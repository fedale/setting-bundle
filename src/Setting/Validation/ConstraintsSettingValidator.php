<?php

namespace Fedale\SettingBundle\Setting\Validation;

use Fedale\SettingBundle\Contract\SettingConstraintsProviderInterface;
use Fedale\SettingBundle\Contract\SettingValidatorInterface;
use Fedale\SettingBundle\Exception\SettingValidationException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates a value against the constraints declared for its key, using the
 * Symfony Validator component. Keys without declared constraints pass through
 * untouched, so the free-form behaviour is preserved per-key.
 */
final class ConstraintsSettingValidator implements SettingValidatorInterface
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly SettingConstraintsProviderInterface $constraints,
    ) {
    }

    public function validate(string $key, mixed $value, string $type): void
    {
        $constraints = $this->constraints->constraintsFor($key);

        if ([] === $constraints) {
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
