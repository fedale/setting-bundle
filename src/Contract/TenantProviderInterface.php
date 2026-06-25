<?php

namespace Fedale\SettingBundle\Contract;

/**
 * Risolve il tenant corrente quando il chiamante non lo passa esplicitamente.
 * L'app implementa questo contratto (es. dalla request, dalla sessione, da un
 * token) e ne aliasa il servizio per sostituire il DefaultTenantProvider.
 */
interface TenantProviderInterface
{
    public function getCurrentTenantId(): int;
}
