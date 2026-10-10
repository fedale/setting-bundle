<?php

namespace Fedale\SettingBundle\Tests\Bridge\Doctrine\Scoped;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Entity\ScopedSetting;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Repository\ScopedSettingRepository;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopedSettingRepository::class)]
#[CoversClass(ScopedSetting::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class ScopedSettingRepositoryTest extends TestCase
{
    private EntityManager $em;

    private ScopedSettingRepository $store;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 4) . '/src/Bridge/Doctrine/Scoped/Entity'],
            true,
        );
        if (method_exists($config, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $this->em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config),
            $config,
        );
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(ScopedSetting::class)]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $this->store = new ScopedSettingRepository($registry);
    }

    public function testLoadsOnlyTheScopesAndTenantsAsked(): void
    {
        $this->store->save('test.ui', Scope::global(), 0, ['renderer' => 'table', 'page_size' => '25']);
        $this->store->save('test.ui', new Scope('grid', 'users'), 5, ['renderer' => 'cards']);
        $this->store->save('test.ui', new Scope('grid', 'orders'), 5, ['renderer' => 'list']);
        $this->store->save('test.ui', Scope::global(), 7, ['renderer' => 'list']);
        $this->store->save('test.other', Scope::global(), 0, ['renderer' => 'other']);

        $result = $this->store->load('test.ui', ScopeChain::of(new Scope('grid', 'users'), Scope::global()), [5, 0]);

        self::assertEquals([
            5 => ['grid:users' => ['renderer' => 'cards']],
            0 => ['global' => ['renderer' => 'table', 'page_size' => '25']],
        ], $result);
    }

    public function testSaveUpdatesAndClearsSingleKeys(): void
    {
        $scope = new Scope('grid', 'users');
        $this->store->save('test.ui', $scope, 5, ['renderer' => 'cards', 'page_size' => '50']);
        $this->store->save('test.ui', $scope, 5, ['renderer' => 'list', 'page_size' => null, 'striped' => '1']);

        self::assertEquals(
            [5 => ['grid:users' => ['renderer' => 'list', 'striped' => '1']]],
            $this->store->load('test.ui', ScopeChain::of($scope), [5]),
        );
        self::assertSame(2, $this->store->count([]));
    }

    public function testGlobalScopeIsStoredWithEmptyIdAndStaysUnique(): void
    {
        $this->store->save('test.ui', Scope::global(), 0, ['renderer' => 'table']);
        $this->store->save('test.ui', Scope::global(), 0, ['renderer' => 'cards']);

        $rows = $this->store->findAll();
        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]->getScopeId());
        self::assertSame('cards', $rows[0]->getValue());
    }

    public function testIdsWithDotsAndColonsRoundTrip(): void
    {
        $scope = new Scope('resource', 'room.1:a');
        $this->store->save('test.ui', $scope, 5, ['renderer' => 'cards']);

        self::assertEquals(
            [5 => ['resource:room.1:a' => ['renderer' => 'cards']]],
            $this->store->load('test.ui', ScopeChain::of($scope, new Scope('resource', 'room')), [5]),
        );
    }
}
