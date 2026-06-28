<?php

namespace Fedale\SettingBundle\Contract;

use Fedale\SettingBundle\Exception\SettingValidationException;

/**
 * Validates a setting value before it is persisted. Invoked at the single write
 * choke point (SettingsManager::set()), so the invariant holds regardless of the
 * entry point (form, CLI, code).
 *
 * Validation is opt-in and per-key: a key without declared rules must pass
 * untouched, preserving the bundle's free-form behaviour. The default
 * implementation is a no-op; the constraint-based implementation is wired only
 * when the consumer enables it.
 */
interface SettingValidatorInterface
{
    /**
     * @throws SettingValidationException if the value violates the rules of $key
     */
    public function validate(string $key, mixed $value, string $type): void;
}
