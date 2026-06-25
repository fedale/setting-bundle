<?php

namespace Fedale\SettingBundle\Tests\Bridge\Doctrine;

use Fedale\SettingBundle\Bridge\Doctrine\Entity\Setting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Setting::class)]
final class SettingTest extends TestCase
{
    public function testCastedValueRoundTripsThroughTypedColumns(): void
    {
        $setting = (new Setting())->setCastedValue(123, 'int');

        self::assertSame('123', $setting->getValue(), 'salvato come stringa');
        self::assertSame('int', $setting->getType());
        self::assertSame(123, $setting->getCastedValue(), 'riletto come int');
    }

    public function testJsonValueIsStoredAsJsonString(): void
    {
        $setting = (new Setting())->setCastedValue(['a', 'b'], 'json');

        self::assertSame('["a","b"]', $setting->getValue());
        self::assertSame(['a', 'b'], $setting->getCastedValue());
    }

    public function testPrePersistSetsBothTimestamps(): void
    {
        $setting = new Setting();
        $setting->onPrePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $setting->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $setting->getUpdatedAt());
        self::assertEquals($setting->getCreatedAt(), $setting->getUpdatedAt());
    }

    public function testPreUpdateMovesOnlyUpdatedAt(): void
    {
        $setting = new Setting();
        $setting->onPrePersist();
        $created = $setting->getCreatedAt();

        $setting->onPreUpdate();

        self::assertSame($created, $setting->getCreatedAt(), 'createdAt invariato');
        self::assertGreaterThanOrEqual($created, $setting->getUpdatedAt());
    }

    public function testTenantDefaultsToGlobal(): void
    {
        self::assertSame(0, (new Setting())->getTenantId());
    }
}
