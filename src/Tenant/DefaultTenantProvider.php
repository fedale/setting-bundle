<?php

namespace Fedale\SettingBundle\Tenant;

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;

/**
 * Fallback usato quando l'app non registra un proprio TenantProvider:
 * il tenant corrente e' sempre quello globale (0). Le app multi-tenant
 * sostituiscono questo servizio aliasando TenantProviderInterface.
 */
final class DefaultTenantProvider implements TenantProviderInterface
{
    public function getCurrentTenantId(): int
    {
        return SettingsManager::GLOBAL_TENANT;
    }
}
