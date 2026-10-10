<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * One step of a scope chain: a level (e.g. 'global', 'grid', 'resource') and,
 * for every level but the global one, the id of the object it targets.
 */
final readonly class Scope implements \Stringable
{
    public const GLOBAL_LEVEL = 'global';

    private const LEVEL_PATTERN = '/^[A-Za-z0-9_.]{1,50}$/';

    public const MAX_ID_LENGTH = 191;

    public function __construct(
        public string $level,
        public ?string $id = null,
    ) {
        if (1 !== preg_match(self::LEVEL_PATTERN, $level)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid scope level "%s": use 1-50 characters among letters, digits, "_" and ".".',
                $level,
            ));
        }

        // '' is how the storage spells "no id", so it cannot be a real id.
        if (null !== $id && ('' === $id || mb_strlen($id) > self::MAX_ID_LENGTH)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid id for scope level "%s": it must be a non-empty string of at most %d characters.',
                $level,
                self::MAX_ID_LENGTH,
            ));
        }
    }

    public static function global(): self
    {
        return new self(self::GLOBAL_LEVEL);
    }

    public function isGlobal(): bool
    {
        return self::GLOBAL_LEVEL === $this->level && null === $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->level === $other->level && $this->id === $other->id;
    }

    /** 'grid:users', 'global': for logs, UI and messages, and as an array key. */
    public function __toString(): string
    {
        return null === $this->id ? $this->level : $this->level . ':' . $this->id;
    }
}
