# Security Architecture

Project: IVORQ Hotel Operations Platform
Version: 1.0 — Sprint 03.2 Hardening
Status: Active

---

## Overview

IVORQ's security model has five concentric layers. Each must hold independently.
A failure in one layer must not allow access through the others.

```
Layer 1 — Authentication          (Sanctum — who are you?)
Layer 2 — Authorisation           (Spatie Permission + Policies — what can you do?)
Layer 3 — Property Isolation      (BelongsToProperty + CurrentPropertyService — whose data?)
Layer 4 — Input Validation        (FormRequests — is the input valid?)
Layer 5 — Observability           (Audit + Activity logs — what happened?)
```

---

## Layer 1 — Authentication (Sanctum)

IVORQ uses **Laravel Sanctum** for stateful web authentication via session
cookies (SPA mode). API tokens are supported for external integrations.

Key points:
- All routes are protected by `auth:sanctum` middleware
- Unauthenticated requests are redirected to `/login` (web) or receive 401 (API)
- Sessions are tied to a property context — the `current_property_id` session
  key is available for property-switcher functionality
- Token-based API access uses the same policy and scope enforcement as session
  access — there is no separate, less-restricted API mode

Password reset uses Laravel's built-in token flow. Passwords are hashed with
bcrypt via `password` cast on the `User` model.

---

## Layer 2 — Authorisation (Spatie Permission + Policies)

### Spatie Permission with Team Scope

IVORQ uses **Spatie Permission v6** with the **team feature** enabled. Each
`property_id` is treated as a team ID. Permissions and roles are always
evaluated in the context of a specific property.

```php
setPermissionsTeamId($property->id);
$user->hasPermissionTo('room.create'); // checks for property context
```

This means a user with `housekeeping-manager` role in Property A does **not**
automatically have any role in Property B.

Super-admins are assigned roles with `team_id = null`, granting cross-property
authority.

### Roles (Sprint 03)

| Role | Scope | Description |
|------|-------|-------------|
| `super-admin` | Global | Full access across all properties |
| `property-admin` | Property | Full access within their property |
| `housekeeping-manager` | Property | Manages housekeeping operations |
| `staff` | Property | Read access to assigned operational areas |

### Policies

Every model has a corresponding Policy class. Controllers use `$this->authorize()`
or FormRequest `authorize()` methods. Direct permission checks are used where
policy context is insufficient.

| Module | Policy | Covers |
|--------|--------|--------|
| Foundation | `PropertyPolicy` | Company and property CRUD |
| Foundation | `DepartmentPolicy` | Department management |
| Foundation | `UserPolicy` | User management |
| Foundation | `RolePolicy` | Role and permission assignment |
| Zoning | `ZonePolicy` | Zone lifecycle |
| Zoning | `ZoneAssignmentPolicy` | Zone staff assignments |
| Zoning | `ZoneTemplatePolicy` | Zone template management |
| Housekeeping | `RoomPolicy` | Room lifecycle |
| Housekeeping | `CleaningTaskPolicy` | Task management |
| Housekeeping | `ChecklistPolicy` | Checklist management |
| Housekeeping | `RoomInspectionPolicy` | Inspection lifecycle |
| Housekeeping | `TaskAssignmentPolicy` | Task staff assignments |

### Rules for Policy Implementation

- Every `viewAny` checks `property.view` or equivalent permission
- Every `create` checks the appropriate `*.create` permission
- Every `update` and `delete` checks ownership (same `property_id`) before
  checking the permission
- Super-admin always returns `true` from `before()` hook
- Policies never bypass the property scope check — a property-admin from
  Property A cannot update records in Property B

---

## Layer 3 — Property Isolation

Handled entirely by `BelongsToProperty` and `CurrentPropertyService`.

Full documentation: `docs/Knowledge-Base/02-Architecture/multi-tenancy-strategy.md`

Key invariants for security:
- The global scope on every `BelongsToProperty` model prevents cross-property
  data leakage in all read operations, without requiring every query to add an
  explicit `WHERE` clause
- `resolveOrFail()` prevents property-scoped records from being created without
  a known context — eliminates orphaned records at the point of creation
- Property isolation is enforced at three points (query scope, validation,
  service layer) — a bypass at one point does not expose data through the others

---

## Layer 4 — Input Validation Hardening

All input is validated at the HTTP boundary using FormRequest classes. Input
validation is the last line of defence against cross-property injection attacks
at the application level.

### Validation Rules (enforced as of Sprint 03.2)

**Existence checks** (`Rule::exists`) on property-scoped tables must include a
`property_id` constraint and a soft-delete filter:

```php
Rule::exists('rooms', 'id')
    ->where('property_id', $propertyId)
    ->whereNull('deleted_at')
```

**Uniqueness checks** (`Rule::unique`) on property-scoped tables must include a
`property_id` constraint and a soft-delete filter:

```php
Rule::unique('rooms', 'room_number')
    ->where('property_id', $propertyId)
    ->whereNull('deleted_at')
```

Without these constraints:
- A malicious request could reference a room from a different property
- A soft-deleted room number could block creation of a legitimate new room

Full rules and table-by-table coverage:
`docs/Knowledge-Base/04-Governance/validation-hardening-rules.md`

---

## Layer 5 — Observability

### Audit Log

`Shared\Observers\AuditObserver` (via `HasAuditColumns` trait) records every
`created`, `updated`, and `deleted` event on audited models.

The `audit_logs` table captures:
- `model_type` and `model_id` — which record changed
- `property_id` — which property owns the record
- `user_id` — who made the change
- `event` — `created`, `updated`, `deleted`
- `old_values` / `new_values` — JSON diff
- `ip_address` and `user_agent`

Audit logs are **permanent** (no `SoftDeletes`, no TTL). They are never
modified after creation.

### Activity Log

`ActivityService` records user-facing operational events that are meaningful to
property managers (e.g., "Room 101 checked out", "Zone A assigned to John").

The `activity_logs` table captures:
- `property_id` — property context
- `user_id` — who triggered the event
- `subject_type` / `subject_id` — the affected entity
- `description` — human-readable event description
- `metadata` — JSON payload for UI rendering

Activity logs support the operational dashboards. They are also permanent records.

### HasAuditColumns Trait

`Shared\Traits\HasAuditColumns` auto-sets `created_by` and `updated_by` on
every model write, recording which authenticated user performed the change.
This is complementary to (not a replacement for) the full audit log.

---

## Security Checklist for New Modules

When adding a new module, verify each item:

- [ ] All routes use `auth:sanctum` middleware
- [ ] Every controller action calls `$this->authorize()` or uses an authorizing FormRequest
- [ ] A Policy class exists and is registered in the module's ServiceProvider
- [ ] Every model with a `property_id` column uses `BelongsToProperty` trait
- [ ] Every `Rule::exists()` on a property-scoped table includes `property_id` scope and soft-delete filter
- [ ] Every `Rule::unique()` on a property-scoped entity includes `property_id` scope and soft-delete filter
- [ ] Events are fired after state changes so audit listeners can record them
- [ ] The model uses `HasAuditColumns` for `created_by` / `updated_by` tracking
- [ ] No business logic is in controllers or models
- [ ] No raw DB queries bypass the property scope

---

## What IVORQ Does Not Do

- **Row-level encryption** — data isolation is logical (scoped queries), not cryptographic
- **Separate database per tenant** — single database, property-scoped tables
- **Cross-property shared roles** — all roles are property-scoped via Spatie team feature
- **Public API without authentication** — no unauthenticated endpoints exist in the current scope
