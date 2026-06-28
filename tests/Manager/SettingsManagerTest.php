<?php

namespace Fedale\SettingBundle\Tests\Manager;

use Fedale\SettingBundle\Contract\SettingValidatorInterface;
use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Tests\Fixtures\FixedTenantProvider;
use Fedale\SettingBundle\Tests\Fixtures\InMemorySettingStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(SettingsManager::class)]
final class SettingsManagerTest extends TestCase
{
    private function manager(
        InMemorySettingStorage $storage,
        FixedTenantProvider $tenant,
    ): SettingsManager {
        return new SettingsManager($storage, $tenant, new ArrayAdapter());
    }

    public function testTenantOverrideWinsOverGlobalDefault(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['general.title' => 'Gestionale', 'general.theme' => 'classic'],
            12 => ['general.title' => 'Cliente XYZ'],
        ]);

        $manager = $this->manager($storage, new FixedTenantProvider(12));

        self::assertSame('Cliente XYZ', $manager->get('general.title'));
        // ereditato dal tenant globale
        self::assertSame('classic', $manager->get('general.theme'));
    }

    public function testFallsBackToCallerDefaultWhenMissingEverywhere(): void
    {
        $storage = new InMemorySettingStorage([0 => ['a' => 1]]);
        $manager = $this->manager($storage, new FixedTenantProvider(12));

        self::assertSame('fallback', $manager->get('does.not.exist', 'fallback'));
    }

    public function testExplicitTenantArgumentOverridesProvider(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['k' => 'global'],
            7 => ['k' => 'seven'],
        ]);
        $manager = $this->manager($storage, new FixedTenantProvider(12));

        self::assertSame('seven', $manager->get('k', null, 7));
        self::assertSame('global', $manager->get('k', null, 0));
    }

    public function testStoredFalsyValuesAreNotReplacedByDefault(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['flag' => false, 'zero' => 0, 'nothing' => null],
        ]);
        $manager = $this->manager($storage, new FixedTenantProvider(0));

        self::assertFalse($manager->get('flag', true));
        self::assertSame(0, $manager->get('zero', 99));
        self::assertNull($manager->get('nothing', 'x'));
    }

    public function testLayersAreLoadedOncePerRequest(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['a' => 1],
            12 => ['b' => 2],
        ]);
        $manager = $this->manager($storage, new FixedTenantProvider(12));

        $manager->get('a');
        $manager->get('b');
        $manager->get('a');

        self::assertSame(1, $storage->loadCalls[0] ?? 0, 'layer globale caricato una volta');
        self::assertSame(1, $storage->loadCalls[12] ?? 0, 'layer tenant caricato una volta');
    }

    public function testSetPersistsAndInvalidatesTenantLayer(): void
    {
        $storage = new InMemorySettingStorage([12 => ['k' => 'old']]);
        $manager = $this->manager($storage, new FixedTenantProvider(12));

        self::assertSame('old', $manager->get('k'));

        $manager->set('k', 'new');

        self::assertSame('new', $manager->get('k'));
        self::assertSame(2, $storage->loadCalls[12], 'il layer e\' stato ricaricato dopo il set');
    }

    public function testSetOnGlobalTenantPropagatesToAllTenants(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['general.theme' => 'classic'],
            12 => ['general.title' => 'Cliente XYZ'],
        ]);
        $tenant = new FixedTenantProvider(12);
        $manager = $this->manager($storage, $tenant);

        // popola il memo per il tenant 12 (include il layer globale)
        self::assertSame('classic', $manager->get('general.theme'));

        // modifica il default globale
        $manager->set('general.theme', 'dark', 0);

        // il tenant 12 vede il nuovo default senza override proprio
        self::assertSame('dark', $manager->get('general.theme', null, 12));
    }

    public function testSetRejectsInvalidValueAndDoesNotPersist(): void
    {
        $storage = new InMemorySettingStorage([12 => ['k' => 'old']]);
        $validator = new class implements SettingValidatorInterface {
            public function validate(string $key, mixed $value, string $type): void
            {
                throw new SettingValidationException($key, ['too short']);
            }
        };

        $manager = new SettingsManager(
            $storage,
            new FixedTenantProvider(12),
            new ArrayAdapter(),
            null,
            $validator,
        );

        $this->expectException(SettingValidationException::class);

        try {
            $manager->set('k', 'ab');
        } finally {
            // Value untouched: validation runs before storage->upsert().
            self::assertSame('old', $storage->loadTenant(12)['k']);
        }
    }

    public function testNativeTypeIsPreservedThroughGet(): void
    {
        $storage = new InMemorySettingStorage([
            0 => ['n' => 42, 'b' => true, 'list' => ['x', 'y']],
        ]);
        $manager = $this->manager($storage, new FixedTenantProvider(0));

        self::assertSame(42, $manager->get('n'));
        self::assertTrue($manager->get('b'));
        self::assertSame(['x', 'y'], $manager->get('list'));
    }
}
