# ADR-086: Controlled Housekeeping Room Readiness Lifecycle Boundary

## ADR Metadata
* **ADR Number:** ADR-086
* **ADR Title:** Controlled Housekeeping Room Readiness Lifecycle Boundary
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-029 (Security Roles and Permissions Governance), ADR-030 (Identity Authentication and Session Governance), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-084 (Controlled Front Desk Arrival, Stay, and Room Assignment Boundary), ADR-085 (Engineering Room Availability and Block Evidence Boundary)

## Context

The Housekeeping module owns room cleanliness and readiness as the source of truth for operational room state. The original ADR context identified direct consumer reads and scattered transition evidence. The accepted Housekeeping package train now provides a Housekeeping-owned readiness boundary, durable checkout-turnover intake, a read-only turnover workspace, canonical Cleaning Task and post-cleaning Inspection integration, controlled Cleaning Task dispatch and assignment, and an accepted controlled post-cleaning Inspection claim with cleaner/inspector identity segregation so that Front Desk, Engineering, and Finance consumers respect Housekeeping ownership without mutation.

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
| Room cleanliness_status | Housekeeping | Read via projection | Housekeeping-owned canonical readiness services only |
| Room readiness_state | Housekeeping | Read via projection | Housekeeping-owned canonical readiness services only |
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
  readiness_state in [dirty, waiting_cleaning, cleaning, waiting_inspection, blocked]
  or cleanliness_status in [dirty, clean]

HOUSEKEEPING_UNKNOWN:
  room missing, inactive, cross-property, or ambiguous
```

Front Desk may treat only `HOUSEKEEPING_READY` as assignment / room move / check-out readiness eligible. `HOUSEKEEPING_BLOCKED` and `HOUSEKEEPING_UNKNOWN` are blocking.

### Controlled Transition Lifecycle

The lifecycle is narrow and operational, controlled through Housekeeping-owned services and recorded in `HousekeepingRoomReadinessTransition` evidence.

Current source-supported readiness states:

```text
dirty
waiting_cleaning
cleaning
waiting_inspection
ready_for_sale
ready_for_arrival
ready_for_vip
blocked
```

Current source-supported cleanliness statuses:

```text
dirty
clean
inspected
```

Current accepted checkout-turnover path:

```text
FD-C2 checkout handoff
→ CHECKOUT_TURNOVER_INTAKE
→ waiting_cleaning checkout-cleaning task
→ START_CLEANING
→ cleaning
→ SUBMIT_INSPECTION
→ waiting_inspection with one pending post-cleaning Inspection
→ RELEASE_READY or INSPECTION_FAILED
```

Current source-supported transition types:

| Type | From | To | Permission | Sensitive Confirmation |
|---|---|---|---|---|
| CHECKOUT_TURNOVER_INTAKE | Source-compatible `dirty`, `waiting_cleaning`, or ready state | waiting_cleaning | Housekeeping system consumer | No |
| START_CLEANING | waiting_cleaning | cleaning | housekeeping.room-readiness.clean | No |
| SUBMIT_INSPECTION | cleaning | waiting_inspection | housekeeping.room-readiness.submit-inspection | No |
| RELEASE_READY | waiting_inspection | ready_for_sale, ready_for_arrival, or ready_for_vip | housekeeping.room-readiness.release-ready | Yes |
| INSPECTION_FAILED | waiting_inspection | waiting_cleaning | housekeeping.room-readiness.clean | No |

### State Transition Policy

- CHECKOUT_TURNOVER_INTAKE: Housekeeping consumes an eligible FD-C2 checkout handoff, re-resolves the authoritative Property, checkout execution, stay, reservation, Room, and source hashes, creates or replays one durable intake and one correlated pending checkout-cleaning task, and records one source-bound transition to `waiting_cleaning`. Front Desk cannot supply or mutate the readiness outcome.
- START_CLEANING: Only allowed from `readiness_state` = `waiting_cleaning`. The canonical Cleaning Task integration additionally requires an assigned checkout-cleaning task and an active assignment for the acting attendant. Server resolves active property, room identity, actor permission, idempotency key, and audit evidence. Browser must not control property, room, actor, or timestamp.
- SUBMIT_INSPECTION: Only allowed from `readiness_state` = `cleaning`. Server resolves active property, room identity, actor permission, idempotency key, audit evidence.
- RELEASE_READY: Only allowed from `readiness_state` = `waiting_inspection`. Requires sensitive confirmation via `housekeeping-room-release-ready` intent. Server re-resolves all values at execution time and compares to confirmation evidence hash.
- INSPECTION_FAILED: Only the canonical failed post-cleaning Inspection path may return the Room to `waiting_cleaning`; the outcome creates exactly one source-bound pending re-cleaning task and replays the same terminal evidence.

### Transition Evidence Aggregate

The `HousekeepingRoomReadinessTransition` is an append-only immutable evidence record:

Minimum fields:
- `id` (ULID)
- `property_id`
- `room_id`
- `from_status` (readiness_state before transition)
- `to_status` (readiness_state after transition)
- `transition_type` (`CHECKOUT_TURNOVER_INTAKE`, `START_CLEANING`, `SUBMIT_INSPECTION`, `RELEASE_READY`, or `INSPECTION_FAILED`)
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

The actor recorded as the completed cleaner in `CleaningTask.completed_by` must not claim or decide the same post-cleaning Inspection. One server-resolved non-cleaner claimant must own the `in_progress` Inspection and its pass/fail decision. Passing still requires `housekeeping.room-readiness.release-ready` and Sensitive Action Confirmation; failing still requires `housekeeping.room-readiness.clean`.

Historical status: **DEFERRED_MAKER_CHECKER_POLICY**

Current Package 16 determination:

- **PACKAGE_17_INSPECTION_SEGREGATION_BOUNDARY_FROZEN**
- **NOT_YET_RUNTIME_ENFORCED**
- **PACKAGE_17_RUNTIME_LOCKED_PENDING_PACKAGE_16_MERGE**

`DEFERRED_MAKER_CHECKER_POLICY` is retained as historical evidence. Contract Version 1.19 resolves only the future identity-level policy direction without inventing a new global role model: when Package 17 is implemented, the cleaner recorded in `CleaningTask.completed_by` must not claim or decide the same post-cleaning Inspection, and one claimed non-cleaner inspector must own its pass/fail decision. The existing current-Property User identity and permissions are sufficient future inputs, but runtime enforcement, durable claim evidence, and concurrency proof do not exist yet. Contract Version 1.19 does not itself authorize Package 17 runtime. Package 17 remains locked until Package 16 is independently reviewed, explicitly Owner-authorized, and merged into canonical; Package 17 then requires its own branch, Draft PR, independent review, Owner authorization, and merge. Package 16 changes governance records only and must not claim that segregation already exists.

The preceding Package 16 markers and paragraph are retained as historical sequencing evidence. They are not the current runtime classification.

Current status under Contract Version 1.21:

- **PACKAGE_17_INSPECTION_CLAIM_AND_SEGREGATION_ACCEPTED**
- **PACKAGE_19_INSPECTION_CLAIM_RECOVERY_AND_REASSIGNMENT_ACCEPTED**
- **RUNTIME_ENFORCED**
- **PACKAGE_20_POST_PACKAGE_19_HOUSEKEEPING_FINAL_GOVERNANCE_CLOSURE**
- **HOUSEKEEPING_TURNOVER_READINESS_PACKAGE_TRAIN_CLOSURE_PENDING_PACKAGE_20_MERGE**
- **NO_PACKAGE_21_ACTIVATED**

Package 17 is accepted through PR #51 at canonical merge `37750626f9e0614d26d628a4707bcb205508ae03`, with accepted feature HEAD `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`. Runtime enforces a canonical post-cleaning claim, server-resolved claimant identity in `RoomInspection.supervisor_id`, deterministic Property-scoped idempotency, immutable claim evidence, cleaner/inspector segregation, claimant-owned terminal decisions, PostgreSQL claim-bypass closure, and historical Package 13 compatibility. Package 17 claimant immutability remains canonical. Package 19 is accepted through PR #53 at canonical merge `086deefca673af57776fcaa14e06494c2f16ab4d`, with accepted feature HEAD `9bd18634e603ee7e545798dd7ddf913407e2a685`. Package 19 adds separate append-only controlled recovery/reassignment evidence and effective replacement claimant semantics without mutating the original Package 17 claim. Package 20 is governance-only and adds no runtime.

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

## Historical Version 1.17 Package 11 Authorization Boundary

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
- at that historical pre-Package-11 point, source-proven transition types were only `START_CLEANING`, `SUBMIT_INSPECTION`, and `RELEASE_READY`;
- at that historical pre-Package-11 point, no checkout-turnover intake transition type existed.

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

The preceding section records the historical Version 1.17 authorization state before Package 11 runtime existed. It is retained as sequencing evidence and is not the current runtime classification.

## Package 11 Accepted Checkout Turnover Intake

Package 11 is accepted and merged through PR #44. Accepted feature head: `c429b2b7409cb1e8f1062ad9431b62217a2758f3`. Canonical merge commit: `39b673109109d28e140b67f3835696836401a9e4`.

Current accepted behavior:

- durable Housekeeping-owned checkout-turnover intake exists in `HousekeepingCheckoutTurnoverIntake`;
- intake is source-bound to the FD-C2 handoff, checkout execution, Front Desk stay, reservation, authoritative Room, and accepted business-date evidence;
- one intake correlates exactly one checkout-cleaning task and one `CHECKOUT_TURNOVER_INTAKE` readiness transition;
- the task begins in `pending` and the Room moves through Housekeeping-owned source evidence to `waiting_cleaning` with `dirty` cleanliness;
- replay returns the same intake, task, and transition identities;
- crash after the Housekeeping transaction commits but before FD-C2 delivery confirmation recovers through the durable intake identity without duplicating the turnover outcome;
- Front Desk remains unable to mutate Housekeeping readiness or cleanliness.

## Package 12 Accepted Read-Only Turnover Workspace

Package 12 is accepted and merged through PR #45. Accepted feature head: `494b9ceaedf8573f09fe685a2dd899bb32dd6bd1`. Canonical merge commit: `0fa4e14f0c791105d31964d9e4ebebd95fda0345`.

The checkout-turnover operational workspace:

- is scoped to the authenticated actor's current active Property;
- is read-only and exposes no dispatch or lifecycle mutation authority;
- presents correlated handoff, intake, Cleaning Task, Inspection, Room, and readiness evidence;
- classifies operational queue and exception states deterministically using PostgreSQL wall clock and Property-scoped evidence;
- performs zero durable writes for workspace GET operations.

## Package 13 Accepted Cleaning and Inspection Integration

Package 13 is accepted and merged through PR #46. Accepted feature head: `70f6f0735d31bb573526649ed283237a742b2f7c`. Canonical merge commit: `9e21f2e3f40438beb6727d9b6c19af4feb53697a`.

Current accepted behavior:

- checkout-cleaning task start and completion invoke the canonical Housekeeping readiness transition service;
- start requires the task to be assigned and the acting attendant to hold the active assignment;
- completion creates exactly one pending post-cleaning Inspection;
- controlled Inspection conduct does not itself mutate Room readiness;
- Inspection pass requires sensitive confirmation bound to the exact current evidence, records `RELEASE_READY`, verifies the task, and releases the Room to its server-derived ready target;
- Inspection failure records `INSPECTION_FAILED`, returns the Room to `waiting_cleaning` and `dirty`, and creates exactly one source-bound pending re-cleaning task;
- terminal evidence, source relationships, uniqueness, and immutability are protected at application and PostgreSQL levels;
- exact replay and real concurrency behavior are accepted.

## Package 15 Accepted Dispatch and Assignment

Package 15 is accepted and merged through PR #49. Accepted feature head: `fdf6036d70a85e9c7283f174c205fdef29bcbefe`. Canonical merge commit: `29731a60afc16ab4b50291cc06b00e67011e92f7`.

Current accepted behavior:

- one canonical Housekeeping command owns initial assignment and pre-start reassignment;
- exactly one active assignment is enforced per Cleaning Task;
- prior assignment history is immutable and cannot be deleted;
- assignment identity, target attendant, Department, Property, actor, timestamps, and source hash are server-validated and database-protected;
- deterministic Property-scoped idempotency returns the same outcome or rejects a conflicting replay;
- an `in_progress` Cleaning Task cannot be silently reassigned;
- completion closes the active assignment as immutable evidence;
- bounded workload projection and turnover-workspace dispatch integration remain read-only projections around the canonical assignment command;
- real PostgreSQL concurrency proof covers assignment races, Property isolation, and zero orphan or mixed assignment state.

## Package 17 Accepted Inspection Claim and Segregation

Package 17 is accepted and merged through PR #51. Accepted feature HEAD: `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`. Canonical merge commit: `37750626f9e0614d26d628a4707bcb205508ae03`.

Accepted append-only provenance:

- original source: `20112b623d04c50655e8701566c1dbd156e6dc53`;
- original metadata: `de3e131c091f02fbb70cabb41006accecb0ce1bd`;
- legacy/replay correction: `0120b793a1e10f21ae4b6a235e9e75591b792ee4`;
- Package 13 concurrency fixture alignment: `86a3b9e242bbf427353e07131c42f69d983df6e9`;
- corrected metadata: `40a6b3959411fd6d4a347e03d617905fc7ad9d5f`;
- PostgreSQL bypass closure: `3055610ebd714f592fe395926a180743a5e945d1`;
- Foundation legacy proof alignment: `b45bba591e32963c2bbe7e03a82cc9f997a5d6c1`;
- Package 13 canonical claim fixture: `55399a7c53dc9c5f099ee4570ec1bc1bb6fd757b`;
- Package 13 successor migration isolation: `98ccdeb9be1b9bc60b2df9cda2d31bbe9aed4a59`;
- final metadata/HEAD: `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`;
- canonical merge: `37750626f9e0614d26d628a4707bcb205508ae03`.

Current accepted behavior:

- one canonical Housekeeping service writes an eligible pending post-cleaning Inspection claim;
- the authenticated, active, current-Property User is re-resolved on the server and recorded as claimant in `RoomInspection.supervisor_id`;
- `housekeeping.inspection.conduct` remains the ordinary claimant eligibility permission;
- claim idempotency is deterministic and Property-scoped;
- `claimed_at`, `claim_idempotency_key`, `claim_source_hash`, and `claim_evidence_version` bind the source graph and claimant;
- the claimant must differ from `CleaningTask.completed_by`;
- `supervisor_id` and all Package 17 claim evidence are immutable at application and PostgreSQL levels;
- only the effective recorded claimant may pass or fail;
- pass still requires `housekeeping.room-readiness.release-ready` and the `housekeeping-room-release-ready` sensitive confirmation;
- fail still requires `housekeeping.room-readiness.clean`;
- PostgreSQL blocks direct claim bypass, historical supervisor takeover, claim deletion, claim evidence rewriting, malformed canonical claims, and maker-checker violation;
- historical Package 13 rows remain compatible without fabricated Package 17 evidence and retain their historical recorded-supervisor terminal boundary.

Package 17 defines no claim expiry, release, abandonment, reassignment, takeover, emergency recovery, or alternate effective claimant. A Package 17 claimant cannot be silently replaced. If that claimant later becomes objectively ineligible, the `in_progress` Inspection has no current recovery path.

## Remaining Inspection Claim and Segregation Boundary

This section records the historical pre-Package-17 determination made under Contract Version 1.19. The accepted Package 13 Inspection lifecycle changed a pending post-cleaning Inspection to `in_progress` and recorded the acting User in `RoomInspection.supervisor_id`, but then-current conduct had no durable claim idempotency identity or claim audit contract. Then-current conduct did not reject the cleaner recorded in `CleaningTask.completed_by`, and pass/fail actions did not require the acting User to equal the recorded claimant.

That historical boundary required future Package 17, `PACKAGE_17_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_AND_SEGREGATION`, on its own runtime branch and Draft PR after Package 16 was accepted and merged. Package 17 is now accepted through PR #51; the paragraph is retained only as historical evidence.

Package 17 was required to preserve Housekeeping ownership, current-Property isolation, existing Inspection pass/fail outcomes, Package 13 readiness and re-cleaning integrity, Package 15 assignment history, existing permissions, and sensitive confirmation. It was authorized to add one durable idempotent claim boundary, exactly one immutable claimant, claimant-owned pass/fail enforcement, and the rule that `CleaningTask.completed_by` cannot claim or decide the same post-cleaning Inspection. Package 17 did not authorize claim release, reassignment, expiry, takeover, emergency override, new role modeling, automated scheduling, or foreign-domain mutation.

## Historical Package 19 Controlled Claim Recovery Boundary

Package 18 freezes, but does not implement, `PACKAGE_19_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_RECOVERY_AND_REASSIGNMENT` — Controlled Housekeeping Inspection Claim Recovery and Supervisory Reassignment Foundation.

The future boundary is limited to one controlled supervisory recovery of an `in_progress` canonical Package 17 post-cleaning Inspection when the original claimant is objectively no longer eligible. Source-resolvable ineligibility is limited to inactive/deleted User state, inactive/missing current-Property membership, or loss of `housekeeping.inspection.conduct`. Shift schedules, GPS presence, attendance, leave, HRIS availability, or subjective workload cannot be inferred from current source.

The future intervenor must be authenticated, active, current-Property, have active membership, hold exact permission `housekeeping.inspection.approve`, and complete Sensitive Action Confirmation using the preferred future intent `housekeeping-inspection-claim-reassignment`. The intent is not registered by Package 18. ADR-066 already governs this confirmation primitive, so no new ADR is required.

The replacement claimant must be server-resolved, active in the current Property, hold `housekeeping.inspection.conduct`, differ from `CleaningTask.completed_by`, and differ from the original claimant. The original Package 17 fields `supervisor_id`, `claimed_at`, `claim_idempotency_key`, `claim_source_hash`, and `claim_evidence_version` remain immutable original evidence and must not be overwritten. A separate Housekeeping-owned append-only intervention evidence identity must record mandatory reason, deterministic Property-scoped idempotency, source hash, server timestamp, intervenor, original claimant, and replacement claimant with audit, PostgreSQL integrity, and concurrency-safe one-winner behavior.

Logical effective claimant:

```text
if no accepted recovery evidence:
    effective claimant = Package 17 original claimant

if one accepted Package 19 recovery exists:
    effective claimant = validated replacement claimant
```

At most one recovery is permitted per original claim. After accepted recovery, only the replacement effective claimant may pass/fail and the original claimant loses terminal authority. Cleaner segregation and the accepted pass/fail permissions remain unchanged; the recovery confirmation does not replace the separate `housekeeping-room-release-ready` confirmation required to pass.

At that historical Contract Version 1.20 point, Package 19 runtime remained locked pending Package 18 independent review, explicit Owner authorization, and merge. Package 18 created no runtime, migration, model, service, controller, request, policy, permission, route, seeder, sensitive intent registration, UI, or test. These markers are retained as historical authorization evidence and are not the current runtime classification.

## Package 19 Accepted Inspection Claim Recovery and Reassignment

Package 19 is accepted and merged through PR #53. Accepted feature HEAD: `9bd18634e603ee7e545798dd7ddf913407e2a685`. Canonical merge commit: `086deefca673af57776fcaa14e06494c2f16ab4d`.

Accepted append-only provenance:

- original source: `3f05283dc878c9ec098ba0e27b319451abda36ad`;
- original metadata: `88750a9a23067d1630d0bf151510f0a94083f546`;
- deterministic timestamp/PostgreSQL correction: `a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50`;
- corrected metadata/final feature HEAD: `9bd18634e603ee7e545798dd7ddf913407e2a685`;
- canonical merge: `086deefca673af57776fcaa14e06494c2f16ab4d`.

Current accepted behavior:

- the original Package 17 claim remains immutable, including `supervisor_id`, `claimed_at`, `claim_idempotency_key`, `claim_source_hash`, and `claim_evidence_version`;
- Package 19 uses a separate append-only `HousekeepingInspectionClaimReassignment` aggregate rather than overwriting Package 17 claim evidence;
- before recovery, the effective claimant is the original Package 17 claimant; after one accepted recovery, the effective claimant is the validated replacement claimant;
- original-claimant ineligibility is objectively re-resolved from inactive/deleted User state, inactive/missing current-Property membership, or loss of `housekeeping.inspection.conduct`;
- the authenticated current-Property intervenor requires exact permission `housekeeping.inspection.approve` and Sensitive Action Confirmation intent `housekeeping-inspection-claim-reassignment`;
- the replacement claimant independently requires `housekeeping.inspection.conduct`, active current-Property membership, and cleaner/inspector segregation;
- at most one recovery is accepted for the original claim; a second recovery/reassignment chain remains future optional scope;
- after accepted recovery, only the replacement effective claimant may pass or fail; the original claimant cannot regain terminal authority merely by becoming eligible again;
- replacement pass still requires independent permission `housekeeping.room-readiness.release-ready` and separate `housekeeping-room-release-ready` Sensitive Action Confirmation;
- replacement fail still requires independent permission `housekeeping.room-readiness.clean`;
- recovery confirmation does not replace room-release confirmation;
- deterministic `occurred_at` and `created_at` evidence, exact replay, audit evidence, PostgreSQL malformed-write protection, and real concurrency proof are accepted.

Package 20, `PACKAGE_20_POST_PACKAGE_19_HOUSEKEEPING_FINAL_GOVERNANCE_CLOSURE`, synchronizes governance only. The Housekeeping turnover/readiness package-train closure remains pending Package 20 review and merge. `NO_PACKAGE_21_ACTIVATED`. No new ADR is required.

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
