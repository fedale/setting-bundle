<?php

namespace Fedale\SettingBundle\Scoped;

use Fedale\SettingBundle\Setting\ValueCaster;

/**
 * Converts scoped setting values between three forms:
 * - input: what a form, a CLI or code passes in (lenient: '1', 'on', '42');
 * - native: what the code works with (bool, int, float, string);
 * - stored: the string the store persists.
 *
 * Reading is strict: a stored string that does not fit the type is rejected, so
 * a value saved under an older definition is skipped instead of misread.
 */
final class SettingTypeCaster
{
    /**
     * Input value -> native value.
     *
     * @throws \InvalidArgumentException when $value cannot represent $type
     */
    public static function normalize(SettingType $type, mixed $value): mixed
    {
        return match ($type) {
            SettingType::Bool => self::toBool($value),
            SettingType::Int => self::toInt($value),
            SettingType::Float => self::toFloat($value),
            SettingType::String, SettingType::Enum => self::toScalar($value, $type),
        };
    }

    /**
     * Native value -> stored string.
     */
    public static function serialize(SettingType $type, mixed $value): string
    {
        return match ($type) {
            SettingType::Bool => ValueCaster::serialize($value, ValueCaster::TYPE_BOOL),
            SettingType::Int => ValueCaster::serialize($value, ValueCaster::TYPE_INT),
            // var_export keeps a float's full precision and its decimal point.
            SettingType::Float => var_export((float) $value, true),
            SettingType::String, SettingType::Enum => (string) $value,
        };
    }

    /**
     * Stored string -> native value. An Enum comes back as the stored string;
     * matching it against the choices is up to the caller.
     *
     * @throws \InvalidArgumentException when $raw does not fit $type
     */
    public static function deserialize(SettingType $type, string $raw): mixed
    {
        return match ($type) {
            SettingType::Bool => match ($raw) {
                '1' => true,
                '0' => false,
                default => throw self::invalid($type, $raw),
            },
            SettingType::Int => 1 === preg_match('/^-?\d+$/', $raw) ? (int) $raw : throw self::invalid($type, $raw),
            SettingType::Float => is_numeric($raw) ? (float) $raw : throw self::invalid($type, $raw),
            SettingType::String, SettingType::Enum => $raw,
        };
    }

    /**
     * The choice $value stands for, in the choice's own type; $value itself when
     * the setting has no choices (free input).
     *
     * @param array<string, scalar>|null $choices label key => value
     *
     * @throws \InvalidArgumentException when $value is not one of the choices
     */
    public static function matchChoice(?array $choices, mixed $value): mixed
    {
        if (null === $choices) {
            return $value;
        }

        // Compared in string form: a stored '3' must select the int choice 3.
        $needle = self::choiceKey($value);
        foreach ($choices as $choice) {
            if (self::choiceKey($choice) === $needle) {
                return $choice;
            }
        }

        throw new \InvalidArgumentException('The value is not one of the allowed choices.');
    }

    private static function choiceKey(mixed $value): ?string
    {
        return match (true) {
            \is_bool($value) => $value ? '1' : '0',
            \is_scalar($value) => (string) $value,
            default => null,
        };
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return match (\is_string($value) || \is_int($value) ? strtolower((string) $value) : null) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw self::invalid(SettingType::Bool, $value),
        };
    }

    private static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\s*-?\d+\s*$/', $value)) {
            return (int) $value;
        }

        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw self::invalid(SettingType::Int, $value);
    }

    private static function toFloat(mixed $value): float
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric(trim($value)))) {
            return (float) (\is_string($value) ? trim($value) : $value);
        }

        throw self::invalid(SettingType::Float, $value);
    }

    private static function toScalar(mixed $value, SettingType $type): string
    {
        // An Enum is compared with its choices in string form, so 3 and '3' both
        // select the choice 3 (see matchChoice()).
        if (\is_string($value) || \is_int($value) || \is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw self::invalid($type, $value);
    }

    private static function invalid(SettingType $type, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            'This value is not a valid %s: %s.',
            $type->value,
            get_debug_type($value) === 'string' ? '"' . $value . '"' : get_debug_type($value),
        ));
    }
}
