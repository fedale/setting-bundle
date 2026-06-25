<?php

namespace Fedale\SettingBundle\Tests\Fixtures;

use Fedale\SettingBundle\Contract\SettingStorageInterface;

/**
 * Storage in memoria per i test del SettingsManager: emula il bridge senza DB.
 * Conta le chiamate a loadTenant() per verificare l'effetto della cache.
 */
final class InMemorySettingStorage implements SettingStorageInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $data;

    /** @var array<int, int> */
    public array $loadCalls = [];

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function loadTenant(int $tenantId): array
    {
        $this->loadCalls[$tenantId] = ($this->loadCalls[$tenantId] ?? 0) + 1;

        return $this->data[$tenantId] ?? [];
    }

    public function upsert(
        int $tenantId,
        string $name,
        mixed $value,
        string $type,
        bool $active,
    ): void {
        if ($active) {
            $this->data[$tenantId][$name] = $value;
        } else {
            unset($this->data[$tenantId][$name]);
        }
    }
}
