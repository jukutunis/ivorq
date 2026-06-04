# Module Dependency Map

Project: IVORQ Hotel Operations Platform
Version: 1.0 — Sprint 03.2 Hardening
Status: Active

---

## Overview

IVORQ is organised into a layered module hierarchy. Modules higher in the
dependency graph provide foundational services consumed by lower modules.
**No circular dependencies are permitted.** A downstream module may import
from any upstream module, but an upstream module must never import from a
downstream module.

---

## Dependency Hierarchy

```
┌─────────────────────────────────────────────────────────────────┐
│                        FOUNDATION                               │
│                                                                 │
│  Property ──► Department ──► User ──► Authorization             │
│      │                         │           │                    │
│      └──► Authentication       └──► Audit ─┘                   │
│                                     Activity                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    SHARED OPERATIONAL CONTEXT                   │
│                                                                 │
│  Operations/Zoning                                              │
│    Zone ◄── ZoneTemplate                                        │
│    Zone ──► ZoneAssignment (references User, Department)        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         OPERATIONS                              │
│                                                                 │
│  Operations/Housekeeping                                        │
│    Room ──► Zone (from Zoning)                                  │
│    CleaningTask ──► Room, Zone                                  │
│    CleaningChecklist ──► ChecklistItem                          │
│    TaskAssignment ──► CleaningTask, User, Department            │
│    RoomInspection ──► Room, CleaningTask, User                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       FUTURE MODULES                            │
│                                                                 │
│  Engineering / Guest Request / PMS / POS / HRIS / Finance       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Module Descriptions

### Foundation (upstream root)

The Foundation module is a **prerequisite for all other modules**. It provides
the core identity and access infrastructure. No other module may be built or
run without Foundation being registered first.

Sub-module boot order is enforced in `FoundationServiceProvider`:

```
PropertyServiceProvider          (no business dependencies)
DepartmentServiceProvider        (depends on: Property)
UserServiceProvider              (depends on: Property, Department)
AuthenticationServiceProvider    (depends on: User)
AuthorizationServiceProvider     (depends on: User)
AuditServiceProvider             (depends on: User)
ActivityServiceProvider          (depends on: User, Property)
```

**Foundation provides:**
- Company and Property management (tenant root)
- Department and Position management (org structure)
- User management with property-scoped role assignment
- Authentication (login, logout, password reset via Sanctum)
- Role and Permission management (Spatie Permission, team-scoped)
- Audit log engine (permanent model lifecycle records)
- Activity log engine (user-facing operational event feed)
- `CurrentPropertyService` (singleton — shared context resolver)
- `BelongsToProperty` trait (auto property_id + global scope)
- `HasUlid`, `HasAuditColumns` shared traits

**Foundation must not import from:** Operations, Zoning, Housekeeping, PMS,
POS, HRIS, or any future domain module.

---

### Operations/Zoning (shared operational context)

Zoning is a **shared operational layer** consumed by Housekeeping and all
future operations modules that deal with physical space assignments.

**Zoning provides:**
- Zone lifecycle management (draft → active → inactive)
- ZoneTemplate management (preconfigured zone definitions)
- ZoneAssignment — links staff (User + Department) to zones with date ranges
- Zone status history (permanent, immutable log)

**Zoning depends on:**
- Foundation/Property (zone belongs to a property)
- Foundation/Department (zone assignments reference departments)
- Foundation/User (zone assignments reference users)

**Zoning must not import from:** Housekeeping or any future downstream module.

---

### Operations/Housekeeping (downstream consumer)

Housekeeping is the first operational module. It consumes both Foundation and
Zoning.

**Housekeeping provides:**
- Room management (room lifecycle, cleanliness and occupancy status)
- Cleaning task management (task creation, assignment, status workflow)
- Cleaning checklist and checklist item management
- Task assignment (staff to task)
- Room inspection (pass/fail/pending)
- Room status history (permanent, immutable log)

**Housekeeping depends on:**
- Foundation/Property, Department, User (all for property context, org structure)
- Operations/Zoning — rooms and tasks reference Zones

**Housekeeping must not import from:** any future module (Engineering, PMS, etc.).

---

### Future Modules — Dependency Direction

All future modules follow the same upstream-only import rule.

| Future Module | Direct Dependencies |
|---------------|---------------------|
| Engineering | Foundation, Zoning, Housekeeping (shared room/task context) |
| Guest Request | Foundation, PMS (guest context), Housekeeping (room context) |
| PMS | Foundation (property, user, department) |
| POS | Foundation, PMS (guest context for billing) |
| HRIS | Foundation (user, department as employee core) |
| Finance | Foundation, POS (transaction data), HRIS (payroll) |
| Inventory | Foundation, Engineering (consumption tracking) |
| Purchasing | Foundation, Inventory (purchase order context) |

**Rule:** When a future module needs data from another operational module, it
must consume it by referencing the other module's models as foreign keys, not
by embedding logic from that module. Service calls must not cross module
boundaries — use events and listeners for cross-module reactions.

---

## Dependency Rules

### No Circular Dependencies

```
Allowed:       Housekeeping → Zoning → Foundation
Not allowed:   Zoning → Housekeeping
Not allowed:   Foundation → Operations/*
Not allowed:   Any module → a module at the same level unless explicitly listed
```

Circular dependencies cause boot order failures and make it impossible to test
modules in isolation.

### Cross-Module Communication — Events Only

When one module needs to react to something that happened in another module,
use Laravel events:

- The **source module** fires an event (e.g., `RoomStatusChanged`)
- The **consumer module** registers a listener for that event in its own
  ServiceProvider

The source module does not know the consumer exists. The consumer does not
reach into the source module's services.

```
Housekeeping fires:  RoomStatusChanged
Engineering listens: updates maintenance ticket priority
```

This keeps modules independently deployable and testable.

### Shared Infrastructure — Shared\\ Namespace

Shared traits, exceptions, contracts, and services live in `Shared\\`:

```
Shared\Services\CurrentPropertyService
Shared\Traits\BelongsToProperty
Shared\Traits\HasUlid
Shared\Traits\HasAuditColumns
Shared\Exceptions\NotFoundException
Shared\Exceptions\UnauthorizedException
Shared\Exceptions\PropertyNotResolvedException
Shared\Contracts\RepositoryInterface
```

`Shared\\` is not a module. It has no ServiceProvider, no HTTP layer, and no
database migrations. It is a cross-cutting infrastructure package. Any module
may import from `Shared\\`.

---

## Current Module Status (v0.3-sprint03-complete)

| Module | Status | Notes |
|--------|--------|-------|
| Foundation/Property | Complete | Company + property management |
| Foundation/Department | Complete | Department + position management |
| Foundation/User | Complete | User management |
| Foundation/Authentication | Complete | Login/logout/password |
| Foundation/Authorization | Complete | Roles + permissions |
| Foundation/Audit | Complete | Audit log engine |
| Foundation/Activity | Complete | Activity log engine |
| Operations/Zoning | Complete | Zone lifecycle + assignments |
| Operations/Housekeeping | Complete | Room + task + inspection + checklist |
| Engineering | Planned — Sprint 03 |  |
| Guest Request | Planned — Sprint 06 |  |
| Inventory | Planned — Sprint 04 |  |
| Purchasing | Planned — Sprint 05 |  |
| PMS | Future |  |
| POS | Future |  |
| HRIS | Future |  |
| Finance | Future |  |

---

## Registration Pattern

Every module registers itself through a ServiceProvider hierarchy:

```
AppServiceProvider
  └── FoundationServiceProvider
        ├── PropertyServiceProvider
        ├── DepartmentServiceProvider
        ├── ...
  └── OperationsServiceProvider
        ├── ZoningServiceProvider
        └── HousekeepingServiceProvider
```

Registrations include: service bindings, repository bindings, policy mappings,
observer registration, and event listener registration. No module-level code
runs outside of its ServiceProvider's `register()` and `boot()` methods.
