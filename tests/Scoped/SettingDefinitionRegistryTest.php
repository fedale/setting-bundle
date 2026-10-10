<?php

namespace Fedale\SettingBundle\Tests\Scoped;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;
use Fedale\SettingBundle\Tests\Fixtures\TestSettingDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingDefinitionRegistry::class)]
final class SettingDefinitionRegistryTest extends TestCase
{
    public function testIndexesByNamespaceAndKey(): void
    {
        $renderer = new TestSettingDefinition('renderer', levels: ['global', 'grid']);
        $width = new TestSettingDefinition('width', levels: ['grid']);
        $other = new TestSettingDefinition('renderer', namespace: 'test.other');

        $registry = new SettingDefinitionRegistry([$renderer, $width, $other]);

        self::assertSame($renderer, $registry->get('test.ui', 'renderer'));
        self::assertSame($other, $registry->get('test.other', 'renderer'));
        self::assertNull($registry->get('test.ui', 'nope'));
        self::assertTrue($registry->has('test.ui', 'width'));
        self::assertSame(['renderer' => $renderer, 'width' => $width], $registry->all('test.ui'));
        self::assertSame(['renderer' => $renderer], $registry->forScope('test.ui', Scope::global()));
        self::assertSame([], $registry->all('nope'));
    }

    public function testDuplicateKeyIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        new SettingDefinitionRegistry([new TestSettingDefinition('renderer'), new TestSettingDefinition('renderer')]);
    }

    public function testInvalidNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        new SettingDefinitionRegistry([new TestSettingDefinition('Bad:Key')]);
    }
}
