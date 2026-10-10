<?php

namespace Fedale\SettingBundle\Tests\Fixtures;

use Fedale\SettingBundle\Scoped\AbstractSettingDefinition;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\SettingType;

/**
 * Setting definition configured through its constructor, for tests.
 */
final class TestSettingDefinition extends AbstractSettingDefinition
{
    /**
     * @param list<string>|null                    $levels      supported scope levels; null = all
     * @param array<string, scalar>|null           $choices
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     * @param (\Closure(mixed, mixed): bool)|null $applicable
     */
    public function __construct(
        private readonly string $key,
        private readonly SettingType $type = SettingType::String,
        private readonly string $namespace = 'test.ui',
        private readonly ?array $levels = null,
        private readonly ?array $choices = null,
        private readonly array $constraints = [],
        private readonly ?\Closure $applicable = null,
    ) {
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): SettingType
    {
        return $this->type;
    }

    public function label(): string
    {
        return 'setting.' . $this->key;
    }

    public function supportsScope(Scope $scope): bool
    {
        return null === $this->levels || \in_array($scope->level, $this->levels, true);
    }

    public function choices(?Scope $scope): ?array
    {
        return $this->choices;
    }

    public function constraints(): array
    {
        return $this->constraints;
    }

    public function isApplicable(mixed $value, mixed $context): bool
    {
        return null === $this->applicable || ($this->applicable)($value, $context);
    }
}
