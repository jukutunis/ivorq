# Validation Hardening Rules

Project: IVORQ Hotel Operations Platform
Version: 1.0 — Sprint 03.2 Hardening
Status: Active

---

## Purpose

This document defines mandatory rules for writing validation rules in FormRequest
classes across all IVORQ modules. These rules close a class of property-isolation
bugs where a malicious or misconfigured request can reference data belonging to
another property.

Every rule in this document is **non-negotiable**. Violations are security defects,
not style preferences.

---

## Background — Why Validation Must Enforce Property Scope

The `BelongsToProperty` trait's global scope prevents cross-property data being
*read* through Eloquent queries. But Laravel's string-form `exists:table,column`
validation rule **does not use Eloquent** — it issues a raw database query that
bypasses global scopes entirely.

This means without explicit property constraints in validation:

```php
// Attacker sends zone_id belonging to Property B while authenticated in Property A
// exists:zones,id passes — the zone exists in the database
// The cleaning task is created referencing a zone from another property
'zone_id' => ['nullable', 'exists:zones,id'],  // WRONG — no property scope
```

The service layer would then associate Property A data with Property B's zone,
creating a cross-property data leak.

The fix is to enforce property scope **at the validation layer** using
`Rule::exists()` and `Rule::unique()` fluent builders.

---

## Rule 1 — Exists on Property-Scoped Tables Must Be Property-Scoped

**Rule:** Any `Rule::exists()` (or string `exists:`) on a table that has a
`property_id` column must add a `property_id` constraint.

**Wrong:**

```php
'zone_id' => ['nullable', 'exists:zones,id'],
'room_id' => ['required', 'exists:rooms,id'],
```

**Correct:**

```php
$propertyId = app(CurrentPropertyService::class)->getId();

'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('property_id', $propertyId)],
'room_id' => ['required', Rule::exists('rooms', 'id')->where('property_id', $propertyId)],
```

The `propertyId` must be obtained from `CurrentPropertyService::getId()` at the
top of the `rules()` method. Do not hardcode or read it from the request body.

---

## Rule 2 — Exists on Soft-Deletable Tables Must Exclude Deleted Records

**Rule:** Any `Rule::exists()` on a table that uses `SoftDeletes` must add
`->whereNull('deleted_at')`.

**Wrong:**

```php
'room_id' => ['required', Rule::exists('rooms', 'id')->where('property_id', $propertyId)],
// rooms uses SoftDeletes — a deleted room would still pass validation
```

**Correct:**

```php
'room_id' => ['required', Rule::exists('rooms', 'id')
    ->where('property_id', $propertyId)
    ->whereNull('deleted_at')],
```

When both rules apply (table has `property_id` AND uses `SoftDeletes`), both
constraints are required on the same rule.

---

## Rule 3 — Unique on Property-Scoped Entities Must Be Property-Scoped

**Rule:** Any `Rule::unique()` on a table that is property-owned must scope
uniqueness to `property_id`. Global uniqueness checks on property-scoped data
are incorrect — the same code (e.g., `HK`) is valid in both Property A and
Property B.

**Wrong:**

```php
'code' => ['required', 'unique:departments,code'],
// Blocks "HK" in Property B because Property A already has "HK"
```

**Correct (string form):**

```php
$propertyId = app(CurrentPropertyService::class)->getId();

'code' => ['required', "unique:departments,code,NULL,id,property_id,{$propertyId},deleted_at,NULL"],
```

**Correct (fluent form — preferred for update requests):**

```php
$departmentId = $this->route('department');
$propertyId   = app(CurrentPropertyService::class)->getId();

'code' => ['sometimes', Rule::unique('departments', 'code')
    ->ignore($departmentId)
    ->where('property_id', $propertyId)
    ->whereNull('deleted_at')],
```

---

## Rule 4 — Unique on Soft-Deletable Entities Must Exclude Deleted Records

**Rule:** Any `Rule::unique()` where the entity uses `SoftDeletes` must exclude
soft-deleted records from the uniqueness check. A soft-deleted record's code
or name should not block a new record from using the same value.

The `deleted_at,NULL` trailer in the string form calls `whereNull('deleted_at')`
in the underlying `DatabasePresenceVerifier`. Both forms are equivalent:

```php
// String form — appending deleted_at,NULL triggers whereNull() inside Laravel
"unique:rooms,room_number,NULL,id,property_id,{$propertyId},deleted_at,NULL"

// Fluent form — explicit and readable
Rule::unique('rooms', 'room_number')
    ->where('property_id', $propertyId)
    ->whereNull('deleted_at')
```

**Models with SoftDeletes — always include soft-delete filter in unique rules:**

| Table | SoftDeletes | Required filter |
|-------|-------------|-----------------|
| `departments` | Yes | `deleted_at,NULL` |
| `properties` | Yes | `deleted_at,NULL` |
| `rooms` | Yes | `deleted_at,NULL` |
| `cleaning_tasks` | Yes | `deleted_at,NULL` |
| `cleaning_checklists` | Yes | `deleted_at,NULL` |
| `zones` | Yes | `deleted_at,NULL` |
| `zone_templates` | Yes | `deleted_at,NULL` |
| `users` | Yes | `whereNull('deleted_at')` |
| `positions` | No | Not required |
| `checklist_items` | No | Not required |

---

## Rule 5 — The Users Table Exception

**Rule:** `exists:users,id` must include `whereNull('deleted_at')` but must
**not** include a `property_id` constraint.

Users have a `property_id` column, but it is **nullable**. Super-admins
(`property_id = null`) are legitimate system actors who can be assigned as
inspectors, zone managers, or task supervisors in any property. Scoping
`exists:users,id` to `property_id` would incorrectly reject super-admin
assignments.

**Wrong:**

```php
// Blocks super-admin assignments
'user_id' => ['required', Rule::exists('users', 'id')->where('property_id', $propertyId)],

// Accepts soft-deleted users as valid assignees
'user_id' => ['required', 'exists:users,id'],
```

**Correct:**

```php
// Excludes deleted users, but does not scope to property_id
'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
```

---

## Rule 6 — Permissions and Global Tables Are Not Property-Scoped

**Rule:** `exists:permissions,name` and similar global Spatie Permission tables
must not have a `property_id` constraint added. These tables have no
`property_id` column and are global by design.

```php
// Correct — permissions are global
'permissions.*' => ['string', 'exists:permissions,name'],
```

---

## Complete Reference: IVORQ Exists Rules (Sprint 03.2 State)

| Field | Table | Has property_id | Has SoftDeletes | Required scope |
|-------|-------|-----------------|-----------------|----------------|
| `company_id` | `companies` | No | Yes | `whereNull('deleted_at')` only |
| `department_id` | `departments` | Yes | Yes | `property_id` + `deleted_at` |
| `position_id` | `positions` | Yes | No | `property_id` only |
| `zone_id` | `zones` | Yes | Yes | `property_id` + `deleted_at` |
| `room_id` | `rooms` | Yes | Yes | `property_id` + `deleted_at` |
| `cleaning_task_id` | `cleaning_tasks` | Yes | Yes | `property_id` + `deleted_at` |
| `checklist_items.*` | `checklist_items` | Yes | No | `property_id` only |
| `user_id` / `inspector_id` | `users` | Nullable | Yes | `deleted_at` only (see Rule 5) |
| `permissions.*` | `permissions` | No | No | No constraint |

---

## Checklist for Every New FormRequest

When writing a new FormRequest, verify each rule:

- [ ] `rule(): array` starts by resolving `$propertyId = app(CurrentPropertyService::class)->getId()`
- [ ] Every `exists:` on a property-scoped table uses `Rule::exists()->where('property_id', ...)`
- [ ] Every `exists:` on a soft-deletable table uses `->whereNull('deleted_at')`
- [ ] Every `unique:` on a property-scoped entity includes a `property_id` constraint
- [ ] Every `unique:` on a soft-deletable entity includes a `deleted_at,NULL` filter
- [ ] Update requests use `Rule::unique()->ignore($id)` with property scope and soft-delete filter
- [ ] `user_id` / `inspector_id` fields use `whereNull('deleted_at')` only, not `where('property_id', ...)`
- [ ] `exists:permissions,name` has no property scope

---

## How to Test Validation Hardening

Tests that verify property-scope enforcement follow this pattern:

```php
// 1. Create context for Property A
$propA   = $this->createProperty($company);
$adminA  = $this->createPropertyAdmin($propA);
$zoneA   = $this->createZone($propA);

// 2. Create a zone in Property B
$propB   = $this->createProperty($company, ['code' => 'PB']);
$zoneB   = $this->createZone($propB);

// 3. Attempt to use Property B's zone in a Property A request
app(CurrentPropertyService::class)->setId($propA->id);
$this->actingAs($adminA)
    ->post('/rooms', ['zone_id' => $zoneB->id, ...])
    ->assertSessionHasErrors('zone_id');  // Must reject cross-property reference
```

A test that does NOT verify the zone is from the correct property is incomplete
for security purposes.

---

## Relationship to Seeder Rules

Seeders do not use FormRequests. The rules in this document apply exclusively to
HTTP-layer validation. For seeder-specific rules around `property_id`, see:
`docs/Governance/seeder-contract.md`
