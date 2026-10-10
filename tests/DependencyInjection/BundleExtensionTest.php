<?php

namespace Fedale\SettingBundle\Tests\DependencyInjection;

use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Repository\ScopedSettingRepository;
use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Fedale\SettingBundle\Contract\SettingStorageInterface;
use Fedale\SettingBundle\Contract\SettingValidatorInterface;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\FedaleSettingBundle;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;
use Fedale\SettingBundle\Scoped\SettingDefinitionInterface;
use Fedale\SettingBundle\Scoped\Store\CachingScopedStore;
use Fedale\SettingBundle\Scoped\Store\NullScopedStore;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;
use Fedale\SettingBundle\Setting\Validation\ConstraintsSettingValidator;
use Fedale\SettingBundle\Setting\Validation\NullSettingValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(FedaleSettingBundle::class)]
final class BundleExtensionTest extends TestCase
{
    public function testDoctrineIsTheDefaultStorage(): void
    {
        $container = $this->load([]);

        self::assertSame(
            SettingRepository::class,
            (string) $container->getAlias(SettingStorageInterface::class),
        );
    }

    public function testCustomProviderReplacesStorageAndSkipsDoctrine(): void
    {
        $container = $this->load(['provider' => 'app.my_storage']);

        self::assertSame(
            'app.my_storage',
            (string) $container->getAlias(SettingStorageInterface::class),
        );
        self::assertFalse($container->hasDefinition(SettingRepository::class));
    }

    public function testManagerIsWiredWithDefaultPoolAndTtl(): void
    {
        $container = $this->load([]);

        $args = $container->getDefinition(SettingsManager::class)->getArguments();

        self::assertSame(SettingStorageInterface::class, (string) $args[0]);
        self::assertSame(TenantProviderInterface::class, (string) $args[1]);
        self::assertSame('cache.app', (string) $args[2], 'pool di default');
        self::assertNull($args[3], 'ttl di default');
        self::assertSame(SettingValidatorInterface::class, (string) $args[4]);
    }

    public function testValidatorDefaultsToNoOp(): void
    {
        $container = $this->load([]);

        self::assertSame(
            NullSettingValidator::class,
            (string) $container->getAlias(SettingValidatorInterface::class),
        );
        self::assertFalse($container->hasDefinition(ConstraintsSettingValidator::class));
    }

    public function testValidationEnabledWiresConstraintsValidator(): void
    {
        $container = $this->load([
            'validation' => [
                'enabled' => true,
                'constraints_provider' => 'app.my_constraints',
            ],
        ]);

        self::assertSame(
            ConstraintsSettingValidator::class,
            (string) $container->getAlias(SettingValidatorInterface::class),
        );

        $args = $container->getDefinition(ConstraintsSettingValidator::class)->getArguments();
        self::assertSame('validator', (string) $args[0]);
        self::assertSame('app.my_constraints', (string) $args[1]);
    }

    public function testValidationEnabledWithoutProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->load(['validation' => ['enabled' => true]]);
    }

    public function testCachePoolAndTtlAreConfigurable(): void
    {
        $container = $this->load([
            'cache' => ['enabled' => true, 'pool' => 'app.my_pool', 'ttl' => 3600],
        ]);

        $args = $container->getDefinition(SettingsManager::class)->getArguments();

        self::assertSame('app.my_pool', (string) $args[2]);
        self::assertSame(3600, $args[3]);
    }

    public function testCacheDisabledUsesArrayPool(): void
    {
        $container = $this->load(['cache' => ['enabled' => false]]);

        $args = $container->getDefinition(SettingsManager::class)->getArguments();

        self::assertSame('fedale_setting.array_pool', (string) $args[2]);
        self::assertTrue($container->hasDefinition('fedale_setting.array_pool'));
    }

    public function testCoreAliasesAreWired(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasAlias(SettingsManagerInterface::class));
        self::assertTrue($container->hasAlias(TenantProviderInterface::class));
    }

    public function testTwigExtensionRegisteredOnlyWhenTwigPresent(): void
    {
        $container = $this->load([]);

        self::assertFalse(
            $container->hasDefinition(\Fedale\SettingBundle\Bridge\Twig\SettingsExtension::class),
            'senza TwigBundle la extension non deve essere registrata',
        );
    }

    public function testScopedSettingsAreOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertSame(NullScopedStore::class, (string) $container->getAlias(ScopedStoreInterface::class));
        self::assertTrue($container->hasDefinition(ScopedSettingsResolver::class));
        self::assertTrue($container->hasDefinition(ScopedSettingsWriter::class));
        self::assertFalse($container->hasDefinition(ScopedSettingRepository::class));
        self::assertArrayHasKey(
            SettingDefinitionInterface::class,
            $container->getAutoconfiguredInstanceof(),
            'definitions are tagged automatically',
        );
    }

    public function testDoctrineScopedStoreIsCached(): void
    {
        $container = $this->load([
            'scoped' => ['store' => 'doctrine'],
            'cache' => ['pool' => 'app.my_pool', 'ttl' => 60],
        ]);

        self::assertSame(CachingScopedStore::class, (string) $container->getAlias(ScopedStoreInterface::class));

        $args = $container->getDefinition(CachingScopedStore::class)->getArguments();
        self::assertSame(ScopedSettingRepository::class, (string) $args[0]);
        self::assertSame('app.my_pool', (string) $args[1]);
        self::assertSame(60, $args[2]);
    }

    public function testCustomScopedStore(): void
    {
        $container = $this->load(['scoped' => ['store' => 'app.my_scoped_store']]);

        $args = $container->getDefinition(CachingScopedStore::class)->getArguments();
        self::assertSame('app.my_scoped_store', (string) $args[0]);
        self::assertFalse($container->hasDefinition(ScopedSettingRepository::class));
    }

    /**
     * @return array<string, mixed> the ORM mappings prepended to the doctrine config
     */
    private function prependedMappings(array $config): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->registerExtension(new class extends \Symfony\Component\DependencyInjection\Extension\Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });
        $container->prependExtensionConfig('fedale_setting', $config);

        $extension = (new FedaleSettingBundle())->getContainerExtension();
        $extension->prepend($container);

        return array_merge(...array_map(
            static fn (array $c): array => $c['orm']['mappings'] ?? [],
            $container->getExtensionConfig('doctrine'),
        ));
    }

    public function testScopedMappingIsRegisteredOnlyForTheDoctrineScopedStore(): void
    {
        self::assertSame(['FedaleSetting'], array_keys($this->prependedMappings([])));
        self::assertSame(
            ['FedaleSetting', 'FedaleSettingScoped'],
            array_keys($this->prependedMappings(['scoped' => ['store' => 'doctrine']])),
        );
        self::assertSame(
            ['FedaleSettingScoped'],
            array_keys($this->prependedMappings(['provider' => 'app.my_storage', 'scoped' => ['store' => 'doctrine']])),
        );
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        (new FedaleSettingBundle())
            ->getContainerExtension()
            ->load([$config], $container);

        return $container;
    }
}
