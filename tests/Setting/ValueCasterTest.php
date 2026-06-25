<?php

namespace Fedale\SettingBundle\Tests\Setting;

use Fedale\SettingBundle\Setting\ValueCaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueCaster::class)]
final class ValueCasterTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string, string, mixed}>
     */
    public static function roundTripCases(): iterable
    {
        // name => [native, type, expectedRaw, expectedNative]
        yield 'string' => ['Gestionale', 'string', 'Gestionale', 'Gestionale'];
        yield 'int' => [123, 'int', '123', 123];
        yield 'int from string' => ['123', 'int', '123', 123];
        yield 'float' => [1.5, 'float', '1.5', 1.5];
        yield 'bool true' => [true, 'bool', '1', true];
        yield 'bool false' => [false, 'bool', '0', false];
        yield 'json array' => [['a', 'b'], 'json', '["a","b"]', ['a', 'b']];
        yield 'json map' => [['k' => 1], 'json', '{"k":1}', ['k' => 1]];
    }

    #[DataProvider('roundTripCases')]
    public function testSerialize(mixed $native, string $type, string $expectedRaw, mixed $expectedNative): void
    {
        self::assertSame($expectedRaw, ValueCaster::serialize($native, $type));
    }

    #[DataProvider('roundTripCases')]
    public function testDeserialize(mixed $native, string $type, string $expectedRaw, mixed $expectedNative): void
    {
        self::assertSame($expectedNative, ValueCaster::deserialize($expectedRaw, $type));
    }

    public function testUnknownTypeFallsBackToString(): void
    {
        self::assertSame('raw', ValueCaster::serialize('raw', 'whatever'));
        self::assertSame('raw', ValueCaster::deserialize('raw', 'whatever'));
    }

    public function testBoolDeserializeAcceptsCommonTruthyStrings(): void
    {
        self::assertTrue(ValueCaster::deserialize('true', 'bool'));
        self::assertTrue(ValueCaster::deserialize('yes', 'bool'));
        self::assertFalse(ValueCaster::deserialize('0', 'bool'));
        self::assertFalse(ValueCaster::deserialize('', 'bool'));
    }
}
