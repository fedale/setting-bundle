<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * Sensible defaults for a setting definition: settable at every scope, free
 * input, no help text, no constraints, always applicable. A subclass declares
 * namespace(), key(), type() and label(), and overrides the rest as needed.
 */
abstract class AbstractSettingDefinition implements SettingDefinitionInterface
{
    public function help(): ?string
    {
        return null;
    }

    public function supportsScope(Scope $scope): bool
    {
        return true;
    }

    public function choices(?Scope $scope): ?array
    {
        return null;
    }

    public function constraints(): array
    {
        return [];
    }

    public function isApplicable(mixed $value, mixed $context): bool
    {
        return true;
    }
}
