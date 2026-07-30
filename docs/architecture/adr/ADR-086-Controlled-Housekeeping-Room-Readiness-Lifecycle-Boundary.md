# ADR-086: Controlled Housekeeping Room Readiness Lifecycle Boundary

## ADR Metadata
* **ADR Number:** ADR-086
* **ADR Title:** Controlled Housekeeping Room Readiness Lifecycle Boundary
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-029 (Security Roles and Permissions Governance), ADR-030 (Identity Authentication and Session Governance), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-084 (Controlled Front Desk Arrival, Stay, and Room Assignment Boundary), ADR-085 (Engineering Room Availability and Block Evidence Boundary)

## Context

The Housekeeping module owns room cleanliness and readiness as the source of truth for operational room state. Currently, Front Desk reads `readiness_state` and `cleanliness_status` directly from the Room model, bypassing Housekeeping ownership. Transition evidence is scattered across CleaningTask, RoomInspection, and RoomStatusHistory models. A unified Housekeeping-owned readiness boundary with controlled transition evidence and projection service is needed to ensure that Front Desk, Engineering, and Finance consumers respect Housekeeping ownership without mutation.

## Decision

### Ownership

Housekeeping owns:
- room cleanliness/readiness state;
- cleaning work evidence;
- inspection readiness evidence;
- room release-to-ready evidence;
- Housekeeping operational workboard;
- read-only readiness projection for Front Desk.

Housekeeping does not own:
- Engineering technical room blocks;
- Front Desk room assignment;
- Front Desk check-in;
- Front Desk room move;
- final checkout;
- room master configuration beyond accepted readiness fields;
- folio;
- deposit;
- payment;
- revenue;
- tax;
- Accounts Receivable;
- General Ledger;
- Night Audit;
- Cashier;
- Banking;
- Financial Period;
- Business Date.

### Canonical Source Ownership Matrix

| Domain Fact | Owner Module | Consumer Read | Consumer Mutate |
|---|---|---|---|
| Property / Tenant identity | Foundation Property | All | None |
| Room identity, number, type | Housekeeping Room | All | Housekeeping only |
| Room cleanliness_status | Housekeeping | Read via projection | HousekeepingTransitionService only |
| Room readiness_state | Housekeeping | Read via projection | HousekeepingTransitionService only |
| Housekeeping transition evidence | Housekeeping | Read via HK services | HK TransitionService only |
| Cleaning task workflow | Housekeeping | HK workspace | HK services |
| Inspection workflow | Housekeeping | HK workspace | HK services |
| Engineering availability block | Engineering | Read via Engineering projection | Engineering alone |
| Front Desk assignment / stay | FrontDesk | FD workspace | FD alone |
| Reservation / Guest | PMS | All read | PMS alone |
| Folio / Payment / Revenue / Tax / AR / GL | Finance | Finance workspace | Finance alone |
| Night Audit / Business Date | Finance | Finance workspace | Finance alone |

### Readiness Status Semantics

Housekeeping room readiness is projected for consumers using three deterministic statuses:

```
HOUSEKEEPING_READY:
  readiness_state in [ready_for_sale, ready_for_arrival, ready_for_vip]
  cleanliness_status is 'inspected'

HOUSEKEEPING_BLOCKED:
  readiness_state in [waiting_cleaning, cleaning, waiting_inspection, blocked]
  or cleanliness_status in [dirty, clean]

HOUSEKEEPING_UNKNOWN:
  room missing, inactive, cross-property, or ambiguous
```

Front Desk may treat only `HOUSEKEEPING_READY` as assignment / room move / check-out readiness eligible. `HOUSEKEEPING_BLOCKED` and `HOUSEKEEPING_UNKNOWN` are blocking.

### Controlled Transition Lifecycle

The lifecycle is narrow and operational, controlled via `HousekeepingRoomReadinessTransitionService`:

```text
DIRTY / WAITING_CLEANING → CLEANING → WAITING_INSPECTION → READY
```

Transition types:

| Type | From | To | Permission | Sensitive Confirmation |
|---|---|---|---|---|
| START_CLEANING | waiting_cleaning | cleaning | housekeeping.room-readiness.clean | No |
| SUBMIT_INSPECTION | cleaning | waiting_inspection | housekeeping.room-readiness.submit-inspection | No |
| RELEASE_READY | waiting_inspection | ready_for_sale | housekeeping.room-readiness.release-ready | Yes |

### State Transition Policy

- START_CLEANING: Only allowed from `readiness_state` = `waiting_cleaning`. Server resolves active property, room identity, actor permission, idempotency key, audit evidence. Browser must not control property, room, actor, or timestamp.
- SUBMIT_INSPECTION: Only allowed from `readiness_state` = `cleaning`. Server resolves active property, room identity, actor permission, idempotency key, audit evidence.
- RELEASE_READY: Only allowed from `readiness_state` = `waiting_inspection`. Requires sensitive confirmation via `housekeeping-room-release-ready` intent. Server re-resolves all values at execution time and compares to confirmation evidence hash.

### Transition Evidence Aggregate

The `HousekeepingRoomReadinessTransition` is an append-only immutable evidence record:

Minimum fields:
- `id` (ULID)
- `property_id`
- `room_id`
- `from_status` (readiness_state before transition)
- `to_status` (readiness_state after transition)
- `transition_type` (START_CLEANING, SUBMIT_INSPECTION, RELEASE_READY)
- `reason` (nullable)
- `source_type` (nullable)
- `source_id` (nullable)
- `occurred_at` (server-owned timestamp)
- `created_by`
- `idempotency_key` (unique per property)
- `source_hash` (SHA-256 of all transition evidence)

Uniqueness:
- `property_id + idempotency_key` → at most one transition outcome
- `property_id + room_id + source_hash` → at most one equivalent transition outcome

Immutability:
- Application-level update/delete blocked in model booted()
- PostgreSQL UPDATE trigger blocks changes
- PostgreSQL DELETE trigger blocks deletion
- No `updated_at` column; `public const UPDATED_AT = null`

### Authorization Boundary

Permissions:

| Permission | Grant | Consumer |
|---|---|---|
| `housekeeping.room-readiness.view` | View readiness projection | Housekeeping actors |
| `housekeeping.room-readiness.clean` | Start cleaning transition | Housekeeping cleaners |
| `housekeeping.room-readiness.submit-inspection` | Submit for inspection | Housekeeping cleaners |
| `housekeeping.room-readiness.release-ready` | Release room ready | Housekeeping inspectors |
| `frontdesk.housekeeping-readiness.view` | Read readiness projection | Front Desk actors |

Rules:
- Housekeeping users may view/clean/submit/release only with exact permission.
- Front Desk users may only read Housekeeping readiness projection.
- Engineering users do not gain Housekeeping release authority.
- Finance, GL, AP, General Cashier, Banking, Tax, AR, Revenue, Night Audit roles do not gain Housekeeping transition authority.
- Housekeeping readiness permissions do not grant Front Desk assignment, check-in, room move, checkout readiness, final checkout, payment, or folio authority.

### Cleaner / Inspector Segregation Policy

The actor who submits cleaning (`housekeeping.room-readiness.submit-inspection`) should not release the same room as ready (`housekeeping.room-readiness.release-ready`) when the repository has actor identity to enforce cleaner/inspector segregation.

Status: **DEFERRED_MAKER_CHECKER_POLICY**

The current role model does not yet enforce distinct cleaner and inspector roles at the permission level. This is recorded as deferred and must not be faked.

### Sensitive Confirmation Policy

RELEASE_READY requires sensitive confirmation via the `housekeeping-room-release-ready` intent.

At confirmation issuance:
- Bind `property_id`, `room_id`, `current readiness_status`, `idempotency_context`, and optional `commercial_evidence_hash`.

At execution:
- Server re-resolves and compares all bound values to current values.
- If evidence hash mismatch, fails closed.
- On success, invalidates the confirmation immediately.

### Idempotency

- All transition mutations accept a client-supplied `idempotency_key`.
- Duplicate idempotency keys with matching parameters return the existing outcome.
- Duplicate idempotency keys with different parameters fail closed with `DomainException`.
- `source_hash` (SHA-256 of all evidence) provides a secondary uniqueness guard.

### Audit Evidence

Every transition creates:
- One immutable `HousekeepingRoomReadinessTransition` record.
- One `AuditLog` entry recording actor, property, room, transition type, from/to status, idempotency key, and source hash.

### Concurrency / Lock Policy

- Room row locked with `FOR UPDATE` during all transition mutations.
- Transition evidence uniqueness enforced via PostgreSQL unique indexes.
- Two concurrent START_CLEANING attempts for the same room: one succeeds, one fails.
- Two concurrent RELEASE_READY attempts for the same room: one succeeds, one fails closed.
- Concurrency proof must demonstrate distinct OS PIDs, PostgreSQL backend PIDs, row-lock contention, and zero orphan evidence.

### Front Desk Read-Only Dependency Boundary

Front Desk must consume Housekeeping readiness only through `HousekeepingReadinessDependencyService` → `HousekeepingRoomReadinessProjectionService::forFrontDesk()`.

Front Desk must not read `readiness_state` or `cleanliness_status` directly from the Room model when the projection service is available.

Allowed Front Desk changes in this package:
- Swap or harden dependency to use the projection service.
- Update tests to prove Front Desk treats HOUSEKEEPING_READY as eligible and HOUSEKEEPING_BLOCKED/HOUSEKEEPING_UNKNOWN as blocking.

Forbidden Front Desk changes:
- New room assignment behavior.
- New check-in behavior.
- New room move behavior.
- New checkout readiness behavior beyond dependency source hardening.
- Final checkout.
- Folio/Payment/Revenue/Tax/AR/GL/Night Audit.

## Package 11 Checkout Turnover Intake Boundary

Decision:

```text
HOUSEKEEPING_CHECKOUT_TURNOVER_INTAKE_REQUIRED
```

Package 11 runtime must introduce one durable Housekeeping-owned checkout-turnover intake identity before marking an FD-C2 handoff DELIVERED. This is an amendment to ADR-086, not a new ADR.

The logical intake evidence must be Housekeeping-owned, Property-scoped, server-generated, immutable, audit-backed, independently source-validated, idempotent, and privacy-minimized.

The intake evidence must bind at minimum:

- `property_id`;
- FD-C2 handoff identity;
- `checkout_execution_id`;
- `front_desk_stay_id`;
- reservation relationship;
- authoritative `room_id`;
- accepted business-date reference where source-compatible;
- source hash or fingerprint;
- server-owned `occurred_at`;
- server-owned actor or system-consumer identity.

Required uniqueness and replay boundary:

- one Housekeeping intake per Property plus FD-C2 handoff;
- one Housekeeping intake per Property plus checkout execution;
- replay returns the same intake identity;
- conflicting relationships fail closed.

The exact PHP class and table name may follow repository conventions, but the logical aggregate and uniqueness contract are mandatory.

Canonical Package 11 source determination:

- no canonical durable idempotent checkout-turnover intake target currently exists;
- `dirty` is both a source-supported readiness state and `RoomCleanlinessStatusEnum` cleanliness status;
- source-supported readiness states are `dirty`, `waiting_cleaning`, `cleaning`, `waiting_inspection`, `ready_for_sale`, `ready_for_arrival`, `ready_for_vip`, and `blocked`;
- source-supported cleanliness statuses are `dirty`, `clean`, and `inspected`;
- readiness projections are `HOUSEKEEPING_READY`, `HOUSEKEEPING_BLOCKED`, and `HOUSEKEEPING_UNKNOWN`;
- currently source-proven transition types are only `START_CLEANING`, `SUBMIT_INSPECTION`, and `RELEASE_READY`;
- no checkout-turnover intake transition type currently exists.

Package 11 may add a narrowly governed checkout-turnover intake transition only according to this amendment and its own runtime PR. It must not claim that ADR-086 previously contained a checkout-handoff intake transition.

Room-state ownership remains frozen:

- only Housekeeping may change cleanliness or readiness;
- Front Desk remains prohibited from changing either;
- accepted checkout evidence is a trigger/source reference, not authority to bypass Housekeeping services;
- matching existing dirty/waiting-cleaning intake may replay;
- contradictory active cleaning, inspection, or blocked evidence must fail closed or enter an explicitly controlled Housekeeping exception path;
- Package 11 must not silently overwrite an active Housekeeping lifecycle;
- handoff delivery does not mean room READY.

Package 11 runtime must correlate task creation and readiness mutation to the durable intake identity; duplicate delivery must resolve the same intake and same task/outcome; and crash after Housekeeping commit but before FD-C2 markDelivered must recover through the intake identity. Legacy `CleaningTaskService::generateDepartureTask()` alone is not sufficient because it directly creates a `checkout_cleaning` task without accepted durable source-identity, source-hash, checkout-execution, handoff, or idempotency protection. A `CleaningTask` row alone is not accepted as the recovery identity unless the Package 11 runtime explicitly hardens it with the required source identity, uniqueness, immutability, and replay contract.

Package 11 must not create a parallel generic workflow framework.

### Explicit Non-Goals

This ADR does not authorize:
- Final checkout or checkout execution.
- FrontDeskStay CHECKED_OUT state.
- Folio, deposit, payment, refund, room charge, revenue, tax.
- Accounts Receivable, General Ledger, Night Audit.
- Cashier, Banking, Financial Period, Business Date.
- Reservation cancellation, no-show settlement, rate override.
- Engineering block mutation or release.
- PMS room_blocks takeover.
- Room master overwrite outside accepted readiness fields.
- Front Desk assignment, check-in, or room move behavior changes.
- Queue, worker, broker, event bus, outbox, external integration.
- Generic housekeeping or workflow framework.
- Package C Cost Ledger runtime.
