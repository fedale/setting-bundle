<?php

namespace Fedale\SettingBundle\Tests\Scoped;

use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;
use Fedale\SettingBundle\Scoped\SettingType;
use Fedale\SettingBundle\Tests\Fixtures\FixedTenantProvider;
use Fedale\SettingBundle\Tests\Fixtures\InMemoryScopedStore;
use Fedale\SettingBundle\Tests\Fixtures\TestSettingDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

#[CoversClass(ScopedSettingsWriter::class)]
final class ScopedSettingsWriterTest extends TestCase
{
    private InMemoryScopedStore $store;

    private ScopedSettingsResolver $resolver;

    private ScopedSettingsWriter $writer;

    protected function setUp(): void
    {
        $registry = new SettingDefinitionRegistry([
            new TestSettingDefinition('renderer', SettingType::Enum, levels: ['global', 'grid'], choices: [
                'renderer.table' => 'table',
                'renderer.cards' => 'cards',
            ]),
            new TestSettingDefinition('page_size', SettingType::Int, constraints: [new Assert\Range(min: 5, max: 100)]),
            new TestSettingDefinition('striped', SettingType::Bool),
        ]);
        $tenant = new FixedTenantProvider(5);

        $this->store = new InMemoryScopedStore();
        $this->resolver = new ScopedSettingsResolver($registry, $this->store, $tenant);
        $this->writer = new ScopedSettingsWriter(
            $registry,
            $this->store,
            $tenant,
            $this->resolver,
            Validation::createValidator(),
        );
    }

    public function testSavesStoredFormForTheCurrentTenant(): void
    {
        $this->writer->save('test.ui', new Scope('grid', 'users'), [
            'renderer' => 'cards',
            'page_size' => '50',
            'striped' => 'on',
        ]);

        self::assertSame(
            ['renderer' => 'cards', 'page_size' => '50', 'striped' => '1'],
            $this->store->data['test.ui'][5]['grid:users'],
        );
    }

    public function testExplicitTenant(): void
    {
        $this->writer->save('test.ui', Scope::global(), ['renderer' => 'table'], 0);

        self::assertSame(['renderer' => 'table'], $this->store->data['test.ui'][0]['global']);
    }

    public function testNullOrEmptyClearsTheKeyAndLeavesOthersUntouched(): void
    {
        $this->writer->save('test.ui', Scope::global(), ['renderer' => 'cards', 'page_size' => 10]);
        $this->writer->save('test.ui', Scope::global(), ['renderer' => '']);

        self::assertSame(['page_size' => '10'], $this->store->data['test.ui'][5]['global']);
    }

    public function testSaveRefreshesTheResolver(): void
    {
        $chain = ScopeChain::of(Scope::global());
        self::assertNull($this->resolver->get('test.ui', 'renderer', $chain));

        $this->writer->save('test.ui', Scope::global(), ['renderer' => 'cards']);

        self::assertSame('cards', $this->resolver->get('test.ui', 'renderer', $chain)?->value);
    }

    public function testUnknownKey(): void
    {
        $this->assertRejected(Scope::global(), ['nope' => 'x'], 'nope', 'Unknown setting');
    }

    public function testScopeNotSupported(): void
    {
        $this->assertRejected(new Scope('column', 'name'), ['renderer' => 'cards'], 'renderer', 'cannot be set at scope "column:name"');
    }

    public function testClearingIsAllowedAtAnUnsupportedScope(): void
    {
        $this->store->data['test.ui'][5]['column:name'] = ['renderer' => 'cards'];

        $this->writer->save('test.ui', new Scope('column', 'name'), ['renderer' => null]);

        self::assertSame([], $this->store->data['test.ui'][5]['column:name']);
    }

    public function testWrongType(): void
    {
        $this->assertRejected(Scope::global(), ['page_size' => 'many'], 'page_size', 'not a valid int');
    }

    public function testNotAChoice(): void
    {
        $this->assertRejected(Scope::global(), ['renderer' => 'kanban'], 'renderer', 'not one of the allowed choices');
    }

    public function testConstraints(): void
    {
        $this->assertRejected(Scope::global(), ['page_size' => 1000], 'page_size', 'between 5 and 100');
    }

    public function testConstraintsAreSkippedWithoutValidator(): void
    {
        $registry = new SettingDefinitionRegistry([
            new TestSettingDefinition('page_size', SettingType::Int, constraints: [new Assert\Range(max: 100)]),
        ]);
        $tenant = new FixedTenantProvider(5);
        $writer = new ScopedSettingsWriter(
            $registry,
            $this->store,
            $tenant,
            new ScopedSettingsResolver($registry, $this->store, $tenant),
        );

        $writer->save('test.ui', Scope::global(), ['page_size' => 1000]);

        self::assertSame(['page_size' => '1000'], $this->store->data['test.ui'][5]['global']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function assertRejected(Scope $scope, array $values, string $key, string $message): void
    {
        // A valid key passed alongside must not be saved either.
        $values = ['striped' => true] + $values;

        try {
            $this->writer->save('test.ui', $scope, $values);
            self::fail('the value must be rejected');
        } catch (SettingValidationException $e) {
            self::assertSame($key, $e->getKey());
            self::assertStringContainsString($message, implode(' ', $e->getViolationMessages()));
        }

        self::assertSame([], $this->store->data);
    }
}
