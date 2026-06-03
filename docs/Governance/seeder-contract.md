# Seeder Contract Rules

Project: IVORQ Hotel Operations Platform
Version: 1.0
Owner: Architecture Team

---

## Purpose

This document defines the mandatory rules for writing database seeders in IVORQ.
All seeders must follow these rules without exception.

---

## Rule 1 — Always Pass property_id Explicitly

**Rule:** Every seeder that creates records in a business table must pass
`property_id` as an explicit value in the `create()` call.

**Why:**

The `BelongsToProperty` trait adds a global scope and a `bootBelongsToProperty()`
hook that auto-sets `property_id` from `CurrentPropertyService::getId()`.

Inside a seeder, there is no authenticated user and no HTTP request context.
`CurrentPropertyService::getId()` returns null in this state.

If you rely on the trait to set `property_id` automatically, the column will be
null, the unique constraint will fail or the record will be orphaned, and the
seeder will silently produce wrong data.

**Correct:**

```php
Department::create([
    'property_id' => $property->id,  // Always explicit in seeders
    'name'        => 'Housekeeping',
    'code'        => 'HK',
    'is_active'   => true,
]);
```

**Wrong:**

```php
// property_id is missing — BelongsToProperty cannot resolve it in seeder context
Department::create([
    'name'      => 'Housekeeping',
    'code'      => 'HK',
    'is_active' => true,
]);
```

---

## Rule 2 — Use withoutGlobalScope('property') When Reading Parent Records

**Rule:** When a seeder needs to look up a record that uses `BelongsToProperty`,
always use `withoutGlobalScope('property')` on the query.

**Why:**

The `BelongsToProperty` global scope adds `WHERE property_id = ?` to every query.
In a seeder context, `CurrentPropertyService::getId()` returns null, so the scope
injects `WHERE property_id = null`. This causes the query to return nothing even
when the record exists.

**Correct:**

```php
$property = Property::withoutGlobalScope('property')
    ->where('code', 'IGH')
    ->first();
```

**Wrong:**

```php
$property = Property::where('code', 'IGH')->first(); // Returns null in seeder
```

---

## Rule 3 — Seeder Execution Order Must Match Foreign Key Dependency Order

**Rule:** Register seeders in `DatabaseSeeder` in strict dependency order.
Upstream tables must be seeded before downstream tables.

**Required order for Sprint 01:**

```
PermissionSeeder        (no dependencies)
RoleSeeder              (depends on: permissions)
PropertySeeder          (no business dependencies)
DepartmentSeeder        (depends on: properties)
SuperAdminSeeder        (depends on: roles, properties)
```

**Why:**

Foreign key constraints are enforced by PostgreSQL. Seeding in the wrong order
will throw a foreign key violation and abort the entire seeder run.

---

## Rule 4 — Guard Early if Dependency Is Missing

**Rule:** Seeders that depend on records from other seeders must guard against
missing data with an early return or a clear exception.

**Correct:**

```php
public function run(): void
{
    $property = Property::withoutGlobalScope('property')
        ->where('code', 'IGH')
        ->first();

    if (! $property) {
        // PropertySeeder must run first — nothing to seed without a property.
        return;
    }

    // ... create departments
}
```

**Why:**

If a seeder is run in isolation (e.g., `artisan db:seed --class=DepartmentSeeder`)
without its dependencies present, it will fail with a cryptic null reference error
instead of a clear message. An early guard produces a no-op instead of a crash.

---

## Rule 5 — Never Use Factories in Seeders for Production-Critical Data

**Rule:** Use explicit `Model::create([...])` calls for production baseline data
(roles, permissions, departments, the default property). Reserve factories for
test data only.

**Why:**

Factories use Faker and generate random values. Seeding a production database
with random role names or department codes creates non-deterministic state that
cannot be reproduced, diffed, or rolled back cleanly.

---

## Rule 6 — Seeders Are Idempotent Where Possible

**Rule:** Use `firstOrCreate()` instead of `create()` for records that should
exist exactly once (permissions, roles, the default property).

**Correct:**

```php
Permission::firstOrCreate(['name' => 'department.view', 'guard_name' => 'web']);
```

**Why:**

This allows `artisan db:seed` to be re-run without duplicating data. It also
makes it safe to run individual seeders during development without first
truncating tables.

---

## Summary Table

| Rule | Applies To | Consequence if Broken |
|---|---|---|
| Pass `property_id` explicitly | All business table seeders | `property_id = null`, orphaned records |
| Use `withoutGlobalScope('property')` on reads | Any seeder querying a scoped model | Query returns null silently |
| Respect dependency order in `DatabaseSeeder` | `DatabaseSeeder::run()` | Foreign key violation, seeder abort |
| Guard against missing dependencies | Cross-seeder dependencies | Null reference exception |
| No factories for production data | Roles, permissions, baseline property | Non-deterministic production state |
| Idempotent with `firstOrCreate` | Singleton records | Duplicate key error on re-run |
