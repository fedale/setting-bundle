<?php

namespace Fedale\SettingBundle\Contract;

/**
 * Punto di accesso ai settings applicativi salvati su DB e modificabili a
 * runtime. Le letture applicano il fallback per-tenant N -> 0 -> default.
 */
interface SettingsManagerInterface
{
    /**
     * Ritorna il valore della chiave per il tenant indicato (o quello corrente
     * se null), gia del tipo nativo. Se la chiave non esiste ne nel tenant ne
     * nel tenant globale (0), ritorna $default.
     */
    public function get(
        string $key,
        mixed $default = null,
        ?int $tenantId = null,
    ): mixed;

    /**
     * Crea o aggiorna la chiave per il tenant indicato (o quello corrente se
     * null). $type guida la serializzazione: string|int|float|bool|json.
     */
    public function set(
        string $key,
        mixed $value,
        ?int $tenantId = null,
        string $type = 'string',
    ): void;
}
