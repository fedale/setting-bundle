<?php

namespace Fedale\SettingBundle\Setting\Validation;

use Fedale\SettingBundle\Contract\SettingValidatorInterface;

/**
 * Default validator: accepts every value. Keeps the bundle free-form out of the
 * box, with no dependency on a validation backend. Replaced by
 * ConstraintsSettingValidator when the consumer enables validation.
 */
final class NullSettingValidator implements SettingValidatorInterface
{
    public function validate(string $key, mixed $value, string $type): void
    {
        // no-op: free-form keys, no constraints
    }
}
