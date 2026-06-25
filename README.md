# Fedale Setting Bundle

**Multi-tenant** application settings stored in the database and editable at
runtime — not Symfony parameters compiled into the container.

- Per-tenant fallback: `tenant N` → `tenant 0` (global) → caller-provided default.
- Layered PSR-6 cache, automatically invalidated on every write.
- Abstract storage (`SettingStorageInterface`): a Doctrine ORM bridge is included,
  but it can be swapped (API, file, Mongo, ...).
- Free-form keys: no closed set, any string is a valid key.

## Installation

```bash
composer require fedale/setting-bundle
```

Register the bundle (Symfony Flex does it for you):

```php
// config/bundles.php
return [
    Fedale\SettingBundle\FedaleSettingBundle::class => ['all' => true],
];
```

Configuration (optional, defaults shown):

```yaml
# config/packages/fedale_setting.yaml
fedale_setting:
    cache:
        enabled: true
        pool: cache.app
        ttl: null        # seconds; null = no expiry
    provider: doctrine   # or the id of a custom SettingStorageInterface service
```

## Database schema

The bundle auto-registers the ORM mapping of the `Setting` entity; generate the
migration in your app with `php bin/console make:migration`. Equivalent schema:

```sql
CREATE TABLE setting (
    id          INT AUTO_INCREMENT NOT NULL,
    tenant_id   INT NOT NULL,
    name        VARCHAR(255) NOT NULL,
    value       LONGTEXT NOT NULL,
    type        VARCHAR(20) NOT NULL DEFAULT 'string',
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_setting_tenant_name (tenant_id, name),
    KEY idx_setting_tenant_active (tenant_id, active)
);
```

`tenant_id = 0` holds the global default configuration; `tenant_id > 0` holds the
per-tenant overrides.

`value` is stored as a string; `type` (`string|int|float|bool|json`) drives the
(de)serialization: scalars stay human-readable (`123`, not `"123"`), complex values
are stored as JSON under `type = 'json'`.

## Usage

```php
use Fedale\SettingBundle\Contract\SettingsManagerInterface;

final class GeneralController
{
    public function __construct(
        private readonly SettingsManagerInterface $settings,
    ) {
    }

    public function index(): Response
    {
        // tenant 12: "Cliente XYZ" if an override exists, otherwise the global default
        $title = $this->settings->get('general.title', 'Gestionale');
        $theme = $this->settings->get('general.theme', 'classic'); // inherited from tenant 0
        $useCache = $this->settings->get('general.useCache', false); // already a bool

        // write (current tenant, resolved by the TenantProvider)
        $this->settings->set('general.footer', '(c) 2026', type: 'string');
        $this->settings->set('general.useCache', true, type: 'bool');

        // write explicitly to a tenant / to the global scope
        $this->settings->set('general.title', 'Cliente XYZ', tenantId: 12);
        $this->settings->set('general.theme', 'dark', tenantId: 0); // default for everyone

        // ...
    }
}
```

### Reading settings in Twig

When TwigBundle is installed, the bundle registers a `setting()` function. It is
**read-only** — writes are done in PHP via `SettingsManagerInterface::set()`.

Signature:

```twig
setting(key, default = null, tenantId = null)
```

Examples:

```twig
{# simple read with a default #}
<title>{{ setting('general.title', 'Gestionale') }}</title>

<footer>{{ setting('general.footer', '') }}</footer>

{# the value comes back already typed: a bool is a real bool #}
{% if setting('general.useCache', false) %}
    <meta name="cache" content="on">
{% endif %}

{# a 'json' value comes back as a navigable array/object #}
<ul>
{% for item in setting('menu.items', []) %}
    <li>{{ item.label }}</li>
{% endfor %}
</ul>

{# explicit tenant (usually unnecessary: the TenantProvider resolves it) #}
{{ setting('general.theme', 'classic', 12) }}
```

Key points:

- The **key is a free-form string** — any setting created at runtime (including from
  an admin UI) is readable, with no registration required anywhere.
- The **per-tenant fallback** applies: current tenant first, then tenant 0 (global),
  finally the `default` you pass in the template.
- The function exists **only if Twig is installed**. In a Symfony app with
  TwigBundle that is already guaranteed; registration is conditional, see
  `FedaleSettingBundle::loadExtension()`.

## Multi-tenant: resolving the current tenant

When `tenantId` is not passed, the bundle uses `TenantProviderInterface`. By default
(`DefaultTenantProvider`) the current tenant is always `0`. Multi-tenant apps
register their own implementation:

```php
// src/Setting/RequestTenantProvider.php
use Fedale\SettingBundle\Contract\TenantProviderInterface;

final class RequestTenantProvider implements TenantProviderInterface
{
    public function __construct(private readonly RequestStack $stack) {}

    public function getCurrentTenantId(): int
    {
        return (int) $this->stack->getCurrentRequest()?->attributes->get('_tenant_id', 0);
    }
}
```

```yaml
# config/services.yaml
Fedale\SettingBundle\Contract\TenantProviderInterface:
    alias: App\Setting\RequestTenantProvider
```

## Custom storage

To use a source other than Doctrine, implement
`Fedale\SettingBundle\Contract\SettingStorageInterface` and point
`fedale_setting.provider` at the service id. Caching and the per-tenant fallback are
still handled by the bundle.

## Cache

The `settings.0` and `settings.{tenantId}` layers are cached separately and merged
in memory on each read. A `set()` only invalidates the layer it touches: changing a
global default (tenant 0) therefore propagates to all tenants by invalidating a
single entry.

## License

MIT.
