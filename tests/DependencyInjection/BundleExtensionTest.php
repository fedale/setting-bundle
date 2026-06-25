<?php

namespace Fedale\SettingBundle\Tests\DependencyInjection;

use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;
use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Fedale\SettingBundle\Contract\SettingStorageInterface;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\FedaleSettingBundle;
use Fedale\SettingBundle\Manager\SettingsManager;
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
