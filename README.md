# Fedale Setting Bundle

**Multi-tenant** application settings stored in the database and editable at
runtime — not Symfony parameters compiled into the container.

- Per-tenant fallback: `tenant N` → `tenant 0` (global) → caller-provided default.
- Layered PSR-6 cache, automatically invalidated on every write.
- Abstract storage (`SettingStorageInterface`): a Doctrine ORM bridge is included,
  but it can be swapped (API, file, Mongo, ...).
- Free-form keys: no closed set, any string is a valid key.
- Optional per-key value validation, opt-in and enforced on every write.
- Optional **scoped settings**: declared, typed settings resolved along a chain of
  scopes (e.g. global → grid), on top of the tenant fallback.

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
    validation:
        enabled: false   # opt-in per-key value validation (see "Validating values")
        constraints_provider: null  # service id implementing SettingConstraintsProviderInterface
    scoped:
        store: null      # null = scoped settings off; 'doctrine'; or a ScopedStoreInterface service id
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

## Validating values

By default keys are **free-form**: any value is accepted. You can opt specific
keys into validation, enforced at the single write choke point
(`SettingsManager::set()`), so the rules hold no matter where the write comes from
— a form, a CLI command or plain code. Keys without declared rules stay free-form.

Validation builds on `symfony/validator` (install it: `composer require
symfony/validator`). Declare the per-key constraints by registering a service that
implements `SettingConstraintsProviderInterface`; the bundle ships
`ArrayConstraintsProvider` for the common static-map case:

```php
// config/services.php
use Fedale\SettingBundle\Contract\SettingConstraintsProviderInterface;
use Fedale\SettingBundle\Setting\Validation\ArrayConstraintsProvider;
use Symfony\Component\Validator\Constraints as Assert;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('app.setting_constraints', ArrayConstraintsProvider::class)
        ->args([[
            'myKey'         => [new Assert\Length(min: 3, max: 10)],
            'general.title' => [new Assert\NotBlank()],
            'general.email' => [new Assert\Email()],
        ]]);

    $container->services()
        ->alias(SettingConstraintsProviderInterface::class, 'app.setting_constraints');
};
```

Then enable validation, pointing it at the provider:

```yaml
# config/packages/fedale_setting.yaml
fedale_setting:
    validation:
        enabled: true
        constraints_provider: app.setting_constraints
```

Now a write that breaks a rule throws `SettingValidationException` instead of
persisting:

```php
try {
    $this->settings->set('myKey', 'ab'); // shorter than 3
} catch (\Fedale\SettingBundle\Exception\SettingValidationException $e) {
    $e->getKey();                // 'myKey'
    $e->getViolationMessages();  // ['This value is too short. It should have 3 characters or more.']
}
```

For dynamic rules (e.g. constraints stored in the database or computed per key),
implement `SettingConstraintsProviderInterface` directly instead of using
`ArrayConstraintsProvider`. Return `[]` for any key that must stay free-form:

```php
// src/Setting/AppSettingConstraintsProvider.php
use Fedale\SettingBundle\Contract\SettingConstraintsProviderInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

final class AppSettingConstraintsProvider implements SettingConstraintsProviderInterface
{
    /**
     * @return list<Constraint>
     */
    public function constraintsFor(string $key): array
    {
        return match (true) {
            // every key under "limits." must be a positive integer
            str_starts_with($key, 'limits.') => [new Assert\Positive()],
            'myKey' === $key                 => [new Assert\Length(min: 3, max: 10)],
            default                          => [], // free-form
        };
    }
}
```

With autowiring, just point `constraints_provider` at the class id:

```yaml
fedale_setting:
    validation:
        enabled: true
        constraints_provider: App\Setting\AppSettingConstraintsProvider
```

### How the validation flow fits together

1. `SettingsManagerInterface::set($key, $value, ...)` is called (form, CLI or code).
2. Before persisting, the manager calls the configured `SettingValidatorInterface`.
3. With validation enabled, `ConstraintsSettingValidator` asks your provider for
   the constraints of `$key`. No constraints → the value is accepted (free-form).
4. Otherwise the value is checked with `symfony/validator`; on failure a
   `SettingValidationException` is thrown and **nothing is written**.

This means the rules are enforced once, at the single write choke point, so you
cannot bypass them by writing from a different entry point.

## Scoped settings

Free-form keys fit application-wide settings. Some values instead depend on
*where* they apply: a grid option set for every grid or for one grid, a booking
rule set for an event type, a resource or a single occurrence. Scoped settings
cover that case: a consumer bundle **declares** its settings, and the bundle
resolves each one along a **chain of scopes**, most specific first.

They are an additional layer: `SettingsManagerInterface`, the `setting` table and
free-form keys are untouched.

### Enabling them

```yaml
# config/packages/fedale_setting.yaml
fedale_setting:
    scoped:
        store: doctrine   # or the id of a service implementing ScopedStoreInterface
```

With the default `store: null` the feature is off: `ScopedSettingsResolver::isEnabled()`
returns `false`, lookups find nothing and writes throw. The resolver and the writer
are always registered, so a bundle can depend on them unconditionally.

The `doctrine` store uses its own table, mapped only when it is selected; generate
the migration with `php bin/console make:migration`. Equivalent schema:

```sql
CREATE TABLE setting_scoped (
    id          INT AUTO_INCREMENT NOT NULL,
    tenant_id   INT NOT NULL,
    namespace   VARCHAR(100) NOT NULL,
    level       VARCHAR(50) NOT NULL,
    scope_id    VARCHAR(191) NOT NULL DEFAULT '',   -- '' for scopes without an id
    setting_key VARCHAR(100) NOT NULL,
    value       LONGTEXT NOT NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_setting_scoped (tenant_id, namespace, level, scope_id, setting_key)
);
```

A lookup reads only the scopes of its chain, through a PSR-6 cache (the pool and
`ttl` of `fedale_setting.cache`) with one item per tenant, namespace and scope.
Levels with many ids, such as occurrences or users, stay cheap.

### Declaring a setting

One class per setting, implementing `SettingDefinitionInterface` or extending
`AbstractSettingDefinition`. With autoconfiguration it is picked up automatically
(tag `fedale_setting.definition`).

```php
use Fedale\SettingBundle\Scoped\AbstractSettingDefinition;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\SettingType;
use Symfony\Component\Validator\Constraints as Assert;

final class PageSizeSetting extends AbstractSettingDefinition
{
    public function namespace(): string { return 'gridview.ui'; }
    public function key(): string { return 'page_size'; }
    public function type(): SettingType { return SettingType::Int; }
    public function label(): string { return 'ui_settings.page_size'; }

    public function supportsScope(Scope $scope): bool
    {
        return \in_array($scope->level, ['global', 'grid'], true);
    }

    public function constraints(): array
    {
        return [new Assert\Range(min: 5, max: 500)];
    }
}
```

| Part | Meaning |
|---|---|
| `namespace()` / `key()` | Identity; `[a-z0-9_.]`, the key is unique within the namespace |
| `type()` | `Bool`, `Int`, `Float`, `String`, `Enum` |
| `supportsScope()` | The scope levels the setting can be set at |
| `choices()` | `label key => value`; required for `Enum`, `null` = free input |
| `constraints()` | Validator constraints checked on write, when `symfony/validator` is installed |
| `isApplicable($value, $context)` | Guard at resolve time, with a context supplied by the caller |

There is **no default value** in the definition: when nothing is found the
setting is simply missing from the result, and the consumer keeps its own
configuration in code.

### Resolving: tenant first, then the chain

A scope is a level and, except for `global`, an id: `Scope::global()`,
`new Scope('grid', 'users')`. The consumer builds the chain, most specific first.
Tenant 0 holds **platform defaults**, which never beat a tenant's own choice, so
the whole chain of the current tenant is walked before the one of tenant 0:

```
(N, grid:users) → (N, global) → (0, grid:users) → (0, global) → no value
```

With these stored values:

| tenant | scope | `renderer` |
|---|---|---|
| 0 | global | `table` |
| 0 | grid:users | `list` |
| 5 | global | `cards` |

tenant 5 gets `cards` on the users grid (its own global beats the platform's
per-grid default), any other tenant gets `list` there and `table` elsewhere.

```php
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;

$chain = ScopeChain::of(new Scope('grid', 'users'), Scope::global());

// Every resolved setting of the namespace: key => ResolvedValue
$resolved = $resolver->resolve('gridview.ui', $chain, $gridOptions);

$resolved['renderer']->value;     // 'cards', already in the native type
$resolved['renderer']->scope;     // Scope global: where it comes from...
$resolved['renderer']->tenantId;  // 5: ...and for which tenant

// A single setting, or null
$resolver->get('gridview.ui', 'page_size', $chain)?->value;

// The values stored at exactly one scope, without inheritance (e.g. to fill a form)
$resolver->values('gridview.ui', new Scope('grid', 'users'));

// What each setting falls back to if the head of the chain is cleared for the
// current tenant: the "inherit" hint of the form editing that scope
$resolver->inherited('gridview.ui', $chain, $gridOptions);
```

A candidate is skipped, and the lookup moves on, when:

- its scope level is no longer supported by the definition (logged);
- the stored value no longer fits the definition: wrong type, choice no longer
  offered (logged);
- `isApplicable($value, $context)` returns `false`. The third argument of
  `resolve()`/`get()` is passed through as `$context`. For example, gridview
  passes the grid's options and rejects a renderer that grid does not support,
  so the global value is used instead.

The same lookup with five levels, for a booking rule:

```php
$chain = ScopeChain::of(
    new Scope('occurrence', '981'),
    new Scope('series', '12'),
    new Scope('resource', '7'),
    new Scope('eventType', 'lesson'),
    Scope::global(),
);

$deadline = $resolver->get('booking.policy', 'cancel_deadline_hours', $chain)?->value
    ?? 24; // the consumer's own default
```

### Writing

`ScopedSettingsWriter` is the single write choke point:

```php
use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;

try {
    $writer->save('gridview.ui', new Scope('grid', 'users'), [
        'renderer'  => 'cards',
        'page_size' => '50',  // form input is accepted and cast to int
        'striped'   => null,  // null or '' clears the key: back to "inherit"
    ]);                       // 4th argument: tenant id, the current one by default
} catch (SettingValidationException $e) {
    $e->getKey();               // the first invalid key; nothing was saved
    $e->getViolationMessages();
}
```

Each value must be a declared setting, settable at that scope, of the right type,
one of the choices if any, and pass `constraints()`. Keys not passed are left
untouched.

### Custom store

Implement `Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface` and point
`fedale_setting.scoped.store` at the service id. The store persists strings and
does not know the setting types; the bundle wraps it in the PSR-6 cache.

## Cache

The `settings.0` and `settings.{tenantId}` layers are cached separately and merged
in memory on each read. A `set()` only invalidates the layer it touches: changing a
global default (tenant 0) therefore propagates to all tenants by invalidating a
single entry.

## License

MIT.
