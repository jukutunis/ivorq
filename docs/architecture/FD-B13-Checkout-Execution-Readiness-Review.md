# FD-B13 Checkout Execution Readiness Review

## 10.1 Metadata

Package: FD-B13

Canonical reviewed SHA: `286c7f491ea82385ee369ff0020625448eea671d`

Contract version: 1.5

Review type: Source-backed checkout execution readiness review

Runtime implementation: Not authorized

## 10.2 Executive Verdict

`CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`

Current source proves strong read-only readiness foundations, but it does not prove that checkout execution can be implemented atomically in the next package without additional owner-domain work. The blocking evidence is:

- Front Desk cannot currently express a checked-out/departed terminal stay state. `Modules/Operations/FrontDesk/Enums/FrontDeskStayStatusEnum.php` contains only `ARRIVAL_READY`, `ROOM_ASSIGNED`, `CHECK_IN_CONFIRMATION_PENDING`, and `IN_HOUSE`.
- PMS Guest Ledger GLF-D and General Cashier GC-A1 are top-level `REPEATABLE READ, READ ONLY` projections and explicitly reject nested transaction participation through `GLF_D_REQUIRES_TOP_LEVEL_READ_TRANSACTION` and `GC_A1_REQUIRES_TOP_LEVEL_READ_TRANSACTION`. They prove snapshot read contracts, not checkout write-time financial/cashier terminalization.
- Night Audit start locks the `properties` row and the `property_business_dates` row before creating an active run. The current Front Desk boundary reads the Night Audit projection but does not share those locks; the required race remains unresolved for execution.
- `frontdesk-checkout-execution` is not registered in `SensitiveActionConfirmationService::REGISTERED_INTENTS`, and no `frontdesk.checkout-execution.execute` permission, checkout execution route, checkout execution evidence table, or checkout handoff event/outbox contract exists.

Even if a later package resolves these prerequisites, checkout implementation remains unauthorized until independent review, Owner acceptance, and a separately authorized implementation package.

## 10.3 Source Inventory

Governance and ADR sources reviewed:

- `GEMINI.md`
- `CLAUDE.md`
- `.agents/contracts/IVORQ-Package-Execution-Contract.md`
- `.agents/skills/ivorq-delivery-mode-and-validation-governance/SKILL.md`
- `.agents/skills/ivorq-front-desk-departure-lifecycle/SKILL.md`
- `.agents/skills/ivorq-architecture-and-adr-boundaries/SKILL.md`
- `.agents/skills/ivorq-cross-domain-ownership-boundaries/SKILL.md`
- `.agents/skills/ivorq-module-integration-and-operational-workflows/SKILL.md`
- `.agents/skills/ivorq-financial-and-inventory-controls/SKILL.md`
- `.agents/skills/ivorq-finance-accounting-and-close/SKILL.md`
- `.agents/skills/ivorq-security-access-and-audit/SKILL.md`
- `.agents/skills/ivorq-documentation-and-repository-governance/SKILL.md`
- `.agents/skills/ivorq-validation-baseline-governance/SKILL.md`
- `docs/architecture/adr/ADR-001-Multi-Tenant-Hierarchy.md`
- `docs/architecture/adr/ADR-002-Audit-Trail-Strategy.md`
- `docs/architecture/adr/ADR-004-Finance-Module-Boundary.md`
- `docs/architecture/adr/ADR-029-Security-Roles-and-Permissions-Governance.md`
- `docs/architecture/adr/ADR-034-Night-Audit-and-Hospitality-Business-Date-Architecture.md`
- `docs/architecture/adr/ADR-040-IVORQ-Interaction-Layer-Standard.md`
- `docs/architecture/adr/ADR-066-Sensitive-Action-Reauthentication-and-Session-Confirmation-Boundary.md`
- `docs/architecture/adr/ADR-067-Finance-Sensitive-Decision-Confirmation-Enforcement.md`
- `docs/architecture/adr/ADR-084-Controlled-Front-Desk-Arrival-Stay-and-Room-Assignment-Boundary.md`
- `docs/architecture/adr/ADR-085-Engineering-Room-Availability-and-Block-Evidence-Boundary.md`
- `docs/architecture/adr/ADR-086-Controlled-Housekeeping-Room-Readiness-Lifecycle-Boundary.md`
- `docs/architecture/adr/ADR-087-Controlled-Front-Desk-Departure-Checkout-Execution-Boundary.md`
- `docs/architecture/adr/ADR-088-Guest-Ledger-Folio-and-Hospitality-Financial-Subledger-Architecture.md`
- `docs/architecture/ADR-Master-Structure-Review.md`
- `scripts/validation/ivorq-regression-baselines.json`

Important source code reviewed by domain:

- Front Desk: `Modules/Operations/FrontDesk/Models/FrontDeskStay.php`, `Modules/Operations/FrontDesk/Enums/FrontDeskStayStatusEnum.php`, `Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php`, `Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutFinalReviewService.php`, Front Desk departure B3-B7 services, migrations, and tests.
- PMS Guest Ledger and PMS Cashiering: `Modules/Operations/PMS/Services/GuestLedgerCheckoutSettlementReadinessProjectionService.php`, `Modules/Operations/PMS/ValueObjects/GuestLedgerCheckoutSettlementReadinessProjection.php`, `Modules/Operations/PMS/Enums/GuestLedgerSettlementReadinessStatusEnum.php`, GLF-A through GLF-D migrations and tests.
- General Cashier: `Modules/Operations/GeneralCashier/Services/GeneralCashierCheckoutObligationProjectionService.php`, `Modules/Operations/GeneralCashier/ValueObjects/GeneralCashierCheckoutObligationProjection.php`, `Modules/Operations/GeneralCashier/Enums/GeneralCashierCheckoutObligationStatusEnum.php`, GC-A1 tests and migrations.
- Business Date and Night Audit: `Modules/Foundation/Property/Services/PropertyBusinessDateProjectionService.php`, `Modules/Foundation/Property/Models/PropertyBusinessDate.php`, `database/migrations/2026_06_21_000001_create_property_business_dates_table.php`, `Modules/Operations/NightAudit/Services/NightAuditRunStartService.php`, `Modules/Operations/NightAudit/Services/NightAuditLockProjectionService.php`, `database/migrations/2026_07_17_000001_create_night_audit_runs_table.php`.
- Housekeeping and Engineering: `Modules/Operations/Housekeeping/Services/HousekeepingRoomReadinessProjectionService.php`, `Modules/Operations/Housekeeping/Services/HousekeepingRoomReadinessTransitionService.php`, `Modules/Operations/Engineering/Services/EngineeringRoomAvailabilityProjectionService.php`, `Modules/Operations/Engineering/Services/EngineeringRoomAvailabilityBlockService.php`.
- Security, routes, audit, and outbox: `Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php`, `Modules/Foundation/Authorization/Http/Controllers/SensitiveActionConfirmationController.php`, `routes/web.php`, `Modules/Foundation/Audit/Services/AuditService.php`, `Modules/Foundation/Outbox/Repositories/OutboxRepository.php`, `Modules/Foundation/Outbox/database/migrations/2026_06_27_000000_create_outbox_messages_table.php`.

## 10.4 Ownership Matrix

| Capability | Owner | Front Desk authority | Current source |
| --- | --- | --- | --- |
| Stay departure transition | Front Desk | Future command owner | SOURCE_PARTIAL: `FrontDeskStay` exists, but no terminal checkout status exists in `FrontDeskStayStatusEnum`. |
| Folio settlement | PMS Guest Ledger | Read-only | SOURCE_PARTIAL: GLF-D projection exists; no terminal checkout financial attestation/freeze exists. |
| Payment allocation | PMS Cashiering | None | SOURCE_PROVEN as source-owned lifecycle in GLF-B/GLF-C services; Front Desk reads only through GLF-D. |
| Cashier obligation | General Cashier | Read-only | SOURCE_PARTIAL: GC-A1 projection exists; no terminal checkout cashier attestation/freeze exists. |
| Business Date | Business Date / Night Audit | Read-only | SOURCE_PARTIAL: BD-A1 open-date projection exists; no checkout shared write lock contract exists. |
| Night Audit close lock | Night Audit | Read-only | CONCURRENCY_UNRESOLVED: NA-A1 lock projection exists; checkout does not yet share Night Audit start locks. |
| Housekeeping room readiness as a prerequisite for allowing guest departure | Housekeeping | Read-only | NOT_REQUIRED: dirty, inspected, blocked, or not-ready room state must not by itself prevent otherwise valid guest departure unless a later approved ADR explicitly creates that gate. |
| Post-checkout Housekeeping room-turnover handoff | Housekeeping | Event consumer / owner | IMPLEMENTATION_PREREQUISITE_REQUIRED: Housekeeping owns post-checkout room turnover; no checkout-complete handoff event exists, and Front Desk must not mutate Housekeeping room readiness directly. |
| Engineering availability as a prerequisite for allowing guest departure | Engineering | Read-only | NOT_REQUIRED: maintenance or Engineering block generally affects room availability and turnover, not the guest's right to depart. |
| Engineering checkout handoff | Engineering | Optional event consumer only if separately approved | NOT_REQUIRED: Engineering may consume a future event only if a later approved Engineering workflow requires it. |
| GL/tax/revenue | Accounting | None | NOT_REQUIRED for Front Desk checkout execution gate unless a later ADR/package adds accounting completion as a gate. |

## 10.5 Gate Matrix

| Gate | Source owner | Source service | Current status contract | Execution-time revalidation | Transaction participation | Lock availability | Race risk | Source fingerprint | Readiness classification |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stay property ownership | Front Desk | `FrontDeskDepartureCheckoutExecutionBoundaryProjectionService` | Same-property stay resolved or 404 | Required | Future Front Desk transaction | FrontDeskStay row lock available | Low if locked before status change | Not current execution evidence | SOURCE_PROVEN |
| Stay is IN_HOUSE | Front Desk | `FrontDeskStayStatusEnum` | `IN_HOUSE` required | Required | Future Front Desk transaction | FrontDeskStay row lock available | Medium until terminal state exists | Not current execution evidence | SOURCE_PARTIAL |
| Latest FD-B7 ready | Front Desk | `FrontDeskDepartureCheckoutFinalReviewService` / projection | Latest status must be `CHECKOUT_FINAL_REVIEW_READY` | Required | Future Front Desk transaction can lock/read FD evidence | Rows are immutable; row lock/read possible | Medium if newer B7 appears before checkout lock | `source_hash` exists on B7 evidence | SOURCE_PROVEN |
| No existing completed checkout | Front Desk future | None | Not implemented | Required | Missing | Missing table/unique constraint | High duplicate-execution risk | Missing | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Financial settlement | PMS Guest Ledger | `GuestLedgerCheckoutSettlementReadinessProjectionService` | ready, blocked, review-required, evidence-unavailable | Required | Cannot currently run inside checkout transaction because nested transaction is rejected | No shared source lock exposed | High regression race | `source_fingerprint` exists | CONCURRENCY_UNRESOLVED |
| Payment allocation terminal | PMS Cashiering / PMS Guest Ledger | GLF-B/GLF-C consumed by GLF-D | Terminal allocation/refund/deposit/AR semantics in projection | Required | No Front Desk lock participation | Foreign-domain locks unavailable to Front Desk | High if source mutates after read | Included in GLF-D fingerprint | CONCURRENCY_UNRESOLVED |
| Cashier obligation | General Cashier | `GeneralCashierCheckoutObligationProjectionService` | clear, blocked, review-required, evidence-unavailable | Required | Cannot currently run inside checkout transaction because nested transaction is rejected | No shared source lock exposed | High regression race | `source_fingerprint` exists | CONCURRENCY_UNRESOLVED |
| Business Date open | Business Date / Night Audit | `PropertyBusinessDateProjectionService` via FD-B11 | `BUSINESS_DATE_OPEN` or unavailable | Required | Future checkout must share Property and Business Date row locks | Available if owned lock order is approved | Medium/high during close/advance/reopen future work | `source_fingerprint` exists | CONCURRENCY_UNRESOLVED |
| Night Audit lock clear | Night Audit | `NightAuditLockProjectionService` via FD-B12 | clear, active, unavailable | Required | Current read-only projection does not lock with checkout | Night Audit start lock order known | High for specified clear-then-start race | `source_fingerprint` exists | CONCURRENCY_UNRESOLVED |
| Housekeeping readiness gate | Housekeeping | `HousekeepingRoomReadinessProjectionService` | ready, blocked, unknown | Not required for allowing guest departure; informational only unless a later approved ADR creates a gate | Not a checkout transaction participant | Housekeeping locks Room in owner commands | Low when treated as informational; high if checkout wrongly treats dirty/block as execution failure | No durable checkout fingerprint | NOT_REQUIRED |
| Housekeeping post-checkout turnover handoff | Housekeeping | Future approved handoff/outbox or owner-domain contract | Not implemented | Required before production checkout implementation so Housekeeping can own room-turnover recovery after checkout | Future checkout transaction must persist handoff/outbox atomically with checkout evidence | Missing checkout handoff/outbox contract | Medium until transactional recovery contract exists | Missing checkout handoff fingerprint | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Engineering availability gate | Engineering | `EngineeringRoomAvailabilityProjectionService` | available, blocked, unknown | Not required for allowing guest departure; relevant to room availability and turnover only | Not a checkout transaction participant | Engineering locks Room/block in owner commands | Low for guest departure | No durable checkout fingerprint | NOT_REQUIRED |
| Engineering checkout handoff | Engineering | Optional future event consumer only if separately approved | Not required | Not required for checkout implementation; Engineering may consume a future event only under a separately approved workflow | Not a checkout transaction participant unless later approved | No mandatory checkout lock or event contract required | Low because no mandatory handoff exists | Not applicable | NOT_REQUIRED |

## 10.6 State-Transition Contract

Future Front Desk checkout execution must transition an `IN_HOUSE` stay to a canonical Front Desk-owned terminal state in one controlled transaction. Current source does not contain a terminal status. `FrontDeskStayStatusEnum` has no `CHECKED_OUT`, `DEPARTED`, `SETTLED`, or equivalent terminal state.

Classification: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.

Consequence: the later implementation package must explicitly add and test a terminal Front Desk stay state and must not overload `IN_HOUSE`, `ROOM_ASSIGNED`, `CHECK_IN_CONFIRMATION_PENDING`, or historical departure evidence statuses. The state name should be approved in the implementation package, not silently invented by FD-B13.

## 10.7 Browser Input Contract

The future browser must supply identifiers only:

```json
{
  "front_desk_stay_id": "ULID",
  "idempotency_key": "opaque client-generated key"
}
```

No browser-supplied property, company, tenant, actor, guest, reservation, room, status, business date, amount, currency, folio balance, payment result, cashier result, Night Audit result, source fingerprint, audit timestamp, or execution result may be trusted.

Classification: `SOURCE_PROVEN` for the existing identifier-only direction in ADR-087 and service patterns; `IMPLEMENTATION_PREREQUISITE_REQUIRED` for the future execution route/controller/service.

## 10.8 Authorization and Sensitive Confirmation

Future permission should use:

```text
frontdesk.checkout-execution.execute
```

This name is source-consistent with existing dotted module-resource-action permissions such as `frontdesk.checkout-execution-boundary.view`, `frontdesk.check-in.execute`, and `frontdesk.room-move.execute`.

It may be assigned only to explicit Front Desk operational roles that are authorized to complete departure. Boundary-view permission must not imply execute permission. Finance, Cashier, Night Audit, Housekeeping, Engineering, Banking, GL, AR, tax, revenue, and broad operational roles must not receive it by default. Broad administrators require explicit break-glass or explicit operational assignment, not implicit checkout authority.

Authorization ordering for the future command:

1. Resolve the authenticated actor and server-owned active company/property context.
2. Authorize `frontdesk.checkout-execution.execute` before querying or resolving the requested stay.
3. Resolve `front_desk_stay_id` scoped to the active property.
4. Return non-disclosing 404 for an unknown or cross-property stay, but only after the actor has passed the execute authorization gate.
5. Require a valid `frontdesk-checkout-execution` Sensitive Action Confirmation bound to the actor, company, property, intent, and session.
6. Enter the controlled transaction, acquire the approved locks, and independently revalidate every authoritative gate.

No stay query may occur before execute authorization. Boundary-view permission does not imply execute authority. An actor without execute authority receives a controlled authorization failure without a stay lookup; an authorized execute actor receives non-disclosing 404 for an unknown or cross-property stay. Browser-supplied property, actor, permission, or authorization state is never trusted. Sensitive confirmation is a prerequisite, not a permission grant, and all authorization and property membership must be revalidated server-side.

`frontdesk-checkout-execution` is not in `SensitiveActionConfirmationService::REGISTERED_INTENTS`; it requires a later explicit runtime registration package. `requireValidConfirmation()` currently validates actor, intent, property, company when present, server-side expiry, and session metadata. Confirmation remains reusable during its TTL unless the owning service invalidates it; checkout should consume/invalidate it after a successful command to narrow replay risk.

Classifications:

- Execute permission: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.
- Confirmation primitive: `SOURCE_PROVEN`.
- Checkout intent registration: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.
- Confirmation consumption/invalidation policy: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.

## 10.9 Idempotency Matrix

Future checkout execution must enforce at least these unique constraints:

- `property_id + idempotency_key` unique on checkout execution evidence.
- `front_desk_stay_id` unique for successful checkout execution evidence, or an equivalent partial unique constraint on `property_id + front_desk_stay_id` for successful terminal outcomes.

| Case | Required behavior | Classification |
| --- | --- | --- |
| First valid request | Lock required rows, revalidate gates, persist one immutable success evidence, transition stay, enqueue handoff transactionally. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Retry with same key and same stay | Return original outcome without new stay mutation or duplicate handoff. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Same key with different stay | Fail closed as idempotency conflict without object disclosure across property. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Different key after stay already checked out | Return terminal already-checked-out outcome or controlled duplicate error from `front_desk_stay_id` success uniqueness. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Two concurrent requests with same key | One wins; one replays/conflicts using locked idempotency/evidence row. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Two concurrent requests with different keys | One wins terminal stay lock; the other sees no longer `IN_HOUSE` and returns controlled duplicate/blocked result. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Retry after validation failure | May retry; no success evidence or stay transition exists. Avoid storing failed validation as successful idempotent outcome. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Retry after transaction rollback | May retry because no durable success evidence exists. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Retry after commit but before browser response | Same key returns committed evidence and terminal result. | IMPLEMENTATION_PREREQUISITE_REQUIRED |
| Retry after downstream event delivery failure | Same key returns committed checkout; outbox remains pending/failed and is replayed by handoff recovery, not by re-closing stay. | IMPLEMENTATION_PREREQUISITE_REQUIRED |

## 10.10 Locking and Atomicity

Proposed lock order for the future implementation:

1. Resolve and authorize actor, active company, and active property.
2. Lock `properties` row for the active property, matching `NightAuditRunStartService`.
3. Lock the current `property_business_dates` row and revalidate BD-A1 source identity.
4. Lock `front_desk_stays` row for the same property/stay.
5. Lock or read the latest immutable FD-B7 final review under the Front Desk transaction.
6. Lock or create the checkout execution idempotency/evidence identity for `property_id + idempotency_key`.
7. Revalidate Night Audit using the same Property and Business Date lock order; if Night Audit uses only `properties` and `property_business_dates`, checkout must share that order.
8. Obtain PMS Guest Ledger terminal financial attestation through an approved PMS-owned execution-time contract, not the current top-level read-only projection.
9. Obtain General Cashier terminal obligation attestation through an approved General Cashier-owned execution-time contract, not the current top-level read-only projection.
10. Persist Front Desk immutable checkout execution evidence, transition the stay terminally, and persist transactional handoff/outbox records.

Shared-lock findings:

- Front Desk-owned rows can be locked by Front Desk.
- Night Audit lock ordering is source-proven: `NightAuditRunStartService` locks `properties`, then `property_business_dates`, then active Night Audit runs.
- PMS Guest Ledger and General Cashier do not expose a checkout transaction lock/freeze/terminal attestation contract to Front Desk.
- No shared advisory-lock namespace is source-proven for checkout.

Unresolved race:

```text
1. Checkout reads NIGHT_AUDIT_LOCK_CLEAR.
2. Night Audit starts concurrently.
3. Night Audit obtains or creates an active close lock.
4. Checkout commits afterward.
```

The read-only projection alone is insufficient. Future checkout must either hold the same authoritative locks that Night Audit start holds or use a new approved cross-domain concurrency primitive. Otherwise Night Audit can become active after checkout's clear read and before checkout commit.

Classification: `CONCURRENCY_UNRESOLVED`.

## 10.11 Evidence Contract

Future immutable checkout execution evidence should include:

- `execution_id`
- `property_id`
- `front_desk_stay_id`
- `reservation_id` or canonical stay reference
- `actor_id`
- `idempotency_key`
- `latest_fd_b7_final_review_id`
- `property_business_date_id`
- `business_date`
- Night Audit source status/fingerprint, without raw `night_audit_run_id` unless the source owner approves exposure
- PMS Guest Ledger source fingerprint and source status, without duplicating folio/payment internals
- General Cashier source fingerprint and source status, without duplicating cashier internals
- Business Date source fingerprint
- optional Housekeeping/Engineering source references only when relevant to handoff, not as execution gates
- `occurred_at`
- minimal `source_snapshot`
- `source_hash`
- `result_status`

Immutability requirements:

- Application-level update/delete guard.
- PostgreSQL update/delete trigger.
- `property_id + idempotency_key` unique.
- successful `front_desk_stay_id` terminal outcome unique.
- Audit record linked to the checkout evidence.

Classification: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.

## 10.12 Post-Commit Handoff

Current repository event/outbox evidence is inventory-shaped: `outbox_messages` requires `source_inventory_transaction_id`, has `topic`, `payload`, `idempotency_key`, delivery state, and uniqueness over source inventory transaction/topic. This cannot be reused for checkout without a new approved generalized or Front Desk/PMS handoff contract.

The future event name should be chosen in the implementation package after checking current event naming conventions. ADR-087 examples such as `FrontDeskStayCheckedOut` or `GuestDepartureCompleted` are not source-proven names today.

Required handoff policy:

- Front Desk owns the event/handoff source after checkout commits.
- Payload contains minimal identifiers: property, stay, reservation/canonical stay reference, checkout execution id, business date, occurred_at, idempotency/correlation key.
- No raw guest PII or financial source snapshots in payload.
- Housekeeping owns room turnover and consumes the handoff to start/mark dirty/waiting-cleaning through a Housekeeping-owned command.
- Engineering consumes only if an approved maintenance/availability workflow requires it.
- Accounting should not consume Front Desk checkout directly for revenue/tax/GL; accounting consumes source-domain financial outputs.
- Publishing failure after checkout commit must leave a durable pending/failed handoff for retry and deduplication.

Classification: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.

## 10.13 Failure Recovery

Required behavior:

- Gate becomes blocked before lock acquisition: fail closed, no stay transition, no success evidence.
- Gate changes while waiting for a lock: revalidate after lock, fail closed if changed.
- Sensitive confirmation expires: fail before mutation, non-disclosing controlled error.
- Stay is no longer `IN_HOUSE`: fail closed or duplicate-terminal response; no partial closure.
- Latest B7 is no longer ready: fail closed; require new ready evidence.
- Night Audit lock becomes active: fail closed with Night Audit active blocker.
- Business Date changes: fail closed because source identity/fingerprint differs.
- Financial readiness regresses: fail closed; no fabricated readiness.
- Cashier readiness regresses: fail closed; no fabricated readiness.
- Duplicate execution: return original committed outcome or controlled conflict.
- Database deadlock: rollback, no partial stay closure, retry only through user/client idempotency.
- Transaction rollback: no success evidence; retry allowed.
- Response loss after commit: idempotent retry returns committed result.
- Downstream event delivery failure: checkout remains immutable; handoff retry/outbox recovery handles delivery.
- Partial infrastructure outage: fail closed unless already committed; no direct foreign-domain repair.

Classification: `IMPLEMENTATION_PREREQUISITE_REQUIRED`.

## 10.14 Security Review

- Authorization-first execution: the future command must resolve authenticated actor and server-owned active company/property context, authorize `frontdesk.checkout-execution.execute`, and only then query or resolve the requested stay.
- Property isolation: after the execute authorization gate passes, future execution must resolve `front_desk_stay_id` only inside the active property and return non-disclosing 404 for unknown or cross-property stays.
- Boundary-view separation: `frontdesk.checkout-execution-boundary.view` does not imply execute authority; an actor without execute permission receives a controlled authorization failure without a stay lookup.
- Sensitive confirmation: `frontdesk-checkout-execution` confirmation is a prerequisite bound to actor, company, property, intent, and session, not a permission grant.
- Server-owned authority: browser-supplied actor, property, permission, authorization state, or source status is never trusted, and authorization plus property membership must be revalidated server-side.
- Replay: current source has idempotency patterns, but checkout-specific uniqueness is missing.
- Session hijack: ADR-066 confirmation reduces risk, but checkout intent is not registered.
- Stale source evidence: current projections have fingerprints but no execution-time freeze/attestation.
- Confused deputy: browser must not supply actor/property/source status; future service must server-resolve all authority.
- Mass assignment: future input must be identifier-only and map to narrow request validation.
- Browser-controlled trusted state: prohibited by ADR-087 and this review.
- Duplicate execution: blocked until checkout evidence uniqueness exists.
- Audit forgery: future evidence and audit records must use server-owned actor, timestamps, and source hash.
- Cross-domain privilege escalation: Front Desk must not mutate PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date, Night Audit, Housekeeping, Engineering, Accounting, GL, AR, tax, or revenue tables.
- Sensitive-data minimization: handoff and evidence should store source fingerprints/statuses and approved references, not raw financial/guest internals.

Security classification: `IMPLEMENTATION_PREREQUISITE_REQUIRED` for checkout execution.

## 10.15 Prerequisite Package List

Source-backed prerequisites:

- Front Desk terminal checkout state and immutable checkout execution evidence foundation.
- Night Audit / checkout shared concurrency guard, using the source-proven Property and Business Date lock order or an approved shared primitive.
- PMS Guest Ledger checkout financial terminal attestation/freeze contract.
- General Cashier checkout obligation terminalization/attestation contract.
- Transactional Housekeeping room-turnover handoff/outbox or event contract for room turnover recovery.
- Sensitive Action Confirmation registration for `frontdesk-checkout-execution`.
- Checkout execution permission and command package, after all source-domain prerequisites are accepted.

Material boundary requiring a later ADR or equivalent Owner-approved architecture decision:

`NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION`

Reason: the current ADRs define read-only projections and ownership boundaries, but they do not fully define the cross-domain execution-time freeze/attestation and shared lock/orchestration primitive needed to atomically combine Front Desk checkout with PMS Guest Ledger, General Cashier, Business Date, and Night Audit.

## 10.16 Future Implementation Test Matrix

The later implementation package must include tests for:

- authorization;
- property isolation;
- 404 non-disclosure;
- sensitive confirmation;
- stale confirmation;
- confirmation invalidation after success;
- idempotency;
- same-key retry;
- same-key different-stay conflict;
- different-key concurrency;
- lock race with Night Audit start;
- Business Date race;
- financial regression race;
- cashier regression race;
- stay state race;
- immutable execution evidence;
- zero foreign-domain mutation;
- transactional event/outbox handoff;
- post-commit retry;
- rollback;
- audit evidence;
- PostgreSQL unique constraints;
- isolated concurrency proof with distinct PHP and PostgreSQL backend PIDs.

## 10.17 Final Decision

`CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`

Review complete: FD-B13 has reviewed and frozen the command contract, ownership boundaries, race findings, and prerequisite package categories.

Implementation authorized: No.

Checkout execution remains unauthorized, `can_execute=false` remains canonical runtime behavior, and no checkout execution route, permission, intent, command, migration, seeder, or runtime state change is introduced by FD-B13.
