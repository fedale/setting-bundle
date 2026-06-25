<?php

use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Wiring dello storage Doctrine, importato solo quando `provider: doctrine`.
 * Richiede doctrine/orm e doctrine/doctrine-bundle nell'app consumer.
 * L'alias di SettingStorageInterface e' gestito centralmente in
 * FedaleSettingBundle::loadExtension().
 */
return static function (ContainerConfigurator $container): void {

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->set(SettingRepository::class);
};
