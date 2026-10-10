<?php

namespace Fedale\SettingBundle\Tests\Scoped;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scope::class)]
#[CoversClass(ScopeChain::class)]
final class ScopeTest extends TestCase
{
    public function testStringForm(): void
    {
        self::assertSame('global', (string) Scope::global());
        self::assertSame('grid:users', (string) new Scope('grid', 'users'));
        self::assertSame('eventType:a:b', (string) new Scope('eventType', 'a:b'));
    }

    public function testGlobalAndEquality(): void
    {
        self::assertTrue(Scope::global()->isGlobal());
        self::assertFalse((new Scope('grid', 'users'))->isGlobal());
        self::assertTrue((new Scope('grid', 'users'))->equals(new Scope('grid', 'users')));
        self::assertFalse((new Scope('grid', 'users'))->equals(new Scope('grid', 'orders')));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'empty level' => ['', null];
        yield 'colon in level' => ['grid:x', null];
        yield 'empty id' => ['grid', ''];
        yield 'id too long' => ['grid', str_repeat('x', Scope::MAX_ID_LENGTH + 1)];
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidScopesAreRejected(string $level, ?string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scope($level, $id);
    }

    public function testChainRejectsEmptyAndDuplicates(): void
    {
        try {
            new ScopeChain([]);
            self::fail('an empty chain must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);

        ScopeChain::of(new Scope('grid', 'users'), Scope::global(), new Scope('grid', 'users'));
    }
}
