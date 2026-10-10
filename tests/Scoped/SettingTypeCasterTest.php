<?php

namespace Fedale\SettingBundle\Tests\Scoped;

use Fedale\SettingBundle\Scoped\SettingType;
use Fedale\SettingBundle\Scoped\SettingTypeCaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingTypeCaster::class)]
final class SettingTypeCasterTest extends TestCase
{
    /**
     * @return iterable<string, array{SettingType, mixed, mixed, string}>
     */
    public static function roundTrips(): iterable
    {
        yield 'bool from form' => [SettingType::Bool, 'on', true, '1'];
        yield 'bool false' => [SettingType::Bool, false, false, '0'];
        yield 'int from string' => [SettingType::Int, ' 42 ', 42, '42'];
        yield 'negative int' => [SettingType::Int, -3, -3, '-3'];
        yield 'float keeps decimals' => [SettingType::Float, '0.1', 0.1, '0.1'];
        yield 'whole float' => [SettingType::Float, 2, 2.0, '2.0'];
        yield 'string' => [SettingType::String, 'cards', 'cards', 'cards'];
        yield 'enum int input' => [SettingType::Enum, 3, '3', '3'];
    }

    #[DataProvider('roundTrips')]
    public function testRoundTrip(SettingType $type, mixed $input, mixed $native, string $stored): void
    {
        $normalized = SettingTypeCaster::normalize($type, $input);

        self::assertSame($native, $normalized);
        self::assertSame($stored, SettingTypeCaster::serialize($type, $normalized));
        self::assertSame($native, SettingTypeCaster::deserialize($type, $stored));
    }

    /**
     * @return iterable<string, array{SettingType, mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'bool from garbage' => [SettingType::Bool, 'maybe'];
        yield 'int from decimal' => [SettingType::Int, '1.5'];
        yield 'int from float' => [SettingType::Int, 1.5];
        yield 'float from text' => [SettingType::Float, 'abc'];
        yield 'string from array' => [SettingType::String, ['a']];
        yield 'enum from bool' => [SettingType::Enum, true];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(SettingType $type, mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SettingTypeCaster::normalize($type, $input);
    }

    /**
     * @return iterable<string, array{SettingType, string}>
     */
    public static function invalidStored(): iterable
    {
        // Reading is strict: a value saved under an older type is not misread.
        yield 'bool' => [SettingType::Bool, 'yes'];
        yield 'int' => [SettingType::Int, 'cards'];
        yield 'float' => [SettingType::Float, 'cards'];
    }

    #[DataProvider('invalidStored')]
    public function testInvalidStoredValueIsRejected(SettingType $type, string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SettingTypeCaster::deserialize($type, $raw);
    }

    public function testMatchChoiceReturnsTheChoiceInItsOwnType(): void
    {
        $choices = ['size.small' => 10, 'size.large' => 50];

        self::assertSame(50, SettingTypeCaster::matchChoice($choices, '50'));
        self::assertSame('free', SettingTypeCaster::matchChoice(null, 'free'));

        $this->expectException(\InvalidArgumentException::class);
        SettingTypeCaster::matchChoice($choices, '20');
    }
}
