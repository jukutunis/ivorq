# Housekeeping Package 17 Inspection Claim and Segregation Readiness Review

## Review Status

- Review date: 2026-08-11
- Governance package: `PACKAGE_16_POST_PACKAGE_15_HOUSEKEEPING_GOVERNANCE_SYNCHRONIZATION`
- Contract version: 1.19
- Canonical branch: `ivorq-enterprise-core`
- Canonical SHA reviewed: `29731a60afc16ab4b50291cc06b00e67011e92f7`
- Package type: governance-only source review
- Runtime implementation: not authorized in Package 16

## Verdict

```text
PACKAGE_14_ACCEPTED_AND_MERGED
PACKAGE_15_ACCEPTED_AND_MERGED
PACKAGE_16_GOVERNANCE_SYNCHRONIZATION_AUTHORIZED
PACKAGE_17_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_AND_SEGREGATION_REQUIRED
NO_NEW_ADR_REQUIRED
ADR_040_AND_ADR_086_REMAIN_GOVERNING
PACKAGE_17_RUNTIME_REQUIRES_SEPARATE_DRAFT_PR
```

Package 17 title:

`Controlled Housekeeping Post-Cleaning Inspection Claim and Cleaner/Inspector Segregation`

Next runtime package identifier:

`PACKAGE_17_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_AND_SEGREGATION`

Package 17 remains locked until Package 16 is independently reviewed, Owner-authorized, and merged. Package 17 runtime must then be delivered on a separate branch and Draft PR. This review does not authorize runtime work.

## Accepted Predecessor Evidence

| Package | PR | Accepted feature head | Canonical merge commit | Accepted boundary |
|---|---:|---|---|---|
| Package 11 | #44 | `c429b2b7409cb1e8f1062ad9431b62217a2758f3` | `39b673109109d28e140b67f3835696836401a9e4` | FD-C2 handoff consumption, durable turnover intake, exactly-once correlated checkout-cleaning outcome, source-bound replay and crash recovery |
| Package 12 | #45 | `494b9ceaedf8573f09fe685a2dd899bb32dd6bd1` | `0fa4e14f0c791105d31964d9e4ebebd95fda0345` | Current-Property-scoped read-only turnover workspace with deterministic queue and exception classification |
| Package 13 | #46 | `70f6f0735d31bb573526649ed283237a742b2f7c` | `9e21f2e3f40438beb6727d9b6c19af4feb53697a` | Canonical Cleaning Task and post-cleaning Inspection readiness integration, sensitive release, failed-inspection re-cleaning, integrity, replay, and concurrency proof |
| Package 14 | #47 | `c388973cc24b6c4cfad3d4a781af0d6c7a3454d0` | `2a88895dd6ab9b14cd94ee7b928636068ecf5d6f` | Post-Package-13 Housekeeping lifecycle governance synchronization and Package 15 activation |
| Package 15 | #49 | `fdf6036d70a85e9c7283f174c205fdef29bcbefe` | `29731a60afc16ab4b50291cc06b00e67011e92f7` | Controlled initial assignment, pre-start reassignment, immutable assignment history, deterministic idempotency, attendant workload projection, integrity, and real-worker concurrency proof |

Package 11 through Package 15 collectively establish:

```text
FD-C2 checkout handoff
→ durable Housekeeping turnover intake
→ checkout-cleaning task
→ controlled dispatch and active attendant assignment
→ assigned attendant completes cleaning
→ one pending post-cleaning Inspection
→ uncontrolled implicit conduct claim
→ pass/release-ready or fail/re-cleaning outcome
```

The accepted lifecycle is canonical through task assignment and cleaning completion. The remaining gap is the claim and maker-checker boundary between a pending post-cleaning Inspection and its terminal pass/fail decision.

## Current Accepted Lifecycle Inventory

Source-supported Room readiness states:

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

Source-supported cleanliness statuses:

```text
dirty
clean
inspected
```

Source-supported Inspection statuses:

```text
pending
in_progress
passed
failed
```

Source-supported readiness transition types:

```text
CHECKOUT_TURNOVER_INTAKE
START_CLEANING
SUBMIT_INSPECTION
RELEASE_READY
INSPECTION_FAILED
```

Package 16 resolves the deferred maker-checker policy direction at the governance level only. Runtime remains absent until Package 17 is independently implemented and accepted.

## Accepted Validation Snapshot

This is a dated Package 15 acceptance snapshot, not a self-updating registry.

| Gate | Accepted result |
|---|---:|
| Package 15 exact suite | 17 tests / 497 assertions |
| Housekeeping active baseline | 22 classes / 164 tests / 2,733 assertions / 0 failures / 0 errors |
| Front Desk active baseline | 68 classes / 729 tests / 5,639 assertions / 0 failures / 0 errors |
| Manifest validation | 36 tests / 1,307 assertions |
| Complete active registry | 14 passed / 0 failed / 0 skipped |
| Complete total | 1,306 tests / 11,994 assertions |
| Frontend production build | passed |
| Inventory Reversal inherited debt | 8 tests / 72 assertions / exactly 2 documented errors |
| New accepted debt | none |

## Focused Source Findings

All findings below are source-proven at the canonical SHA. No suspected issue is promoted beyond the source evidence.

| Finding | Result | Source evidence |
|---|---|---|
| Cleaning completion preserves authoritative cleaner identity | `SOURCE_PROVEN` | `HousekeepingCleaningInspectionReadinessLifecycleService::completeCheckoutCleaning()` requires the active assigned attendant, closes the Package 15 assignment through `completeForLifecycle()`, and records the actor in `CleaningTask.completed_by`. |
| Cleaning completion creates one pending post-cleaning Inspection | `SOURCE_PROVEN` | `completeCheckoutCleaning()` creates `RoomInspection` with `inspection_type = post_cleaning` and `status = pending`; `hk_room_inspections_post_cleaning_task_unique` enforces one post-cleaning Inspection per Cleaning Task. |
| Current conduct is an implicit claim | `SOURCE_PROVEN` | `conductInspection()` locks Room, Inspection, and Cleaning Task, changes `pending` to `in_progress`, and writes the acting User to `RoomInspection.supervisor_id`. An `in_progress` replay is accepted only for that recorded User. |
| Durable claim idempotency evidence is absent | `SOURCE_PROVEN` | `ConductInspectionRequest` accepts no command data and `conductInspection()` has no idempotency key, source hash, claim timestamp, evidence version, or immutable claim audit identity. |
| Cleaner/inspector identity segregation is absent | `SOURCE_PROVEN` | `authorizeInspection()` checks the Inspection policy and optional readiness permission, while `conductInspection()` never compares the acting User with `CleaningTask.completed_by` or the completed Package 15 assignment actor. |
| Pass/fail ownership is not bound to the claimant | `SOURCE_PROVEN` | `passInspection()` and `failInspection()` authorize the acting User and lock the source graph but do not require the actor to equal `RoomInspection.supervisor_id`. |
| Existing permissions can express the bounded operation | `SOURCE_PROVEN` | Current source already defines `housekeeping.inspection.conduct`, `housekeeping.room-readiness.release-ready`, and `housekeeping.room-readiness.clean`. Package 17 need not invent a global role model or trust a browser-supplied role. |
| Current-Property source resolution already exists | `SOURCE_PROVEN` | `scopedInspection()`, `lockRoom()`, `lockInspection()`, and `lockInspectionTask()` resolve and revalidate the Inspection, Room, and Cleaning Task within the authoritative current Property. |
| Terminal Inspection and readiness outcomes are already protected | `SOURCE_PROVEN` | Package 13 protects terminal Inspection evidence and post-cleaning source relationships at application and PostgreSQL levels and binds pass/fail to `RELEASE_READY` or `INSPECTION_FAILED` readiness evidence. |
| No controlled claim takeover or release lifecycle exists | `SOURCE_PROVEN` | Source supports only `pending → in_progress` conduct and terminal pass/fail. It defines no claim expiry, abandonment, release, reassignment, supervisor takeover, or emergency override command. |

These findings establish a bounded need for Package 17. They do not authorize a rewrite of the accepted turnover intake, task assignment, cleaning, Inspection result, sensitive confirmation, re-cleaning, or room-readiness lifecycle.

## Frozen Ownership Boundary

Housekeeping alone owns:

- post-cleaning Inspection claim;
- claimant identity and immutable claim evidence;
- cleaner/inspector identity segregation;
- Inspection pass/fail authority within existing permissions;
- Inspection operational projection and audit evidence;
- Room readiness outcomes through the accepted Housekeeping transition services.

Foundation User remains the identity source. Package 17 must re-resolve the current active User and current-Property membership but must not absorb Foundation identity, employment, Department, role-administration, or session ownership. Package 17 must not transfer Housekeeping lifecycle ownership to Foundation Task or another module.

## Frozen Minimum Runtime Scope

A future Package 17 runtime may implement only:

- one canonical Housekeeping command for claiming an eligible pending post-cleaning Inspection;
- server-owned claimant identity derived from the authenticated actor;
- current active Company, Property, User membership, Room, Cleaning Task, completed cleaner, and Inspection re-resolution;
- an identity-level maker-checker rule that rejects the cleaner recorded in `CleaningTask.completed_by` from claiming or deciding the same post-cleaning Inspection;
- exactly one durable claimant per post-cleaning Inspection;
- deterministic Property-scoped claim idempotency and conflicting-replay rejection;
- immutable claim source identity, claimant, and claimed timestamp;
- claimant-owned pass/fail enforcement while preserving the existing pass and fail permissions;
- authorization and source relationship rechecks after canonical locks are held;
- audit evidence for successful claim and bounded rejection behavior;
- PostgreSQL uniqueness, immutability, and relationship integrity for claim evidence;
- a concurrency-safe claim winner;
- bounded claimant, eligibility, and maker-checker projection in the existing Housekeeping operational workspace;
- Human Designed Hospitality claim interaction following ADR-040.

No additional runtime scope is implied.

## Frozen Narrow Lifecycle

Claim:

```text
pending post-cleaning Inspection
→ authorized non-cleaner claims
→ one immutable claimant
→ Inspection in_progress
```

Decision:

```text
in_progress Inspection owned by claimant
→ same claimant passes with existing release permission and sensitive confirmation
  or
→ same claimant fails with existing clean permission
```

Package 17 must not add claim expiry, release, reassignment, abandonment, supervisor takeover, or emergency override. Any such intervention requires a separate source review and explicit Owner authorization.

## Required Authorization and Server Resolution

Future Package 17 runtime must require:

- authenticated current actor;
- current active Company and Property;
- active current-Property User membership;
- exact Room Inspection policy;
- exact permission `housekeeping.inspection.conduct` to claim;
- existing `housekeeping.room-readiness.release-ready` permission to pass;
- existing `housekeeping.room-readiness.clean` permission to fail;
- actor identity different from `CleaningTask.completed_by` at claim and terminal decision;
- terminal decision actor equal to the recorded Inspection claimant;
- authorization, membership, claim ownership, and maker-checker recheck on locked records.

The browser may submit only the Inspection identifier, a bounded idempotency key for claim, and the already accepted pass/fail business inputs. The browser must not control:

- Company, Property, Room, Cleaning Task, or source relationships;
- cleaner, claimant, inspector, supervisor, actor, role, or permission;
- Inspection status, claimed timestamp, terminal timestamp, or readiness outcome;
- source hash, evidence version, audit identity, or replay classification;
- sensitive-confirmation authority.

## Required Database Integrity and Concurrency Proof

Future Package 17 runtime must prove:

- exactly one claimant for each post-cleaning Inspection;
- the claim binds the exact current-Property Inspection, Room, completed Cleaning Task, and authoritative cleaner identity;
- the claimant differs from `CleaningTask.completed_by`;
- claim source identity, claimant, and claim timestamp cannot be overwritten or deleted;
- duplicate exact claim replays the same outcome;
- a reused idempotency key with different authoritative source identity fails closed;
- concurrent eligible claims produce one valid winner and no mixed or orphan state;
- the completed cleaner cannot win a concurrent claim and causes zero claim mutation;
- only the recorded claimant can pass or fail;
- concurrent pass/fail attempts preserve one accepted terminal outcome and the existing Package 13 readiness/re-cleaning integrity;
- different Properties do not unnecessarily serialize;
- cross-Property and unauthorized access remains non-disclosing and causes zero Housekeeping mutation;
- existing Package 13 and Package 15 source relationships, terminal immutability, sensitive confirmation, assignment history, and readiness evidence remain preserved.

## Explicit Non-Goals

Package 17 must not include:

- a new global role, employment, certification, or workforce model;
- client-selected inspector assignment;
- Inspection claim expiry, lease renewal, release, reassignment, abandonment, takeover, or emergency override;
- bulk Inspection claim or drag-and-drop dispatch;
- automatic inspector scheduling or workload optimization;
- changes to Package 15 cleaning-task assignment or attendant workload ownership;
- changes to Inspection pass/fail business outcomes;
- removal or weakening of sensitive confirmation for room release;
- new Inspection types, checklists, scoring policy, defect taxonomy, or photo policy;
- DND escalation, SLA background workers, push notifications, offline PWA, GPS, or live staff tracking;
- Engineering Work Order creation, Inventory consumption, linen, amenity, payroll, or HRIS integration;
- queues, brokers, schedulers, WebSockets, or external APIs;
- another domain's lifecycle mutation;
- a new ADR.

These remain later, separately authorized packages.

## ADR Determination and Publication Gate

No new ADR is required. ADR-086 already establishes Housekeeping ownership of Cleaning Task workflow, post-cleaning Inspection, room readiness, transition evidence, sensitive release, and the cleaner/inspector maker-checker direction. ADR-040 already governs the required operational workspace, server-authoritative eligibility, controlled actions, and post-execution evidence.

Package 17 remains a separate future runtime package. It requires its own branch, Draft PR, focused PostgreSQL integrity and concurrency proof, independent review, and Owner-authorized merge. Package 16 must not implement or begin that runtime.
