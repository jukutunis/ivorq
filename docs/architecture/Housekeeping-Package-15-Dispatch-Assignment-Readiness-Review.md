# Housekeeping Package 15 Dispatch and Assignment Readiness Review

## Review Status

- Review date: 2026-08-03
- Governance package: `PACKAGE_14_POST_HOUSEKEEPING_LIFECYCLE_GOVERNANCE_SYNCHRONIZATION`
- Canonical branch: `ivorq-enterprise-core`
- Canonical SHA reviewed: `9e21f2e3f40438beb6727d9b6c19af4feb53697a`
- Package type: governance-only source review
- Runtime implementation: not authorized in Package 14

## Verdict

```text
PACKAGE_11_ACCEPTED_AND_MERGED
PACKAGE_12_ACCEPTED_AND_MERGED
PACKAGE_13_ACCEPTED_AND_MERGED
PACKAGE_14_GOVERNANCE_SYNCHRONIZATION_AUTHORIZED
PACKAGE_15_CONTROLLED_DISPATCH_AND_ASSIGNMENT_REQUIRED
NO_NEW_ADR_REQUIRED
ADR_040_AND_ADR_086_REMAIN_GOVERNING
PACKAGE_15_RUNTIME_REQUIRES_SEPARATE_DRAFT_PR
```

Package 15 title:

`Controlled Housekeeping Turnover Dispatch, Assignment, Reassignment, and Attendant Workload Foundation`

Next runtime package identifier:

`PACKAGE_15_CONTROLLED_HOUSEKEEPING_TURNOVER_DISPATCH_AND_ASSIGNMENT`

Package 15 remains locked until Package 14 is independently reviewed, Owner-authorized, and merged. Package 15 runtime must then be delivered on a separate branch and Draft PR. This review does not authorize runtime work.

## Accepted Predecessor Evidence

| Package | PR | Accepted feature head | Canonical merge commit | Accepted boundary |
|---|---:|---|---|---|
| Package 11 | #44 | `c429b2b7409cb1e8f1062ad9431b62217a2758f3` | `39b673109109d28e140b67f3835696836401a9e4` | FD-C2 handoff consumption, durable turnover intake, exactly-once correlated checkout-cleaning outcome, source-bound replay and crash recovery |
| Package 12 | #45 | `494b9ceaedf8573f09fe685a2dd899bb32dd6bd1` | `0fa4e14f0c791105d31964d9e4ebebd95fda0345` | Current-Property-scoped read-only turnover workspace with deterministic queue and exception classification |
| Package 13 | #46 | `70f6f0735d31bb573526649ed283237a742b2f7c` | `9e21f2e3f40438beb6727d9b6c19af4feb53697a` | Canonical Cleaning Task and post-cleaning Inspection readiness integration, sensitive release, failed-inspection re-cleaning, integrity, replay, and concurrency proof |

Package 11 through Package 13 collectively established:

```text
FD-C2 checkout handoff
→ durable Housekeeping turnover intake
→ checkout-cleaning task
→ operational turnover workspace
→ assigned cleaning lifecycle
→ pending post-cleaning Inspection
→ pass/release-ready or fail/re-cleaning outcome
```

Assignment is not included in that chain as an accepted canonical write boundary. The task must be assigned before start, but the current assignment path remains legacy.

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

Source-supported transition types from `HousekeepingRoomReadinessTransitionTypeEnum`:

```text
CHECKOUT_TURNOVER_INTAKE
START_CLEANING
SUBMIT_INSPECTION
RELEASE_READY
INSPECTION_FAILED
```

The deferred segregation marker remains `DEFERRED_MAKER_CHECKER_POLICY`. Current source does not prove completed cleaner/inspector segregation, and Package 15 must not silently introduce it.

## Accepted Validation Snapshot

This is a dated Package 13 acceptance snapshot, not a self-updating registry.

| Gate | Accepted result |
|---|---:|
| Package 13 exact batch | 31 tests / 334 assertions |
| Housekeeping active baseline | 147 tests / 2,236 assertions |
| Package 13 migration proof | 1 test / 42 assertions |
| Package 13 isolated concurrency | 1 test / 93 assertions |
| Manifest validation | 36 tests / 1,267 assertions |
| Complete active registry | 14 passed / 0 failed / 0 skipped |
| Complete total | 1,289 tests / 11,473 assertions |
| Inventory Reversal inherited debt | 8 tests / 72 assertions / exactly 2 documented errors |
| New accepted debt | none |

## Focused Source Findings

All findings below are source-proven at the canonical SHA. No suspected issue is promoted beyond the source evidence.

| Finding | Result | Source evidence |
|---|---|---|
| Checkout-turnover and re-cleaning tasks can exist in `pending` | `SOURCE_PROVEN` | `HousekeepingCheckoutTurnoverIntakeService::consumeClaimed()` inserts the correlated checkout-cleaning task with `status = pending`. `HousekeepingCleaningInspectionReadinessLifecycleService` creates the failed-inspection re-cleaning task with `TaskStatusEnum::Pending`. |
| Start-cleaning requires an active assignment | `SOURCE_PROVEN` | `startCheckoutCleaning()` requires task status `Assigned`, then `lockActiveAssignment()` requires an `active` assignment for the acting User before invoking `START_CLEANING`. |
| Current assignment directly mutates Cleaning Task status | `SOURCE_PROVEN` | `CleaningTaskService::assign()` directly executes `$task->update(['status' => 'assigned'])` rather than using a canonical assignment lifecycle service. |
| Browser-selected attendant and department identifiers enter the assignment path | `SOURCE_PROVEN` | `StoreTaskAssignmentRequest` accepts required `user_id` and `department_id`; `CleaningTaskController::assign()` passes the validated identifiers into `CleaningTaskService::assign()`. The identifiers are lookup inputs only and must not be trusted authority in Package 15. |
| Task, User, and Department resolution is not consistently current-Property scoped inside the service boundary | `SOURCE_PROVEN` | `CleaningTaskService::assign()` resolves the task with unscoped `findOrFail()`, resolves the User with unscoped `findOrFail()`, performs membership as a later check, and persists `department_id` without service-side current-Property re-resolution. |
| Durable assignment idempotency identity is absent | `SOURCE_PROVEN` | The assignment request, `CleaningTaskService::assign()`, `TaskAssignmentService`, model, and Housekeeping assignment migration contain no assignment idempotency key, immutable request identity, source hash, or replay contract. |
| Canonical assignment/reassignment lock order is absent | `SOURCE_PROVEN` | `CleaningTaskService::assign()` opens a transaction but performs no `FOR UPDATE` lock on task, prior assignments, attendant, department, Room, or idempotency identity. `TaskAssignmentService::complete()` and `cancel()` also update without locked revalidation. |
| Database-enforced exactly-one-active-assignment rule is absent | `SOURCE_PROVEN` | The Housekeeping assignment migration defines columns only and contains no unique or partial unique constraint for an active assignment per `cleaning_task_id`. |
| Assignment history is not accepted immutable source evidence | `SOURCE_PROVEN` | `TaskAssignment` uses `SoftDeletes`, has no update/delete immutability guard, and the legacy assignment path bulk-updates existing active rows. The migration defines no immutability trigger or protected source identity. |
| `assigned_by` is not consistently persisted | `SOURCE_PROVEN` | The controller adds `assigned_by`, but `CleaningTaskService::assign()` omits it from `TaskAssignment::create()`, `TaskAssignment::$fillable` omits it, and the reviewed Housekeeping assignment migration does not define the column. |
| `reassigned` may be written although absent from `AssignmentStatusEnum` | `SOURCE_PROVEN` | `CleaningTaskService::assign()` bulk-writes `status = reassigned`; `AssignmentStatusEnum` contains only `active`, `completed`, and `cancelled`. |
| Assignment controller and service operations are inconsistent | `SOURCE_PROVEN` | `TaskAssignmentController::complete()` and `cancel()` call `TaskAssignmentService::find()`, but that service defines only `complete()` and `cancel()` and has no `find()` method. Initial assignment is separately implemented in `CleaningTaskService`, so there is no single canonical assignment service. |
| Package 12 workspace provides no controlled dispatch action | `SOURCE_PROVEN` | The workspace route is GET-only, `HousekeepingCheckoutTurnoverWorkspaceQuery` performs read queries only, and Package 12 source-integrity tests prove zero durable GET writes and no mutation route or lifecycle-service call. |

These findings establish a bounded need for Package 15. They do not authorize a rewrite of the accepted Cleaning Task, Inspection, turnover intake, or readiness lifecycle.

## Frozen Ownership Boundary

Housekeeping alone owns:

- task assignment;
- pre-start reassignment;
- assignment history;
- assignment operational evidence;
- task dispatch projection;
- attendant workload projection.

Foundation User and Department remain identity sources. Package 15 must re-resolve their authoritative current-Property relationships but must not absorb Foundation identity ownership. Package 15 must not transfer Housekeeping lifecycle ownership to Foundation Task.

## Frozen Minimum Runtime Scope

A future Package 15 runtime may implement only:

- one canonical Housekeeping assignment service;
- initial assignment of eligible pending Housekeeping tasks;
- controlled pre-start reassignment;
- exactly one active assignment per Cleaning Task;
- immutable prior assignment history;
- server-owned assigner identity and timestamps;
- current-Property task, attendant, department, Room, Company, and Property re-resolution;
- source-proven active Property membership;
- deterministic assignment idempotency;
- audit evidence;
- PostgreSQL uniqueness and relationship integrity;
- a concurrency-safe assignment winner;
- read-only workload and eligibility projection;
- bounded integration into the Package 12 turnover workspace;
- Human Designed Hospitality dispatch interaction following ADR-040.

No additional runtime scope is implied.

## Frozen Narrow Lifecycle

Initial assignment:

```text
pending task
→ active assignment
→ task assigned
```

Pre-start reassignment:

```text
assigned but not started
→ previous assignment closed as reassigned or cancelled
→ one new active assignment
```

Package 15 must not silently reassign an `in_progress` Cleaning Task. Active-task takeover or emergency supervisor intervention requires separate source review and explicit Owner authorization.

## Required Authorization and Server Resolution

Future Package 15 runtime must require:

- authenticated current actor;
- current active Company and Property;
- exact Cleaning Task policy;
- exact permission `housekeeping.task.assign`;
- current-Property attendant eligibility;
- current-Property department relationship where required;
- authorization recheck on locked records.

Browser-selected `user_id` is an identifier only, not trusted authority. The browser must not control:

- Property;
- Company;
- task status;
- assignment status;
- assigner;
- timestamps;
- Room;
- task type;
- workload totals;
- source hash;
- audit identity;
- historical assignment closure outcome.

## Required Database Integrity and Concurrency Proof

Future Package 15 runtime must prove:

- exactly one active assignment per Cleaning Task;
- assignment task and attendant belong to the authoritative Property;
- previous assignment history is never overwritten;
- assignment source identity is immutable;
- terminal assignment evidence cannot be deleted;
- task and active-assignment statuses remain consistent;
- duplicate exact assignment request replays the same outcome;
- conflicting idempotency request fails closed;
- concurrent assignment attempts produce one valid winner and no orphan or mixed status;
- authorization and source relationships are rechecked after the canonical locks are held.

## Explicit Non-Goals

Package 15 must not include:

- drag-and-drop bulk dispatch;
- automatic optimization or AI scheduling;
- GPS or live attendant tracking;
- offline PWA;
- push notifications;
- DND escalation;
- SLA background workers;
- automatic Inventory consumption;
- linen management;
- amenity variance;
- payroll or HRIS integration;
- public-area recurring scheduling;
- turndown scheduling;
- maker-checker enforcement;
- Engineering Work Order creation;
- PMS API integration;
- queues, brokers, schedulers, WebSockets, or external APIs;
- another domain's lifecycle mutation.

These remain later, separately authorized packages.

## ADR Determination and Publication Gate

No new ADR is required. ADR-086 already establishes Housekeeping ownership of Cleaning Task workflow, room readiness, transition evidence, and operational workboards. ADR-040 already governs the required Human Designed Hospitality interaction layer, server-authoritative eligibility, controlled actions, operational evidence, and read-only projections.

Package 15 remains a separate future runtime package. It requires its own branch, Draft PR, focused PostgreSQL integrity and concurrency proof, independent review, and Owner-authorized merge. Package 14 must not implement or begin that runtime.
