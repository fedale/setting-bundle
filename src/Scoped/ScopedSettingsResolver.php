<?php

namespace Fedale\SettingBundle\Scoped;

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Scoped\Store\NullScopedStore;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves scoped settings along a scope chain, tenant first: the whole chain of
 * the current tenant is walked before the one of tenant 0, which only holds
 * platform defaults and never beats a tenant's own choice.
 *
 *     (N, scope 1) -> (N, scope 2) -> ... -> (0, scope 1) -> (0, scope 2) -> ...
 *
 * The first usable value wins. A candidate is skipped, and the lookup goes on,
 * when its scope is no longer supported or it no longer fits the definition
 * (both logged), or when isApplicable() rejects it for the given context. When
 * nothing is found the setting is left out: the consumer falls back to its own
 * configuration.
 */
class ScopedSettingsResolver implements ResetInterface
{
    /** @var array<string, array<string, string>> per-request bags, "namespace\0tenant\0scope" => key => stored value */
    private array $bags = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SettingDefinitionRegistry $definitions,
        private readonly ScopedStoreInterface $store,
        private readonly TenantProviderInterface $tenantProvider,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** Whether a real store is wired: with the null store the feature is off. */
    public function isEnabled(): bool
    {
        return !$this->store instanceof NullScopedStore;
    }

    /**
     * The effective value of every setting of $namespace that has one.
     *
     * @param mixed    $context  passed to SettingDefinitionInterface::isApplicable()
     * @param int|null $tenantId the current tenant when null
     *
     * @return array<string, ResolvedValue> key => resolved value
     */
    public function resolve(string $namespace, ScopeChain $chain, mixed $context = null, ?int $tenantId = null): array
    {
        $tenants = $this->tenantChain($tenantId);
        $this->load($namespace, $chain, $tenants);

        $resolved = [];
        foreach ($this->definitions->all($namespace) as $key => $definition) {
            if (null !== $value = $this->lookup($namespace, $definition, $chain, $tenants, $context)) {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * The effective value of one setting, or null when no step of the chain has a
     * usable one.
     *
     * @throws \InvalidArgumentException when the setting is not declared
     */
    public function get(
        string $namespace,
        string $key,
        ScopeChain $chain,
        mixed $context = null,
        ?int $tenantId = null,
    ): ?ResolvedValue {
        $definition = $this->definitions->get($namespace, $key)
            ?? throw new \InvalidArgumentException(sprintf('Unknown setting "%s" in namespace "%s".', $key, $namespace));

        $tenants = $this->tenantChain($tenantId);
        $this->load($namespace, $chain, $tenants);

        return $this->lookup($namespace, $definition, $chain, $tenants, $context);
    }

    /**
     * The values stored at exactly one scope of one tenant, without inheritance,
     * e.g. to prefill that scope's form. Values that no longer fit their
     * definition are left out.
     *
     * @return array<string, mixed> key => native value
     */
    public function values(string $namespace, Scope $scope, ?int $tenantId = null): array
    {
        $tenantId ??= $this->tenantProvider->getCurrentTenantId();
        $this->load($namespace, ScopeChain::of($scope), [$tenantId]);

        $values = [];
        foreach ($this->bags[self::bagKey($namespace, $tenantId, $scope)] as $key => $raw) {
            $definition = $this->definitions->get($namespace, $key);

            if (null !== $definition && null !== $value = $this->decode($definition, $scope, $tenantId, $raw)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    public function reset(): void
    {
        $this->bags = [];
    }

    /**
     * @param list<int> $tenants
     */
    private function lookup(
        string $namespace,
        SettingDefinitionInterface $definition,
        ScopeChain $chain,
        array $tenants,
        mixed $context,
    ): ?ResolvedValue {
        foreach ($tenants as $tenantId) {
            foreach ($chain->scopes as $scope) {
                $raw = $this->bags[self::bagKey($namespace, $tenantId, $scope)][$definition->key()] ?? null;

                if (null === $raw) {
                    continue;
                }

                $value = $this->decode($definition, $scope, $tenantId, $raw);

                if (null !== $value && $definition->isApplicable($value, $context)) {
                    return new ResolvedValue($value, $scope, $tenantId);
                }
            }
        }

        return null;
    }

    /**
     * Stored string -> native value, or null (and a log entry) when the value no
     * longer fits its definition: scope not supported, wrong type, choice gone.
     */
    private function decode(SettingDefinitionInterface $definition, Scope $scope, int $tenantId, string $raw): mixed
    {
        try {
            if (!$definition->supportsScope($scope)) {
                throw new \InvalidArgumentException('The setting cannot be set at this scope.');
            }

            return SettingTypeCaster::matchChoice(
                $definition->choices($scope),
                SettingTypeCaster::deserialize($definition->type(), $raw),
            );
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Ignoring stored setting "{namespace}:{key}" at {scope} of tenant {tenant}: {reason}', [
                'namespace' => $definition->namespace(),
                'key' => $definition->key(),
                'scope' => (string) $scope,
                'tenant' => $tenantId,
                'reason' => $e->getMessage(),
                'value' => $raw,
            ]);

            return null;
        }
    }

    /**
     * Fills the per-request bags of every (tenant, scope) pair, loading only the
     * pairs not seen yet in this request.
     *
     * @param list<int> $tenants
     */
    private function load(string $namespace, ScopeChain $chain, array $tenants): void
    {
        $missingTenants = [];
        $missingScopes = [];

        foreach ($tenants as $tenantId) {
            foreach ($chain->scopes as $scope) {
                if (!isset($this->bags[self::bagKey($namespace, $tenantId, $scope)])) {
                    $missingTenants[$tenantId] = $tenantId;
                    $missingScopes[(string) $scope] = $scope;
                }
            }
        }

        if ([] === $missingScopes) {
            return;
        }

        $loaded = $this->store->load($namespace, new ScopeChain(array_values($missingScopes)), array_values($missingTenants));

        foreach ($missingTenants as $tenantId) {
            foreach ($missingScopes as $scope) {
                $this->bags[self::bagKey($namespace, $tenantId, $scope)] ??= $loaded[$tenantId][(string) $scope] ?? [];
            }
        }
    }

    /**
     * @return list<int> the current tenant, then tenant 0 (once, if they coincide)
     */
    private function tenantChain(?int $tenantId): array
    {
        $tenantId ??= $this->tenantProvider->getCurrentTenantId();

        return SettingsManager::GLOBAL_TENANT === $tenantId
            ? [SettingsManager::GLOBAL_TENANT]
            : [$tenantId, SettingsManager::GLOBAL_TENANT];
    }

    private static function bagKey(string $namespace, int $tenantId, Scope $scope): string
    {
        return $namespace . "\0" . $tenantId . "\0" . $scope;
    }
}
