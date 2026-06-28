<?php

namespace Fedale\SettingBundle\Exception;

/**
 * Thrown when a setting value does not satisfy the constraints declared for its
 * key. Carries the key and the human-readable violation messages so callers
 * (forms, CLI commands) can surface them without depending on the validation
 * backend (e.g. Symfony Validator).
 */
final class SettingValidationException extends \RuntimeException
{
    /**
     * @param list<string> $violationMessages
     */
    public function __construct(
        private readonly string $key,
        private readonly array $violationMessages,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Invalid value for setting "%s": %s',
                $key,
                implode('; ', $violationMessages),
            ),
            0,
            $previous,
        );
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return list<string>
     */
    public function getViolationMessages(): array
    {
        return $this->violationMessages;
    }
}
