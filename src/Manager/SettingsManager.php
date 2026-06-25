<?php

namespace Fedale\SettingBundle\Manager;

use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Fedale\SettingBundle\Contract\SettingStorageInterface;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Implementazione dei settings con fallback per-tenant e cache a layer.
 *
 * I layer (tenant globale 0 e tenant richiesto) sono cachati separatamente
 * sotto "settings.{tenantId}" e fusi in memoria a ogni lettura: cosi una
 * modifica al tenant 0 si propaga a tutti i tenant invalidando una sola voce.
 */
final class SettingsManager implements SettingsManagerInterface
{
    /** Tenant dei default globali. */
    public const GLOBAL_TENANT = 0;

    private const CACHE_PREFIX = 'settings.';

    /**
     * Memo per-request delle mappe gia' fuse, indicizzato per tenant.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $runtime = [];

    public function __construct(
        private readonly SettingStorageInterface $storage,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly CacheItemPoolInterface $cache,
        private readonly ?int $ttl = null,
    ) {
    }

    public function get(
        string $key,
        mixed $default = null,
        ?int $tenantId = null,
    ): mixed {
        $merged = $this->resolveMerged(
            $tenantId ?? $this->tenantProvider->getCurrentTenantId()
        );

        // array_key_exists, non isset: un valore null/false/0 salvato e' valido
        // e non deve essere scambiato col default.
        return array_key_exists($key, $merged) ? $merged[$key] : $default;
    }

    public function set(
        string $key,
        mixed $value,
        ?int $tenantId = null,
        string $type = 'string',
    ): void {
        $tenantId ??= $this->tenantProvider->getCurrentTenantId();

        $this->storage->upsert($tenantId, $key, $value, $type, true);

        $this->cache->deleteItem(self::CACHE_PREFIX . $tenantId);

        // Modificando il layer globale, ogni mappa fusa in questo request e'
        // stale (tutte includono il tenant 0); altrimenti basta il tenant tocco.
        if (self::GLOBAL_TENANT === $tenantId) {
            $this->runtime = [];
        } else {
            unset($this->runtime[$tenantId]);
        }
    }

    /**
     * Default globali sovrascritti dagli override del tenant.
     *
     * @return array<string, mixed>
     */
    private function resolveMerged(int $tenantId): array
    {
        if (isset($this->runtime[$tenantId])) {
            return $this->runtime[$tenantId];
        }

        $global = $this->loadLayer(self::GLOBAL_TENANT);
        $layer = self::GLOBAL_TENANT === $tenantId
            ? []
            : $this->loadLayer($tenantId);

        return $this->runtime[$tenantId] = [...$global, ...$layer];
    }

    /**
     * Settings grezzi di un singolo tenant, dalla cache o dallo storage.
     *
     * @return array<string, mixed>
     */
    private function loadLayer(int $tenantId): array
    {
        $item = $this->cache->getItem(self::CACHE_PREFIX . $tenantId);

        if ($item->isHit()) {
            return $item->get();
        }

        $data = $this->storage->loadTenant($tenantId);

        $item->set($data);

        if (null !== $this->ttl) {
            $item->expiresAfter($this->ttl);
        }

        $this->cache->save($item);

        return $data;
    }
}
