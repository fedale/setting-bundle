<?php

namespace Fedale\SettingBundle\Scoped;

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The single write choke point of scoped settings: every value is checked
 * against its definition before it reaches the store, whatever the entry point
 * (form, CLI, code).
 */
final class ScopedSettingsWriter
{
    public function __construct(
        private readonly SettingDefinitionRegistry $definitions,
        private readonly ScopedStoreInterface $store,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ScopedSettingsResolver $resolver,
        private readonly ?ValidatorInterface $validator = null,
    ) {
    }

    /**
     * Sets or clears keys of one scope of one tenant; keys not passed are left
     * untouched. A null or '' value clears the key (back to "inherit").
     *
     * Each value must be a declared setting, settable at $scope, of the right type,
     * one of the choices if any, and pass the definition's constraints when
     * symfony/validator is installed.
     *
     * @param array<string, mixed> $values   key => input value
     * @param int|null             $tenantId the current tenant when null
     *
     * @throws SettingValidationException on the first invalid key; nothing is saved then
     */
    public function save(string $namespace, Scope $scope, array $values, ?int $tenantId = null): void
    {
        $stored = [];
        foreach ($values as $key => $value) {
            $stored[$key] = $this->prepare($namespace, $scope, (string) $key, $value);
        }

        if ([] === $stored) {
            return;
        }

        $this->store->save($namespace, $scope, $tenantId ?? $this->tenantProvider->getCurrentTenantId(), $stored);

        // The per-request bags of the resolver are stale now.
        $this->resolver->reset();
    }

    /**
     * Input value -> stored string, or null to clear the key.
     */
    private function prepare(string $namespace, Scope $scope, string $key, mixed $value): ?string
    {
        $definition = $this->definitions->get($namespace, $key)
            ?? throw new SettingValidationException($key, [sprintf('Unknown setting in namespace "%s".', $namespace)]);

        // Clearing is always allowed, so a value left at a scope the setting no
        // longer supports can still be removed.
        if (null === $value || '' === $value) {
            return null;
        }

        if (!$definition->supportsScope($scope)) {
            throw new SettingValidationException($key, [sprintf('This setting cannot be set at scope "%s".', $scope)]);
        }

        try {
            $value = SettingTypeCaster::matchChoice(
                $definition->choices($scope),
                SettingTypeCaster::normalize($definition->type(), $value),
            );
        } catch (\InvalidArgumentException $e) {
            throw new SettingValidationException($key, [$e->getMessage()], $e);
        }

        $this->validate($key, $value, $definition);

        return SettingTypeCaster::serialize($definition->type(), $value);
    }

    private function validate(string $key, mixed $value, SettingDefinitionInterface $definition): void
    {
        $constraints = $definition->constraints();

        if (null === $this->validator || [] === $constraints) {
            return;
        }

        $violations = $this->validator->validate($value, $constraints);

        if (0 === count($violations)) {
            return;
        }

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        throw new SettingValidationException($key, $messages);
    }
}
