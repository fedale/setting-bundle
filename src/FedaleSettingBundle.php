<?php

namespace Fedale\SettingBundle;

use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Repository\ScopedSettingRepository;
use Fedale\SettingBundle\Bridge\Twig\SettingsExtension;
use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Fedale\SettingBundle\Contract\SettingStorageInterface;
use Fedale\SettingBundle\Contract\SettingValidatorInterface;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;
use Fedale\SettingBundle\Scoped\SettingDefinitionInterface;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;
use Fedale\SettingBundle\Scoped\Store\CachingScopedStore;
use Fedale\SettingBundle\Scoped\Store\NullScopedStore;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;
use Fedale\SettingBundle\Setting\Validation\ConstraintsSettingValidator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class FedaleSettingBundle extends AbstractBundle
{
    public const DEFINITION_TAG = 'fedale_setting.definition';

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
                // Scoped settings (declared definitions resolved along a scope
                // chain). The store is null = feature off, 'doctrine' = the
                // built-in setting_scoped table, or the id of a service
                // implementing ScopedStoreInterface.
                ->arrayNode('scoped')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('store')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Registra automaticamente il mapping ORM dell'entita Setting quando si usa
     * lo storage Doctrine, cosi l'app consumer non deve aggiungere una voce
     * doctrine.orm.mappings che punta dentro vendor/. Saltato se DoctrineBundle
     * non e' presente o se e' configurato uno storage custom.
     *
     * The ScopedSetting mapping is registered on its own, only when
     * `scoped.store` is 'doctrine': apps without scoped settings get no extra
     * table in their migrations.
     */
    public function prependExtension(
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $mappings = [];

        if ($this->isDoctrineProvider($builder)) {
            $mappings['FedaleSetting'] = [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => dirname(__DIR__) . '/src/Bridge/Doctrine/Entity',
                'prefix' => 'Fedale\SettingBundle\Bridge\Doctrine\Entity',
                'alias' => 'FedaleSetting',
            ];
        }

        if ('doctrine' === $this->rawConfigValue($builder, 'scoped', 'store')) {
            $mappings['FedaleSettingScoped'] = [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => dirname(__DIR__) . '/src/Bridge/Doctrine/Scoped/Entity',
                'prefix' => 'Fedale\SettingBundle\Bridge\Doctrine\Scoped\Entity',
                'alias' => 'FedaleSettingScoped',
            ];
        }

        if ([] === $mappings) {
            return;
        }

        $container->extension('doctrine', [
            'orm' => [
                'mappings' => $mappings,
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
        return 'doctrine' === ($this->rawConfigValue($builder, 'provider') ?? 'doctrine');
    }

    /**
     * The last value set for a config path in the raw (unprocessed) config.
     */
    private function rawConfigValue(ContainerBuilder $builder, string ...$path): mixed
    {
        $value = null;

        foreach ($builder->getExtensionConfig('fedale_setting') as $config) {
            foreach ($path as $segment) {
                if (!\is_array($config) || !\array_key_exists($segment, $config)) {
                    continue 2;
                }
                $config = $config[$segment];
            }
            $value = $config;
        }

        return $value;
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

        $this->loadScoped($config, $container, $builder, $poolId);

        // Funzione Twig `setting()`, solo se TwigBundle e' presente.
        if ($builder->hasExtension('twig')) {
            $container->services()
                ->set(SettingsExtension::class)
                ->args([service(SettingsManagerInterface::class)])
                ->tag('twig.extension');
        }
    }

    /**
     * Scoped settings: the resolver and the writer are always registered, so a
     * consumer can depend on them unconditionally and check isEnabled(); with no
     * store configured they run on the NullScopedStore.
     *
     * @param array<string, mixed> $config
     */
    private function loadScoped(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
        string $poolId,
    ): void {
        $services = $container->services();

        $builder->registerForAutoconfiguration(SettingDefinitionInterface::class)
            ->addTag(self::DEFINITION_TAG);

        $services->set(SettingDefinitionRegistry::class)
            ->args([tagged_iterator(self::DEFINITION_TAG)]);

        $store = $config['scoped']['store'];

        if (null === $store) {
            $services->set(NullScopedStore::class);
            $services->alias(ScopedStoreInterface::class, NullScopedStore::class);
        } else {
            if ('doctrine' === $store) {
                $services->set(ScopedSettingRepository::class)
                    ->args([service('doctrine')]);
                $store = ScopedSettingRepository::class;
            }

            $services->set(CachingScopedStore::class)
                ->args([
                    service($store),
                    service($poolId),
                    $config['cache']['ttl'],
                ]);
            $services->alias(ScopedStoreInterface::class, CachingScopedStore::class);
        }

        $services->set(ScopedSettingsResolver::class)
            ->args([
                service(SettingDefinitionRegistry::class),
                service(ScopedStoreInterface::class),
                service(TenantProviderInterface::class),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->tag('monolog.logger', ['channel' => 'fedale_setting']);

        $services->set(ScopedSettingsWriter::class)
            ->args([
                service(SettingDefinitionRegistry::class),
                service(ScopedStoreInterface::class),
                service(TenantProviderInterface::class),
                service(ScopedSettingsResolver::class),
                service('validator')->nullOnInvalid(),
            ]);
    }
}
