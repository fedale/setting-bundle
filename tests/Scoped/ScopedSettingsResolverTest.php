<?php

namespace Fedale\SettingBundle\Tests\Scoped;

use Fedale\SettingBundle\Scoped\ResolvedValue;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;
use Fedale\SettingBundle\Scoped\SettingType;
use Fedale\SettingBundle\Scoped\Store\NullScopedStore;
use Fedale\SettingBundle\Tests\Fixtures\FixedTenantProvider;
use Fedale\SettingBundle\Tests\Fixtures\InMemoryScopedStore;
use Fedale\SettingBundle\Tests\Fixtures\TestSettingDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(ScopedSettingsResolver::class)]
#[CoversClass(ResolvedValue::class)]
final class ScopedSettingsResolverTest extends TestCase
{
    /** The example of the plan: renderer of gridview, two tenants with own values. */
    private const RENDERER_ROWS = [
        'test.ui' => [
            0 => [
                'global' => ['renderer' => 'table'],      // A
                'grid:users' => ['renderer' => 'list'],   // B
            ],
            5 => ['global' => ['renderer' => 'cards']],   // C
            7 => ['grid:orders' => ['renderer' => 'cards']], // D
        ],
    ];

    /**
     * @return iterable<string, array{int, string, string, string, int}>
     */
    public static function tenantFirst(): iterable
    {
        yield 'tenant global beats platform per-grid' => [5, 'users', 'cards', 'global', 5];
        yield 'platform per-grid when tenant has none' => [7, 'users', 'list', 'grid:users', 0];
        yield 'tenant per-grid' => [7, 'orders', 'cards', 'grid:orders', 7];
        yield 'platform global as last resort' => [9, 'orders', 'table', 'global', 0];
        yield 'tenant 0 itself' => [0, 'users', 'list', 'grid:users', 0];
    }

    #[DataProvider('tenantFirst')]
    public function testTenantChainIsWalkedBeforeTenantZero(
        int $tenant,
        string $grid,
        string $value,
        string $scope,
        int $fromTenant,
    ): void {
        $resolver = $this->resolver(new InMemoryScopedStore(self::RENDERER_ROWS), $tenant);

        $resolved = $resolver->get('test.ui', 'renderer', $this->gridChain($grid));

        self::assertNotNull($resolved);
        self::assertSame($value, $resolved->value);
        self::assertSame($scope, (string) $resolved->scope);
        self::assertSame($fromTenant, $resolved->tenantId);
    }

    public function testNotApplicableValueFallsBackToTheNextStep(): void
    {
        $definition = new TestSettingDefinition(
            'renderer',
            applicable: static fn (mixed $value, mixed $context): bool => \in_array($value, $context, true),
        );
        $resolver = $this->resolver(new InMemoryScopedStore(self::RENDERER_ROWS), 5, [$definition]);

        // The users grid of tenant 5 cannot render cards: C is skipped, B wins.
        $resolved = $resolver->get('test.ui', 'renderer', $this->gridChain('users'), ['table', 'list']);

        self::assertSame('list', $resolved?->value);
        self::assertSame(0, $resolved->tenantId);
    }

    public function testNoValueAnywhereLeavesTheSettingOut(): void
    {
        $resolver = $this->resolver(new InMemoryScopedStore(), 5, [
            new TestSettingDefinition('renderer'),
            new TestSettingDefinition('page_size', SettingType::Int),
        ]);

        self::assertSame([], $resolver->resolve('test.ui', $this->gridChain('users')));
        self::assertNull($resolver->get('test.ui', 'page_size', $this->gridChain('users')));
    }

    public function testResolveReturnsEveryResolvedSettingInNativeType(): void
    {
        $store = new InMemoryScopedStore(['test.ui' => [
            0 => ['global' => ['page_size' => '25', 'striped' => '1']],
            5 => ['grid:users' => ['page_size' => '50']],
        ]]);
        $resolver = $this->resolver($store, 5, [
            new TestSettingDefinition('page_size', SettingType::Int),
            new TestSettingDefinition('striped', SettingType::Bool),
            new TestSettingDefinition('renderer'),
        ]);

        $resolved = $resolver->resolve('test.ui', $this->gridChain('users'));

        self::assertSame(['page_size', 'striped'], array_keys($resolved));
        self::assertSame(50, $resolved['page_size']->value);
        self::assertTrue($resolved['striped']->value);
    }

    public function testInvalidStoredValuesAreSkippedAndLogged(): void
    {
        $store = new InMemoryScopedStore(['test.ui' => [
            0 => ['global' => ['page_size' => '25', 'renderer' => 'table']],
            5 => [
                'global' => ['renderer' => 'gone'],        // choice no longer offered
                'grid:users' => ['page_size' => 'many'],   // wrong type
                'column:name' => ['renderer' => 'cards'],  // level not supported
            ],
        ]]);
        $logger = $this->logger();
        $resolver = $this->resolver($store, 5, [
            new TestSettingDefinition('page_size', SettingType::Int),
            new TestSettingDefinition('renderer', SettingType::Enum, levels: ['global', 'grid'], choices: [
                'renderer.table' => 'table',
                'renderer.cards' => 'cards',
            ]),
        ], $logger);

        $chain = ScopeChain::of(new Scope('column', 'name'), new Scope('grid', 'users'), Scope::global());
        $resolved = $resolver->resolve('test.ui', $chain);

        self::assertSame(25, $resolved['page_size']->value);
        self::assertSame('table', $resolved['renderer']->value);
        self::assertCount(3, $logger->records);
    }

    public function testEnumComesBackInTheChoiceType(): void
    {
        $store = new InMemoryScopedStore(['test.ui' => [0 => ['global' => ['size' => '50']]]]);
        $resolver = $this->resolver($store, 0, [
            new TestSettingDefinition('size', SettingType::Enum, choices: ['size.small' => 10, 'size.large' => 50]),
        ]);

        self::assertSame(50, $resolver->get('test.ui', 'size', ScopeChain::of(Scope::global()))?->value);
    }

    public function testExplicitTenantOverridesTheProvider(): void
    {
        $resolver = $this->resolver(new InMemoryScopedStore(self::RENDERER_ROWS), 7);

        self::assertSame('cards', $resolver->get('test.ui', 'renderer', $this->gridChain('users'), null, 5)?->value);
    }

    public function testStoreIsReadOncePerRequestUntilReset(): void
    {
        $store = new InMemoryScopedStore(self::RENDERER_ROWS);
        $resolver = $this->resolver($store, 5);

        $resolver->resolve('test.ui', $this->gridChain('users'));
        $resolver->get('test.ui', 'renderer', $this->gridChain('users'));
        self::assertCount(1, $store->loads);
        self::assertSame([5, 0], $store->loads[0]['tenants']);

        // Only the scope not seen yet is loaded.
        $resolver->resolve('test.ui', $this->gridChain('orders'));
        self::assertCount(2, $store->loads);
        self::assertSame(['grid:orders'], $store->loads[1]['scopes']);

        $resolver->reset();
        $resolver->resolve('test.ui', $this->gridChain('users'));
        self::assertCount(3, $store->loads);
    }

    public function testValuesReadsOneScopeWithoutInheritance(): void
    {
        $resolver = $this->resolver(new InMemoryScopedStore(self::RENDERER_ROWS), 5);

        self::assertSame([], $resolver->values('test.ui', new Scope('grid', 'users')));
        self::assertSame(['renderer' => 'cards'], $resolver->values('test.ui', Scope::global()));
        self::assertSame(['renderer' => 'list'], $resolver->values('test.ui', new Scope('grid', 'users'), 0));
    }

    public function testInheritedSkipsOnlyTheEditedStep(): void
    {
        $resolver = $this->resolver(new InMemoryScopedStore(self::RENDERER_ROWS), 5);

        // Tenant 5 editing its global scope: without C, the platform global (A) applies.
        $global = $resolver->inherited('test.ui', ScopeChain::of(Scope::global()));
        self::assertSame('table', $global['renderer']->value);
        self::assertSame(0, $global['renderer']->tenantId);

        // Tenant 5 editing the users grid: it has no own value there, so the
        // hint is its effective value, its own global (C).
        $users = $resolver->inherited('test.ui', $this->gridChain('users'));
        self::assertSame('cards', $users['renderer']->value);
        self::assertSame(5, $users['renderer']->tenantId);

        // Tenant 0 editing its global scope: nothing below it.
        self::assertSame([], $resolver->inherited('test.ui', ScopeChain::of(Scope::global()), null, 0));
    }

    public function testUnknownSettingIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver(new InMemoryScopedStore(), 0)->get('test.ui', 'nope', ScopeChain::of(Scope::global()));
    }

    public function testNullStoreMeansDisabled(): void
    {
        $registry = new SettingDefinitionRegistry([]);

        self::assertFalse((new ScopedSettingsResolver($registry, new NullScopedStore(), new FixedTenantProvider()))->isEnabled());
        self::assertTrue((new ScopedSettingsResolver($registry, new InMemoryScopedStore(), new FixedTenantProvider()))->isEnabled());
    }

    /**
     * @param list<TestSettingDefinition>|null $definitions
     */
    private function resolver(
        InMemoryScopedStore $store,
        int $tenant,
        ?array $definitions = null,
        ?AbstractLogger $logger = null,
    ): ScopedSettingsResolver {
        return new ScopedSettingsResolver(
            new SettingDefinitionRegistry($definitions ?? [new TestSettingDefinition('renderer')]),
            $store,
            new FixedTenantProvider($tenant),
            $logger,
        );
    }

    private function gridChain(string $grid): ScopeChain
    {
        return ScopeChain::of(new Scope('grid', $grid), Scope::global());
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
    }
}
