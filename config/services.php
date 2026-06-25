<?php

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Tenant\DefaultTenantProvider;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Risoluzione del tenant corrente: fallback al tenant globale (0).
    // L'app multi-tenant ri-aliasa TenantProviderInterface sul proprio servizio.
    $services->set(DefaultTenantProvider::class);
    $services->alias(TenantProviderInterface::class, DefaultTenantProvider::class);
};
