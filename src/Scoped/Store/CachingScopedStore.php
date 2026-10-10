<?php

namespace Fedale\SettingBundle\Scoped\Store;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6 cache in front of any scoped store, one item per (tenant, namespace,
 * scope). A lookup reads all its items in one getItems() call and loads only the
 * missing pairs from the inner store; empty bags are cached too, otherwise every
 * scope without values would hit the store on each request. A save invalidates
 * the single item it touches.
 */
final class CachingScopedStore implements ScopedStoreInterface
{
    private const CACHE_PREFIX = 'settings_scoped.';

    public function __construct(
        private readonly ScopedStoreInterface $inner,
        private readonly CacheItemPoolInterface $cache,
        private readonly ?int $ttl = null,
    ) {
    }

    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array
    {
        /** @var array<string, array{int, Scope}> $pairs cache key => [tenant, scope] */
        $pairs = [];
        foreach ($tenantIds as $tenantId) {
            foreach ($chain->scopes as $scope) {
                $pairs[self::cacheKey($namespace, $tenantId, $scope)] = [$tenantId, $scope];
            }
        }

        $result = [];
        $missing = [];
        $missingTenants = [];
        $missingScopes = [];

        foreach ($this->cache->getItems(array_keys($pairs)) as $cacheKey => $item) {
            [$tenantId, $scope] = $pairs[$cacheKey];

            if ($item->isHit()) {
                if ([] !== $bag = $item->get()) {
                    $result[$tenantId][(string) $scope] = $bag;
                }
                continue;
            }

            $missing[$cacheKey] = $item;
            $missingTenants[$tenantId] = $tenantId;
            $missingScopes[(string) $scope] = $scope;
        }

        if ([] === $missing) {
            return $result;
        }

        // One query for every missing pair; it may fetch a few pairs that were
        // already cached (tenants x scopes), which is harmless.
        $loaded = $this->inner->load(
            $namespace,
            new ScopeChain(array_values($missingScopes)),
            array_values($missingTenants),
        );

        foreach ($missing as $cacheKey => $item) {
            [$tenantId, $scope] = $pairs[$cacheKey];
            $bag = $loaded[$tenantId][(string) $scope] ?? [];

            $item->set($bag);
            if (null !== $this->ttl) {
                $item->expiresAfter($this->ttl);
            }
            $this->cache->saveDeferred($item);

            if ([] !== $bag) {
                $result[$tenantId][(string) $scope] = $bag;
            }
        }

        $this->cache->commit();

        return $result;
    }

    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void
    {
        $this->inner->save($namespace, $scope, $tenantId, $values);

        $this->cache->deleteItem(self::cacheKey($namespace, $tenantId, $scope));
    }

    /**
     * Hashed, since PSR-6 reserves ':' and other characters an id may contain.
     */
    private static function cacheKey(string $namespace, int $tenantId, Scope $scope): string
    {
        return self::CACHE_PREFIX . hash('xxh128', $tenantId . "\0" . $namespace . "\0" . $scope);
    }
}
