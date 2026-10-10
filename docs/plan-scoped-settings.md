# Plan: scoped settings with declared definitions

## Context

`fedale/setting-bundle` (released as `v1.0.0`) offers:
- free-form settings, saved through a swappable storage (Doctrine optional);
- per-tenant fallback (N → 0 → default);
- typed values (`ValueCaster`), PSR-6 cache and optional per-key validation.

Several bundles need the same thing: **values configurable at runtime, organized
along a chain of scopes where the most specific one wins**.

- **gridview-bundle**: UI settings on two scopes, global → grid. Today the logic
  lives in gridview: `UiSettingInterface`, `UiSettingsResolver`,
  `UiSettingsStoreInterface`, `NullUiSettingsStore`, in `src/UiSettings/`,
  commit `4ab82bb`.
- **calendar-bundle** (future): UI settings, global → calendar.
- **booking-policy** (future): booking rules on five scopes, global → event type →
  resource → series → occurrence. Typed values: durations, integers, dates,
  lists, enums.

The goal is to move the **generic core** of this mechanism into setting-bundle,
including the fallback when a value is not applicable. Each bundle keeps only its
specific part: the definitions, the context passed to `isApplicable()`, the
Hotwire flow.

## Requirements

- New namespace `Fedale\SettingBundle\Scoped\`, **without a Doctrine
  dependency**. The Doctrine store lives in `Bridge/Doctrine/Scoped/`.
- An **additional layer**: the current API (`SettingsManagerInterface`,
  `SettingStorageInterface`), the `setting` table and free-form keys stay
  **unchanged**. No compatibility break: the work ships as `1.1`.
- Consumer bundles depend only on the **interfaces**. The store is swappable and
  a `NullScopedStore` keeps the feature off.
- Tenant and scope are **independent axes**, combined with the "tenant first"
  rule (see *Precedence*).
- Code, comments and documentation of the bundle in English. Existing files have
  Italian comments: they are not translated as part of this work unless asked.

## Model

A setting has two parts:
- the **definition** says *what* can be set (namespace, key, type, allowed
  scopes, choices, constraints, applicability). It is code and lives in the
  consumer bundle;
- the **stored value** says *where* it was set:
  `(tenant, namespace, scope, key) → value`. It is a row in the store.

### Setting definition

```php
interface SettingDefinitionInterface
{
    public function namespace(): string;        // e.g. 'gridview.ui', 'booking.policy'
    public function key(): string;              // unique within the namespace
    public function type(): SettingType;
    public function label(): string;            // translation key; domain chosen by the consumer
    public function help(): ?string;
    public function supportsScope(Scope $scope): bool;
    /** @return array<string, scalar>|null label key => value; null = free input */
    public function choices(?Scope $scope): ?array;
    /** @return list<Constraint> applied on write when the validator is installed */
    public function constraints(): array;
    /** false skips the candidate; $context is opaque and supplied by the consumer */
    public function isApplicable(mixed $value, mixed $context): bool;
}
```

- `SettingType` is an enum. v1 cases: `Bool`, `Int`, `Float`, `String`, `Enum`.
  `Duration`, `DateTime` and `List` come with booking-policy (phase 6).
- `SettingTypeCaster` (static, like `ValueCaster`) converts between three forms:
  - **input**, lenient: `'on'`, `' 42 '`;
  - **native**, what the code works with;
  - **stored**, a string.

  Reading is strict, so a value saved under an older type is skipped, not
  misread. `Enum` values are matched against the choices in string form, and the
  choice comes back in its own type.
- The store persists **strings only** and does not know the types: the
  definition is the single source of truth, so there is no `type` column.
- `AbstractSettingDefinition` provides sensible defaults: every scope, no help,
  free input, no constraints, always applicable.
- Definitions are autoconfigured with the `fedale_setting.definition` tag and
  collected in a `SettingDefinitionRegistry`, indexed by namespace and key.
  Duplicates and invalid names fail at boot.
- **The default stays out of the definition.** If no step of the chain has a
  value, the resolver answers "no value" and the consumer falls back to its own
  configuration in code (in gridview, `viewConfig()`).
- `namespace` and `key` accept `[a-z0-9_.]` (max 100). A scope `level` accepts
  `[A-Za-z0-9_.]` (max 50, e.g. `eventType`). A scope `id` is any non-empty
  string up to 191 characters.

### Scope and chain

```php
final readonly class Scope implements \Stringable
{
    public function __construct(public string $level, public ?string $id = null) {}
    public static function global(): self;
    public function __toString(): string;   // 'grid:users', 'global'
}

final readonly class ScopeChain
{
    /** @param list<Scope> $scopes most specific first; non-empty, no duplicates */
    public function __construct(public array $scopes) {}
    public static function of(Scope ...$scopes): self;
}
```

The consumer builds the chain. Two examples:
- gridview: `[grid:users, global]`;
- booking-policy: `[occurrence:981, series:12, resource:7, eventType:lesson, global]`.

### Precedence: tenant first

Tenant 0 holds **platform defaults**, never enforced rules: a default must not
beat a tenant's explicit choice. So the resolver walks the whole scope chain of
the current tenant before moving on to tenant 0:

```
(N, scope 1) → (N, scope 2) → … → (0, scope 1) → (0, scope 2) → … → no value
```

If the current tenant is 0, the tenant chain is just `[0]`. At each step a
candidate is skipped, and the lookup goes on, if:
- the definition no longer supports that scope (logged);
- the stored value no longer fits (wrong type, choice no longer offered) (logged);
- `isApplicable($value, $context)` returns false.

Stored keys without a definition are ignored, because the resolver iterates over
the definitions.

Example, setting `gridview.ui` / `renderer`:

| Row | tenant | scope | value |
|---|---|---|---|
| A | 0 | global | `table` |
| B | 0 | grid:users | `list` |
| C | 5 | global | `cards` |
| D | 7 | grid:orders | `cards` |

| Who opens the grid | Result | Why |
|---|---|---|
| tenant 5, users | `cards` (C) | the tenant's global beats the platform's per-grid value |
| tenant 5, users, `cards` not applicable | `list` (B) | C skipped by `isApplicable`, lookup goes on |
| tenant 7, users | `list` (B) | tenant 7 has no value for users |
| tenant 7, orders | `cards` (D) | |
| tenant 9, orders | `table` (A) | |

The order lives **only in the resolver**: stored data does not depend on it. If
a different order is ever needed (even for a single namespace), the resolver
changes and no data is migrated.

### Store

```php
interface ScopedStoreInterface
{
    /** @return array<int, array<string, array<string, string>>> tenantId => (string) scope => key => stored value */
    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array;

    /** @param array<string, string|null> $values null clears the key; keys not passed are untouched */
    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void;
}
```

Implementations:
- **`NullScopedStore`**: the default. It loads nothing and refuses writes.
- **`ScopedSettingRepository`** (`Bridge/Doctrine/Scoped/`, `store: doctrine`):
  dedicated table, separate from `setting`.

  ```
  setting_scoped
      id, tenant_id, namespace, level, scope_id, setting_key, value (text), created_at, updated_at
      UNIQUE (tenant_id, namespace, level, scope_id, setting_key)
  ```

  - `scope_id` is `''` for scopes without an id, **never NULL**: with NULL the
    unique index would not prevent duplicates of the global scope.
  - Column names are explicit, so the index holds whatever naming strategy the
    app uses.
  - **Only the scopes of the chain are read**: even with millions of
    occurrences, a request loads one. No dedicated store is needed for
    high-cardinality levels (occurrences, series, users).
  - `save()` writes every key in one transaction; null **deletes** the row.
  - The ORM mapping of `setting_scoped` is registered only when `scoped.store`
    is `doctrine`. It lives in its own directory: apps without scoped settings
    get no extra table in their migrations, and an app with a custom storage for
    `setting` can still use the Doctrine store for scopes.
- **`CachingScopedStore`**: a PSR-6 decorator wrapped around any configured
  store (Doctrine or custom).
  - One item per (tenant, namespace, scope), read with a single `getItems()`.
  - Missing items are loaded with a single call to the inner store and saved
    **even when empty**; otherwise every scope without values would hit the
    database again.
  - A `save()` invalidates one item.
  - The cache key is a hash, because PSR-6 reserves `:` and other characters an
    id may contain.
  - It uses the pool and `ttl` of `fedale_setting.cache`.

### Resolver and writer

- **`ScopedSettingsResolver`** (implements `ResetInterface`, tagged `kernel.reset`):
  - `isEnabled(): bool`: false with the `NullScopedStore`;
  - `resolve(string $namespace, ScopeChain $chain, mixed $context = null, ?int $tenantId = null): array<string, ResolvedValue>`;
  - `get(string $namespace, string $key, ScopeChain $chain, mixed $context = null, ?int $tenantId = null): ?ResolvedValue`:
    an undeclared key is a programming error (`InvalidArgumentException`);
  - `values(string $namespace, Scope $scope, ?int $tenantId = null): array<string, mixed>`:
    the native values of one scope, without inheritance, to prefill a form.
  - `inherited(string $namespace, ScopeChain $chain, mixed $context = null, ?int $tenantId = null): array<string, ResolvedValue>`:
    the same lookup without the current tenant's step at the head of the chain,
    i.e. what applies if that value is cleared. It is the "inherit" hint of a form.

  `ResolvedValue` holds `value` (native type), `scope` and `tenantId` of origin,
  so the UI can show "inherited from global (platform)".

  The per-request cache holds the **stored strings** per (tenant, scope), not
  the resolved result, because the result depends on `$context`. Only the pairs
  not seen yet in the request are loaded. The logger is optional (`NullLogger`
  by default, channel `fedale_setting`).
- **`ScopedSettingsWriter`**:
  - `save(string $namespace, Scope $scope, array $values, ?int $tenantId = null)`:
    the tenant defaults to the current one. Each value must be a declared
    setting, settable at the scope, of the right type, one of the choices if
    any, and pass `constraints()` when `symfony/validator` is installed. Then
    the values are converted to stored form and passed to the store;
  - on error it throws `SettingValidationException` (existing) for the first
    invalid key, and nothing is saved;
  - a `null` or `''` value removes the key ("inherit"). Clearing is allowed even
    at a scope the setting no longer supports, so stale values can be removed;
  - after a save it resets the resolver's per-request cache.

### Form (optional bridge)

`Bridge/Form`, active only if `symfony/form` is installed, builds a form from the
definitions allowed in a scope:

| Type | Field |
|---|---|
| `Enum`, or with choices | `ChoiceType` |
| `Bool` | three-state choice: inherit / yes / no |
| `Int`, `Float` | number field |
| `Duration` | value + unit (minutes, hours, days) |
| `DateTime` | date-time field |
| `List` | multiple `ChoiceType` |

The empty value always means "inherit".

### The `Duration` value object (phase 6)

`SettingType::Duration` will use `Fedale\SettingBundle\Scoped\Value\Duration`:

```php
final readonly class Duration implements \Stringable
{
    // Calendar days and exact seconds are kept apart: across a DST change
    // "1 day" (P1D) is not "24 hours" (PT24H).
    private function __construct(public int $days, public int $seconds) {}

    public static function fromIso(string $iso): self;   // P30D, PT2H, P1DT12H; rejects Y/M/W components
    public static function ofDays(int $days): self;
    public static function ofHours(int $hours): self;
    public static function ofMinutes(int $minutes): self;

    public function toIso(): string;                      // normalized form, used for storage
    public function addTo(\DateTimeImmutable $at): \DateTimeImmutable;   // days in $at's timezone, then seconds
    public function subtractFrom(\DateTimeImmutable $at): \DateTimeImmutable;
    public function equals(self $other): bool;            // structural: P1D != PT24H
    public function isZero(): bool;
}
```

Other types planned for phase 6:

| `SettingType` | Stored form |
|---|---|
| `Duration` | normalized ISO 8601 (`P1DT12H`) |
| `DateTime` | RFC 3339 with offset (`2026-10-10T09:00:00+02:00`) |
| `List` | JSON list of scalars; with `choices()` it is a multiple choice |

- **No months or years in v1**: they have a variable length and make
  comparisons and normalization ambiguous.
- **No generic ordering**. Whoever needs to compare does it on concrete
  instants, e.g. `$d->subtractFrom($start) < $now`.

## Configuration

```yaml
fedale_setting:
    scoped:
        store: null   # null = NullScopedStore; 'doctrine' = setting_scoped table; or a service id
```

## Migration of gridview-bundle

Commit `4ab82bb` is **not part of any release** (the latest tag is `v1.2.2`): it
is replaced directly, without a compatibility adapter.

- `UiSettingInterface` extends `SettingDefinitionInterface`, with namespace
  `gridview.ui`, and keeps `optionPath()`. `supportsGlobal()`/`supportsGrid()`
  become `supportsScope()`.
- `isApplicable($value, $options)` stays as it is: gridview passes the grid's
  resolved options as `$context`.
- `choices(?Scope)`: gridview rebuilds the `GridDescriptor` from the scope id
  through its own `GridRegistry`.
- `UiSettingsResolver::apply()` asks the core for
  `resolve('gridview.ui', [grid:<id>, global], $options)` and writes the values
  found with `setPath()`. The current fallback loop disappears from gridview.
- `UiSettingsStoreInterface` and `NullUiSettingsStore` are removed.

## Phases

1. **Core** — done.
   - Pieces: `SettingType` (v1 cases), `SettingTypeCaster`, definition interface
     and base class, `Scope`/`ScopeChain`, registry, `ScopedStoreInterface`,
     `NullScopedStore`, resolver.
   - Tests:
     - tenant-first precedence;
     - "inherit";
     - invalid values skipped and logged;
     - fallback on `isApplicable`;
     - current tenant equal to 0;
     - per-request cache.
2. **Doctrine store and cache** — done.
   - Pieces: entity, conditional mapping, `ScopedSettingRepository`,
     `CachingScopedStore`.
   - Tests (SQLite in memory):
     - reads limited to the scopes of the chain;
     - empty bags cached;
     - invalidation after `save()`;
     - deletion with null;
     - uniqueness of the global scope;
     - ids containing `.` and `:`.
3. **Writer and validation** — done: types, allowed scopes, choices, optional
   constraints.
4. **Migration of gridview-bundle**, in its own repository: the first real
   consumer, used to check the API before going further.
5. **Documentation** — done: README section with a two-scope and a five-scope
   example.
6. **When booking-policy starts**:
   - `Duration`, with tests on parsing, normalization and
     `addTo`/`subtractFrom` across a DST change;
   - `DateTime`;
   - `List`.
7. **Form bridge**, once at least two bundles actually use it.

## Open questions

- **Locked values**: a tenant-0 value the tenant cannot override, shown
  read-only in the UI. Out of v1: tenant 0 holds defaults only.
- **User scope**: the dedicated-table store already supports it (level
  `user:<id>` at the head of the chain). It is out of v1 only because no bundle
  needs it yet.
- **Months and years in `Duration`**: out of v1. To be evaluated if
  booking-policy needs them, e.g. "registrations open one month before".
