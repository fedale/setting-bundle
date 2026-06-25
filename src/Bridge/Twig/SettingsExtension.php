<?php

namespace Fedale\SettingBundle\Bridge\Twig;

use Fedale\SettingBundle\Contract\SettingsManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Espone la funzione Twig `setting()` per leggere i settings nei template su
 * una chiave qualsiasi (anche creata a runtime dall'admin):
 *
 *     {{ setting('general.footer', '') }}
 *
 * Registrata solo se TwigBundle e' presente (vedi FedaleSettingBundle).
 */
final class SettingsExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsManagerInterface $settings,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('setting', $this->getSetting(...)),
        ];
    }

    public function getSetting(
        string $key,
        mixed $default = null,
        ?int $tenantId = null,
    ): mixed {
        return $this->settings->get($key, $default, $tenantId);
    }
}
