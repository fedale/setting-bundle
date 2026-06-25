<?php

namespace Fedale\SettingBundle\Setting;

/**
 * Converte i valori dei settings tra la forma nativa (usata dal codice) e la
 * forma stringa persistita su DB. Il "type" del setting e' l'unica fonte di
 * verita' del cast: scalari come stringa leggibile, valori complessi come JSON.
 */
final class ValueCaster
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOL = 'bool';
    public const TYPE_JSON = 'json';

    /**
     * Tipo nativo -> stringa da salvare nello storage.
     */
    public static function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            self::TYPE_BOOL => $value ? '1' : '0',
            self::TYPE_INT => (string) (int) $value,
            self::TYPE_FLOAT => (string) (float) $value,
            self::TYPE_JSON => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    /**
     * Stringa dello storage -> tipo nativo.
     */
    public static function deserialize(string $raw, string $type): mixed
    {
        return match ($type) {
            self::TYPE_BOOL => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            self::TYPE_INT => (int) $raw,
            self::TYPE_FLOAT => (float) $raw,
            self::TYPE_JSON => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            default => $raw,
        };
    }
}
