<?php

namespace Fedale\SettingBundle\Contract;

/**
 * Contratto di persistenza dei settings (lettura + scrittura), indipendente
 * dalla sorgente. Il bridge Doctrine ne e' l'implementazione di default, ma
 * l'app puo' fornirne una propria (API, file, Mongo, ...) via configurazione.
 */
interface SettingStorageInterface
{
    /**
     * Tutti i settings ATTIVI del tenant, gia' deserializzati al tipo nativo,
     * indicizzati per nome. Il risultato deve essere serializzabile (finisce
     * in cache).
     *
     * @return array<string, mixed>
     */
    public function loadTenant(int $tenantId): array;

    /**
     * Crea o aggiorna il setting (tenantId, name). $value e' nel tipo nativo;
     * $type guida la serializzazione verso lo storage.
     */
    public function upsert(
        int $tenantId,
        string $name,
        mixed $value,
        string $type,
        bool $active,
    ): void;
}
