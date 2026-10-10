<?php

namespace Fedale\SettingBundle\Scoped;

use Symfony\Component\Validator\Constraint;

/**
 * Declares one scoped setting: what can be set, where, and which values are
 * accepted. A consumer bundle adds a setting by implementing this interface (or
 * extending AbstractSettingDefinition); services implementing it are
 * autoconfigured with the `fedale_setting.definition` tag.
 *
 * The default value is deliberately not part of the definition: when no step of
 * the chain holds a value, the resolver answers "no value" and the consumer
 * falls back to its own configuration in code.
 */
interface SettingDefinitionInterface
{
    /** Group the setting belongs to, e.g. 'gridview.ui', 'booking.policy'. */
    public function namespace(): string;

    /** Stable key, unique within the namespace. */
    public function key(): string;

    public function type(): SettingType;

    /** Translation key; the domain is chosen by the consumer. */
    public function label(): string;

    /** Optional translation key; the domain is chosen by the consumer. */
    public function help(): ?string;

    /** Whether the setting can be set at this scope. */
    public function supportsScope(Scope $scope): bool;

    /**
     * The values that can be picked at $scope, as label key => value; null means
     * free input. Required for SettingType::Enum. A stored value that is no longer
     * among the choices is skipped by the resolver.
     *
     * @return array<string, scalar>|null
     */
    public function choices(?Scope $scope): ?array;

    /**
     * Applied on write when symfony/validator is installed.
     *
     * @return list<Constraint>
     */
    public function constraints(): array;

    /**
     * Final guard at resolve time: false skips the candidate and the lookup moves
     * on to the next step of the chain. $context is opaque to the bundle and
     * supplied by the consumer (e.g. gridview passes the grid's resolved options).
     */
    public function isApplicable(mixed $value, mixed $context): bool;
}
