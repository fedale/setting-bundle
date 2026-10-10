<?php

namespace Fedale\SettingBundle\Tests\Scoped\Store;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\Store\CachingScopedStore;
use Fedale\SettingBundle\Tests\Fixtures\InMemoryScopedStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CachingScopedStore::class)]
final class CachingScopedStoreTest extends TestCase
{
    private InMemoryScopedStore $inner;

    private CachingScopedStore $store;

    private ScopeChain $chain;

    protected function setUp(): void
    {
        $this->inner = new InMemoryScopedStore(['test.ui' => [
            0 => ['global' => ['renderer' => 'table']],
            5 => ['grid:users' => ['renderer' => 'cards']],
        ]]);
        $this->store = new CachingScopedStore($this->inner, new ArrayAdapter());
        $this->chain = ScopeChain::of(new Scope('grid', 'users'), Scope::global());
    }

    public function testSecondLoadIsServedFromCacheIncludingEmptyBags(): void
    {
        $expected = [
            5 => ['grid:users' => ['renderer' => 'cards']],
            0 => ['global' => ['renderer' => 'table']],
        ];

        self::assertEquals($expected, $this->store->load('test.ui', $this->chain, [5, 0]));
        self::assertEquals($expected, $this->store->load('test.ui', $this->chain, [5, 0]));

        // (5, global) and (0, grid:users) are empty but cached as well.
        self::assertCount(1, $this->inner->loads);
    }

    public function testOnlyMissingPairsAreLoaded(): void
    {
        $this->store->load('test.ui', ScopeChain::of(Scope::global()), [5, 0]);
        $this->store->load('test.ui', $this->chain, [5, 0]);

        self::assertCount(2, $this->inner->loads);
        self::assertSame(['grid:users'], $this->inner->loads[1]['scopes']);
    }

    public function testSaveInvalidatesOnlyItsOwnBag(): void
    {
        $this->store->load('test.ui', $this->chain, [5, 0]);

        $this->store->save('test.ui', new Scope('grid', 'users'), 5, ['renderer' => 'list']);
        $result = $this->store->load('test.ui', $this->chain, [5, 0]);

        self::assertSame('list', $result[5]['grid:users']['renderer']);
        self::assertCount(2, $this->inner->loads);
        self::assertSame(['grid:users'], $this->inner->loads[1]['scopes']);
        self::assertSame([5], $this->inner->loads[1]['tenants']);
    }

    public function testIdsWithReservedCharactersAreCacheable(): void
    {
        $scope = new Scope('resource', 'room {1}/a:b@c');
        $this->store->save('test.ui', $scope, 5, ['renderer' => 'cards']);

        self::assertSame(
            [5 => ['resource:room {1}/a:b@c' => ['renderer' => 'cards']]],
            $this->store->load('test.ui', ScopeChain::of($scope), [5]),
        );
    }
}
