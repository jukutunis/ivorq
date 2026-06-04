# Multi-Tenancy Strategy

Project: IVORQ Hotel Operations Platform
Version: 1.0 — Sprint 03.2 Hardening
Status: Active

---

## Overview

IVORQ is a **multi-property SaaS platform**. A single database instance serves
multiple hotel companies, each owning one or more properties. All business data
is scoped to a `property_id`. Property isolation is enforced at the model, query,
validation, and service layers simultaneously.

Isolation is **never optional**. Every layer must enforce it independently.

---

## 1. Property ID Strategy

Every business table carries a `property_id` foreign key referencing the
`properties` table. This is the single source of truth for data ownership.

```
companies
  └── properties            (company_id → companies.id)
        └── departments     (property_id → properties.id)
        └── users           (property_id → properties.id, nullable for super-admin)
        └── rooms           (property_id → properties.id)
        └── zones           (property_id → properties.id)
        └── cleaning_tasks  (property_id → properties.id)
        └── ...all business tables
```

Tables that do **not** carry `property_id`:
- `companies` — tenant root, sits above properties
- `permissions` — global Spatie Permission records
- `roles` — team-scoped via Spatie Permission's team feature

Super-admins are the only users with `property_id = null`. They exist outside
any property context. All other users belong to exactly one property.

---

## 2. BelongsToProperty Trait

`Shared\Traits\BelongsToProperty` is applied to every model that owns a
`property_id` column. It provides two automatic behaviours.

### 2.1 Creating Hook — Auto-assignment

When a model is created **without** an explicit `property_id` in the data, the
trait calls `CurrentPropertyService::resolveOrFail()` to obtain one.

```php
static::creating(function ($model) {
    if (empty($model->property_id)) {
        $model->property_id = app(CurrentPropertyService::class)->resolveOrFail();
    }
});
```

If `property_id` is present in the data it is **never overwritten**. This means
seeders, tests, and any code that passes `property_id` explicitly bypass the
service entirely.

If `property_id` is absent and the service cannot resolve a context, a
`PropertyNotResolvedException` is thrown immediately — the record is never
created with a null `property_id`.

### 2.2 Global Scope — Query Filtering

Every Eloquent query on a `BelongsToProperty` model is automatically filtered
to the current property context.

```php
static::addGlobalScope('property', function (Builder $query) {
    $propertyId = app(CurrentPropertyService::class)->getId();
    if ($propertyId) {
        $query->where($query->getModel()->getTable() . '.property_id', $propertyId);
    }
});
```

If no property context is resolved (e.g., super-admin read path), the scope
adds no clause and the query returns all records across properties. This is
intentional for super-admin operations.

### 2.3 Bypassing the Global Scope

When you need to query across properties (seeders, super-admin tools, reporting):

```php
// Correct — explicitly opt out of the scope
Department::withoutGlobalScope('property')->where('code', 'HK')->first();

// Also correct — use the built-in scope helper
Department::forProperty($propertyId)->get();
```

Never call `Property::all()` or similar in code that needs isolation — you will
get cross-property data.

---

## 3. CurrentPropertyService Resolution Stack

`Shared\Services\CurrentPropertyService` is a **singleton** registered in
`FoundationServiceProvider`. It resolves the active property context through a
three-tier stack, checked in priority order.

```
Tier 1 — Explicit override    setPropertyId($id) / setId($id)
Tier 2 — Session              session('current_property_id')
Tier 3 — Authenticated user   auth()->user()->property_id
```

**Tier 1 — Explicit override** is used by tests, background jobs, and future
property-switcher middleware. It takes absolute precedence.

**Tier 2 — Session** stores the operator's currently selected property when they
have access to multiple properties. It sits above the user's home property,
allowing a multi-property operator to switch context without changing their
account. The session key is `current_property_id`. The UI switcher that writes
this key has not been implemented yet.

**Tier 3 — Authenticated user** is the fallback for normal property-scoped
users. `auth()->user()->property_id` is the user's home property.

If no tier resolves a value, `getPropertyId()` returns `null`.

### API Summary

| Method | Signature | Description |
|--------|-----------|-------------|
| `setPropertyId` | `setPropertyId(?string): void` | Set explicit override; pass null to clear |
| `getPropertyId` | `getPropertyId(): ?string` | Resolve through the 3-tier stack |
| `resolveOrFail` | `resolveOrFail(): string` | Resolve or throw `PropertyNotResolvedException` |
| `clear` | `clear(): void` | Remove the explicit override |
| `setId` | `setId(string): void` | Backward-compatible alias for `setPropertyId` |
| `getId` | `getId(): ?string` | Backward-compatible alias for `getPropertyId` |
| `resolve` | `resolve(): ?string` | Backward-compatible alias for `getPropertyId` |
| `isResolved` | `isResolved(): bool` | Returns true if any tier resolves a value |

---

## 4. resolveOrFail Behaviour

`resolveOrFail()` is used wherever a missing property context is a programming
error rather than a legitimate state.

```php
// Used in BelongsToProperty::creating — auto-assignment must succeed or fail
// fast. A null property_id on a business record is never acceptable.
$model->property_id = app(CurrentPropertyService::class)->resolveOrFail();
```

Throws `Shared\Exceptions\PropertyNotResolvedException`:
- Message: `"Property context could not be resolved."`
- HTTP render: 422 JSON

Do not catch this exception in normal application code. Its presence indicates
a missing context setup, not a user error. Fix the context, not the handler.

---

## 5. Super-Admin Context Rules

Super-admins (`property_id = null`) can act across all properties. The rules
for working with super-admins:

| Scenario | Behaviour |
|----------|-----------|
| Super-admin reads property-scoped model — no context set | Global scope adds no clause; all records visible |
| Super-admin creates property-scoped model — no context set | `resolveOrFail()` throws `PropertyNotResolvedException` |
| Super-admin creates property-scoped model — explicit override set | Model created under the overridden property |
| Super-admin creates property-scoped model — session set | Model created under the session property |
| Normal user reads property-scoped model | Global scope filters to user's property automatically |
| Normal user creates property-scoped model | Auto-assigned from auth user's `property_id` |

**Rule:** Super-admins who need to create records in a specific property must
set the context explicitly before the operation:

```php
app(CurrentPropertyService::class)->setPropertyId($targetPropertyId);
try {
    // create operations here
} finally {
    app(CurrentPropertyService::class)->clear();
}
```

---

## 6. Property-Scoped Validation Rules

Form validation must also enforce property isolation. Referencing a record
from another property through a validated request is a data-boundary violation.

The full rules are documented in:
`docs/Knowledge-Base/04-Governance/validation-hardening-rules.md`

Summary:
- Every `Rule::exists()` on a property-scoped table must include `.where('property_id', $propertyId)`
- Every `Rule::unique()` on a property-scoped table must include `.where('property_id', $propertyId)` or the string equivalent `property_id,{$propertyId}`
- Both must also apply soft-delete filtering where the table uses `SoftDeletes`

---

## 7. Seeder Rules

Seeders run without an HTTP request context. `CurrentPropertyService` has no
resolved value. All seeders must:

1. Pass `property_id` explicitly in every `Model::create()` call.
2. Use `Model::withoutGlobalScope('property')` when querying property-scoped
   records (e.g., looking up a property by code).

Full rules are documented in: `docs/Governance/seeder-contract.md`

---

## Invariants — Never Violate These

- A business record must never have `property_id = null` in production.
- `CurrentPropertyService` must never be bypassed for context resolution.
- The `BelongsToProperty` trait must be applied to every model with a `property_id` column.
- Cross-property data access must go through `withoutGlobalScope` explicitly, never silently.
- `resolveOrFail()` exceptions must surface — never swallow them.
