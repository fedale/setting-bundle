<?php

namespace Fedale\SettingBundle;

use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;
use Fedale\SettingBundle\Bridge\Twig\SettingsExtension;
use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Fedale\SettingBundle\Contract\SettingStorageInterface;
use Fedale\SettingBundle\Contract\SettingValidatorInterface;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Setting\Validation\ConstraintsSettingValidator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class FedaleSettingBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        // id del pool PSR-6 (default: il pool applicativo)
                        ->scalarNode('pool')->defaultValue('cache.app')->end()
                        // secondi di validita; null = nessuna scadenza
                        ->integerNode('ttl')->defaultNull()->end()
                    ->end()
                ->end()
                // 'doctrine' = storage built-in, oppure l'id di un servizio
                // custom che implementa SettingStorageInterface (API, file, ...).
                ->scalarNode('provider')
                    ->defaultValue('doctrine')
                ->end()
                // Validazione opt-in dei valori per-key. Disabilitata = free-form.
                ->arrayNode('validation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        // id del servizio che implementa
                        // SettingConstraintsProviderInterface (le regole per key).
                        ->scalarNode('constraints_provider')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Registra automaticamente il mapping ORM dell'entita Setting quando si usa
     * lo storage Doctrine, cosi l'app consumer non deve aggiungere una voce
     * doctrine.orm.mappings che punta dentro vendor/. Saltato se DoctrineBundle
     * non e' presente o se e' configurato uno storage custom.
     */
    public function prependExtension(
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        if (!$this->isDoctrineProvider($builder)) {
            return;
        }

        $container->extension('doctrine', [
            'orm' => [
                'mappings' => [
                    'FedaleSetting' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => dirname(__DIR__) . '/src/Bridge/Doctrine/Entity',
                        'prefix' => 'Fedale\SettingBundle\Bridge\Doctrine\Entity',
                        'alias' => 'FedaleSetting',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Lo storage e' Doctrine se non configurato (default) o impostato a
     * 'doctrine'. Legge la config grezza perche' prependExtension gira prima
     * della risoluzione dell'estensione.
     */
    private function isDoctrineProvider(ContainerBuilder $builder): bool
    {
        $provider = 'doctrine';

        foreach ($builder->getExtensionConfig('fedale_setting') as $config) {
            if (isset($config['provider'])) {
                $provider = $config['provider'];
            }
        }

        return 'doctrine' === $provider;
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        $container->import(dirname(__DIR__) . '/config/services.php');

        // Storage concreto (sorgente dei settings).
        if ('doctrine' === $config['provider']) {
            $container->import(dirname(__DIR__) . '/config/doctrine.php');
            $storageId = SettingRepository::class;
        } else {
            $storageId = $config['provider'];
        }

        $container->services()
            ->alias(SettingStorageInterface::class, $storageId);

        // Pool PSR-6: quello configurato, o un ArrayAdapter per-request se la
        // cache e' disabilitata (nessuna persistenza tra richieste).
        if ($config['cache']['enabled']) {
            $poolId = $config['cache']['pool'];
        } else {
            $poolId = 'fedale_setting.array_pool';
            $container->services()->set($poolId, ArrayAdapter::class);
        }

        // Validazione opt-in: se abilitata, l'alias del validatore punta al
        // ConstraintsSettingValidator wired col Validator di Symfony e col
        // provider di regole indicato dal consumer; altrimenti resta il no-op.
        if ($config['validation']['enabled']) {
            $providerId = $config['validation']['constraints_provider'];

            if (null === $providerId) {
                throw new \InvalidArgumentException(
                    'fedale_setting.validation.constraints_provider must be set'
                    . ' (service id implementing SettingConstraintsProviderInterface)'
                    . ' when validation is enabled.'
                );
            }

            $container->services()
                ->set(ConstraintsSettingValidator::class)
                ->args([
                    service('validator'),
                    service($providerId),
                ]);

            $container->services()
                ->alias(SettingValidatorInterface::class, ConstraintsSettingValidator::class);
        }

        $container->services()
            ->set(SettingsManager::class)
            ->args([
                service(SettingStorageInterface::class),
                service(TenantProviderInterface::class),
                service($poolId),
                $config['cache']['ttl'],
                service(SettingValidatorInterface::class),
            ]);

        $container->services()
            ->alias(SettingsManagerInterface::class, SettingsManager::class);

        // Funzione Twig `setting()`, solo se TwigBundle e' presente.
        if ($builder->hasExtension('twig')) {
            $container->services()
                ->set(SettingsExtension::class)
                ->args([service(SettingsManagerInterface::class)])
                ->tag('twig.extension');
        }
    }
}
