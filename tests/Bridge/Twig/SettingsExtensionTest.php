<?php

namespace Fedale\SettingBundle\Tests\Bridge\Twig;

use Fedale\SettingBundle\Bridge\Twig\SettingsExtension;
use Fedale\SettingBundle\Manager\SettingsManager;
use Fedale\SettingBundle\Tests\Fixtures\FixedTenantProvider;
use Fedale\SettingBundle\Tests\Fixtures\InMemorySettingStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twig\TwigFunction;

#[CoversClass(SettingsExtension::class)]
final class SettingsExtensionTest extends TestCase
{
    private function extension(): SettingsExtension
    {
        $manager = new SettingsManager(
            new InMemorySettingStorage([0 => ['general.footer' => '(c) 2026']]),
            new FixedTenantProvider(0),
            new ArrayAdapter(),
        );

        return new SettingsExtension($manager);
    }

    public function testExposesSettingFunction(): void
    {
        $functions = $this->extension()->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('setting', $functions[0]->getName());
    }

    public function testSettingReadsArbitraryKey(): void
    {
        self::assertSame('(c) 2026', $this->extension()->getSetting('general.footer'));
    }

    public function testSettingReturnsDefaultForUnknownKey(): void
    {
        self::assertSame('', $this->extension()->getSetting('does.not.exist', ''));
    }
}
