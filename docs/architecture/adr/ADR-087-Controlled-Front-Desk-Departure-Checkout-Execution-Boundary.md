# ADR-087: Controlled Front Desk Departure Checkout Execution Boundary

## ADR Metadata
* **ADR Number:** ADR-087
* **ADR Title:** Controlled Front Desk Departure Checkout Execution Boundary
* **Date:** 2026-07-11
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-004 (Finance Module Boundary), ADR-029 (Security Roles and Permissions Governance), ADR-034 (Night Audit and Hospitality Business Date Architecture), ADR-040 (IVORQ Interaction Layer Standard), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-067 (Finance Sensitive Decision Confirmation Enforcement), ADR-084 (Controlled Front Desk Arrival, Stay, and Room Assignment Boundary), ADR-085 (Engineering Room Availability and Block Evidence Boundary), ADR-086 (Controlled Housekeeping Room Readiness Lifecycle Boundary), ADR-088 (Guest Ledger, Folio and Hospitality Financial Subledger Architecture)

## Context

FD-B7 delivered the checkout final review evidence layer — the last Front Desk-owned operational checkpoint before a future checkout execution package. FD-B8 defines, for the first time, the explicit boundary between Front Desk operational readiness and the future checkout execution action.

The repository contains no existing ADR that defines the Front Desk checkout execution boundary. ADR-084 covers checkout readiness (non-financial operational evidence) but explicitly excludes checkout execution, folio, payment, settlement, cashier, business date, and Night Audit concerns. ADR-066 defines the Sensitive Action Confirmation primitive that future checkout execution will require. ADR-034 defines the approved Night Audit and Business Date architecture authority. BD-A1 now provides the accepted read-only Property Business Date projection. NA-A1 now provides the accepted authoritative Night Audit run and active close-lock projection.

This ADR establishes the governance-backed Front Desk checkout execution boundary as a read-only projection. It identifies which authoritative sources exist, which are missing, and what must be in place before a future checkout execution package can perform any checkout mutation.

Ownership clarified by ADR-088: guest folio settlement and canonical folio balance are owned by PMS Guest Ledger; guest payment-allocation command and transaction lifecycle are owned by PMS Cashiering; PMS Guest Ledger consumes accepted allocation evidence and owns the folio-side financial effect; cashier session and cash accountability are owned by General Cashier; AR transfer after accepted transfer is owned by Accounting / AR; revenue, tax, and GL posting are downstream accounting outcomes owned by Accounting; Finance governs and consumes financial outcomes.

## Current Implementation Synchronization Through FD-B12

FD-B9 accepted the PMS Guest Ledger GLF-D checkout settlement readiness projection as a read-only Front Desk dependency. FD-B10 accepted the GC-A1 General Cashier checkout obligation projection as a read-only Front Desk dependency. BD-A1 accepted the authoritative Property Business Date projection as a read-only source owned by Business Date / Night Audit.

FD-B11 integrates BD-A1 evidence read-only into the Front Desk checkout execution boundary and departure queue. FD-B11 is accepted and canonical. NA-A1 introduces the accepted authoritative Night Audit close-lock source. FD-B12 integrates NA-A1 close-lock evidence read-only into Front Desk; Front Desk does not start, abort, close, advance, reopen, run checkpoints, or mutate Night Audit.

In historical pre-Package-9 FD-B12 evidence, `NIGHT_AUDIT_LOCK_CLEAR` satisfied the Night Audit close-lock gate. `NIGHT_AUDIT_LOCK_ACTIVE` produced `NIGHT_AUDIT_CLOSE_LOCK_ACTIVE`, and unavailable source evidence produced `NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE`. `can_execute=false` and checkout-unavailable behavior were correct historical pre-Package-9 runtime markers because checkout execution was then separately unauthorized and unimplemented.

The original FD-B8 decision remains historical source truth for the first boundary package. Progressive implementation through FD-B9, FD-B10, FD-B11, and FD-B12 narrows source-unavailable blockers as authoritative sources are accepted, without transferring source-domain ownership to Front Desk.

## ADR-089 and Package 10 Synchronization Note

FD-B13 is accepted with historical pre-Package-9 verdict `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`. ADR-089 is Approved and canonical at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 is accepted and fast-forward merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. The GLF-E savepoint lock-continuity correction is accepted. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is accepted and merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`. FD-C1 scope was terminal Front Desk stay-state foundation and immutable checkout execution evidence foundation. FD-C1 introduced CheckedOut / CHECKED_OUT, immutable FrontDeskCheckoutExecution evidence, front_desk_checkout_executions, six RESTRICT foreign keys, application-level and PostgreSQL immutability, property-scoped idempotency, and one successful terminal outcome per stay. FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation is accepted and merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2` with accepted feature head `ce05c4217dcf763ccd5e308f66a01201975036a1`. Package 8 is accepted and merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e`. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`, with accepted feature/metadata SHA `df27dc8b7b33caf98ba2dd61305c652069780601` and final source SHA `77a82dd3951b7bb5804efb496b8939163ba2076d`.

Current runtime classification:

```text
PACKAGE_9_RUNTIME_ACCEPTED_AND_MERGED
CHECKOUT_EXECUTION_IMPLEMENTED
CAN_EXECUTE_SERVER_PROJECTED
PACKAGE_11_GOVERNANCE_ACTIVATION_AUTHORIZED
PACKAGE_11_RUNTIME_REQUIRES_SEPARATE_DRAFT_PR
NO_NEW_ADR_REQUIRED
EXISTING_HOUSEKEEPING_ADRS_ADR_040_ADR_086_AND_ADR_089_REMAIN_GOVERNING
```

`CAN_EXECUTE_SERVER_PROJECTED` is not universally true. It is resolved server-side per actor, Company, Property, stay, Business Date, Night Audit, financial, cashier, final-review, permission, confirmation, and idempotency context. Browser input cannot grant execution authority.

Package 9 current runtime truth: execute permission `frontdesk.checkout-execution.execute`; confirmation intent `frontdesk-checkout-execution`; authorization before requested-stay query; non-disclosing unknown and cross-Property stay behavior after authorization succeeds; final browser execution input remains identifier-only with route stay identifier plus `idempotency_key`; password is confirmation-preparation input only and is never execution input; checkout runs through the accepted controlled PostgreSQL transaction; same-key same-stay committed replay returns immutable evidence without duplicate consumption, execution, handoff, audit event, or stay transition; conflicting authoritative identity fails closed; Front Desk owns orchestration, terminal stay transition, execution evidence, Package 9 idempotency, and creation of the checkout-specific Housekeeping handoff; Housekeeping handoff is created as PENDING; Front Desk does not claim that Housekeeping room readiness changed; Housekeeping remains lifecycle owner of post-checkout room turnover; PMS, PMS Cashiering, General Cashier, Business Date, Night Audit, Housekeeping readiness, Engineering, Accounting, GL, AR, tax, revenue, and financial-period ownership remain unchanged; no external HTTP call is performed inside the checkout transaction; and Package 9 does not authorize Package 11 runtime automatically.

## Historical Pre-Package-9 Package 8 Governance Activation Synchronization

Contract Version 1.14 historically activated Package 8 - Checkout Sensitive Confirmation, Durable One-Time Consumption, and Execute Permission Foundation after accepted FD-C2. Canonical predecessor: `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`. No new ADR required - ADR-087 and ADR-089 remain governing.

Package 8 establishes the future checkout-specific Sensitive Action Confirmation intent `frontdesk-checkout-execution` and future checkout execute permission `frontdesk.checkout-execution.execute` as the next package boundary. Confirmation is not authorization and cannot grant execute permission. Authorization must occur before stay resolution. Browser-supplied property, actor, role, permission, or authorization state is never trusted. Unauthorized actors must not cause a stay query; unknown or cross-property stay remains non-disclosing only after authorization succeeds. Boundary-view permission does not imply execute permission, confirmation does not imply execute permission, and execute permission alone does not make checkout executable while Package 9 remains absent.

Future confirmation must be bound according to ADR-066, ADR-067, ADR-087, and ADR-089 semantics, including authenticated actor, active company, active property, session, checkout intent, Front Desk stay, checkout idempotency identity, expiration, and single-use lifecycle.

Future durable confirmation-consumption claim must occur inside the same PostgreSQL checkout transaction. It must be locked or atomically claimed after approved domain locks and execution-time attestations; occur before or atomically with the first persistent checkout mutation; bind to the authoritative checkout identity required by ADR-089; commit with immutable checkout execution evidence, terminal stay transition, and transactional Housekeeping handoff; roll back when the checkout transaction rolls back; prevent successful duplicate consumption through database-enforced uniqueness; fail closed when already consumed; never rely on session invalidation as authoritative consumption; allow committed same-idempotency replay to return immutable committed evidence without requiring or consuming another confirmation; and require fresh confirmation for a changed stay, changed idempotency identity, or new checkout attempt, except where rollback source truth leaves the original confirmation unconsumed and unexpired.

This governance activation contains no runtime source, migration, model, enum, service, permission seeder, Sensitive Action Confirmation registration, durable consumption persistence, command, route, controller, request class, policy, UI, React/TypeScript, test, baseline metadata, queue, worker, event, scheduler, WebSocket, external integration, checkout execution, stay transition, Housekeeping readiness mutation, or foreign-domain mutation.

Historical pre-Package-9 runtime status at Package 8 activation:

```text
historical pre-Package-9 can_execute=false
historical pre-Package-9 CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED
historical pre-Package-9 checkout unauthorized at runtime
```

## Package 10 Governance Synchronization and Package 11 Activation (2026-07-30)

Package 9 accepted validation evidence: Scenario I focused 3 tests / 130 assertions / 0 failures / 0 errors; Package 9 isolated concurrency 15 tests / 417 assertions / 0 failures / 0 errors; Package 9 focused final batch 41 tests / 708 assertions / 0 failures / 0 errors; Package 8 confirmation 33 tests / 346 assertions / 0 failures / 0 errors; adjacent NA-A2 + GLF-E + registered GC-A2 150 tests / 1447 assertions / 0 failures / 0 errors; exact Front Desk baseline 68 classes, 729 tests / 5539 assertions / 0 failures / 0 errors, exit code 0; RegressionBaselineManifestTest 34 tests / 1150 assertions / 0 failures / 0 errors; complete active baseline runner 14 passed / 0 failed / 0 skipped, 1205 tests / 9378 assertions, exit code 0; Inventory Reversal accepted inherited debt 8 tests / 72 assertions / 2 accepted errors.

The Package 9 browser execution request remains identifier-only:

```json
{
  "front_desk_stay_id": "ULID",
  "idempotency_key": "opaque client-generated key"
}
```

The browser must not submit or control trusted Company, Property, Tenant, actor, role, permission, membership, guest, reservation, room, stay status, business date, Night Audit, folio, amount, currency, payment, settlement, cashier, attestation, source fingerprint, confirmation, audit timestamp, execution, Housekeeping readiness, handoff, or retry outcome values. All trusted values remain server-resolved.

Package 11 - Housekeeping Checkout Handoff Consumption and Room Turnover Start - is not a continuation of Front Desk checkout execution. It is a Housekeeping-owned downstream consumption package for checkout-specific FD-C2 handoffs. A later runtime PR may consume eligible PENDING or retry-due FAILED handoffs, independently re-resolve source identity, verify immutable checkout evidence and handoff source integrity, create or replay one Housekeeping-owned room-turnover outcome through the existing canonical Housekeeping lifecycle, markDelivered only after the Housekeeping-owned outcome is committed or proven as an idempotent replay, markFailed with retry evidence after bounded failure, recover crash-after-Housekeeping-commit-before-DELIVERED without duplicate outcome, and prove the required concurrency/failure cases with real isolated PostgreSQL processes.

Package 11 must use actual canonical Housekeeping state names from source: readiness states `waiting_cleaning`, `cleaning`, `waiting_inspection`, `ready_for_sale`, `ready_for_arrival`, `ready_for_vip`, and `blocked`; readiness projections `HOUSEKEEPING_READY`, `HOUSEKEEPING_BLOCKED`, and `HOUSEKEEPING_UNKNOWN`; transition types `START_CLEANING`, `SUBMIT_INSPECTION`, and `RELEASE_READY`; and cleanliness statuses `dirty`, `clean`, and `inspected`. The FD-C2 handoff delivery states are `PENDING`, `CLAIMED`, `DELIVERED`, and `FAILED`. Package 11 must not freeze speculative room-turnover status names outside source-proven Housekeeping lifecycle values.

## Decision

### Ownership

Front Desk owns:
- The checkout execution boundary projection (read-only);
- The stay departure lifecycle command (future scope, not in FD-B8);
- Operational readiness evidence (FD-A4, FD-B1 through FD-B7).

Front Desk does not own:
- Financial settlement (folio balance, payment, deposit, refund, room charge);
- Cashier session lifecycle, cash accountability, or cashier handover;
- Housekeeping room readiness lifecycle;
- Engineering room availability and block lifecycle;
- Business Date lifecycle and Night Audit close-lock;
- Accounts Receivable transfer;
- Revenue recognition, tax calculation, or tax posting;
- General Ledger journal entries;
- Financial Period lifecycle.

### Immediate Prerequisite

Future checkout execution requires the latest FD-B7 final review evidence to be exactly `CHECKOUT_FINAL_REVIEW_READY`.

The following FD-B7 states must not permit execution:
- `CHECKOUT_FINAL_REVIEW_BLOCKED`
- `CHECKOUT_FINAL_REVIEW_REVIEWED`
- No FD-B7 evidence exists

### Required Authoritative Gates

Each gate must be re-resolved independently at execution time. The original FD-B8 package established the read-only checkout execution boundary; current implementation through FD-B12 evaluates accepted read-only dependencies where their source contracts now exist.

| Gate | Owner | Current Repository Availability | Historical Pre-Package-9 FD-B12 Behavior |
|---|---|---|---|
| Stay belongs to current property | Front Desk | Yes — FrontDeskStay.property_id | Resolved server-side |
| Stay is IN_HOUSE | Front Desk | Yes — FrontDeskStayStatusEnum | Verified |
| Latest FD-B7 is CHECKOUT_FINAL_REVIEW_READY | Front Desk | Yes — FrontDeskDepartureCheckoutFinalReview | Verified |
| Actor is authorized | Foundation/Authorization | Yes — Spatie Permission | Verified server-side |
| No existing completed checkout execution | Front Desk | Historical pre-Package-9 source lacked the final checkout execution package | Historical pre-Package-9 blocker: CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED |
| Folio balance settled or transferred | PMS Guest Ledger | Yes - GLF-D accepted checkout settlement readiness projection is available as a read-only Front Desk dependency through FD-B9 | GLF-D source status drives ready, blocked, review-required, or evidence-unavailable behavior without Front Desk mutating folios, payments, deposits, refunds, AR transfers, or ledger state |
| Guest payment allocation terminal and resolved | PMS Cashiering / PMS Guest Ledger | PMS Cashiering retains payment command and allocation lifecycle ownership; GLF-D consumes only accepted settlement/allocation evidence according to its source contract | Evaluated only through GLF-D settlement readiness evidence; Front Desk does not infer allocation lifecycle beyond the accepted GLF-D contract |
| Cashier session/accountability obligations resolved | General Cashier | Yes - GC-A1 checkout obligation readiness projection is available as a read-only Front Desk dependency through FD-B10 | GC-A1 source status drives clear, blocked, review-required, or evidence-unavailable behavior without Front Desk mutating cashier sessions, guest cash transactions, counts, handovers, reconciliation, or accountability completion |
| AR transfer accepted when applicable | Accounting / AR through PMS Guest Ledger evidence | GLF-D consumes accepted AR-transfer settlement evidence according to its source contract | Evaluated only through GLF-D settlement readiness evidence; Front Desk does not claim or mutate Accounting / AR decisions |
| Business date permits checkout | Business Date/Night Audit | Yes - BD-A1 authoritative Property Business Date projection | `BUSINESS_DATE_OPEN` satisfies the Business Date evidence gate; incomplete or unavailable source evidence maps to `BUSINESS_DATE_EVIDENCE_UNAVAILABLE`; Front Desk does not own lifecycle |
| No active Night Audit close lock | Night Audit | Yes - NA-A1 authoritative Night Audit close-lock projection | `NIGHT_AUDIT_LOCK_CLEAR` satisfies the gate; `NIGHT_AUDIT_LOCK_ACTIVE` blocks with `NIGHT_AUDIT_CLOSE_LOCK_ACTIVE`; unavailable source evidence blocks with `NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE`; Front Desk does not mutate Night Audit |
| Housekeeping readiness gate | Housekeeping | Yes — HousekeepingRoomReadinessProjectionService | NOT_REQUIRED for allowing guest departure unless a later approved ADR explicitly creates such a gate |
| Housekeeping post-checkout turnover handoff | Housekeeping | No — no checkout-complete handoff/outbox contract exists | IMPLEMENTATION_PREREQUISITE_REQUIRED before production checkout implementation; Housekeeping owns room-turnover recovery |
| Engineering availability gate | Engineering | Yes — EngineeringRoomAvailabilityProjectionService | NOT_REQUIRED for allowing guest departure; maintenance or Engineering blocks generally affect availability and turnover |
| Engineering checkout handoff | Engineering | No mandatory checkout handoff required | NOT_REQUIRED unless a later approved Engineering workflow consumes a future event |

### Downstream Accounting Ownership

- Accounting owns revenue recognition, tax journal posting, GL journals, and financial-period control.
- These are downstream accounting outcomes.
- They are not FD-B8 checkout-readiness gates.
- Accounting posting completion must not be inferred as a prerequisite for operational folio settlement unless a future approved ADR explicitly introduces such a gate.
- Accounting ownership does not transfer guest-folio ownership away from PMS Guest Ledger.

### Execution Boundary Statuses

The checkout execution boundary exposes these projection statuses:

- `EXECUTION_BOUNDARY_READY` - every mandatory gate is resolved and satisfied. This remains unreachable until a later checkout execution package exists; `can_execute` remains explicitly false in FD-B12.
- `EXECUTION_BOUNDARY_BLOCKED` - at least one mandatory gate is not satisfied and no review reason exists requiring explicit human review action.
- `EXECUTION_BOUNDARY_REVIEW_REQUIRED` - at least one gate requires a specific human review decision before execution can proceed (for example, FD-B7 CHECKOUT_FINAL_REVIEW_REVIEWED, GLF-D review-required evidence, or GC-A1 review-required evidence).

### Stay Resolution and Non-Disclosure

- **Future execution authorization precondition**: authorize `frontdesk.checkout-execution.execute` before querying or resolving the requested stay. An actor without execute authority receives controlled authorization failure without a stay lookup.
- **Unknown stay ID or cross-property stay for an authorized execute actor**: return 404. Do not disclose whether the stay exists in another property.
- **Same-property stay found but status is not IN_HOUSE**: return the boundary projection with `can_execute = false`, status `EXECUTION_BOUNDARY_BLOCKED`, blocker `STAY_NOT_IN_HOUSE`, and the actual server-resolved stay status. Do not fabricate B7 or other readiness evidence for non-IN_HOUSE stays.
- **Same-property IN_HOUSE stay**: proceed to evaluate all authoritative gates.

### Status Determination Precedence

1. If `blocker_codes` is empty → `EXECUTION_BOUNDARY_READY`, `can_execute = true`.
2. Else if `review_reasons` is not empty → `EXECUTION_BOUNDARY_REVIEW_REQUIRED`, `can_execute = false`.
3. Otherwise → `EXECUTION_BOUNDARY_BLOCKED`, `can_execute = false`.

Specific B7 mappings:
- `CHECKOUT_FINAL_REVIEW_REVIEWED` → `EXECUTION_BOUNDARY_REVIEW_REQUIRED` (review_reasons populated, can_execute false).
- `CHECKOUT_FINAL_REVIEW_BLOCKED` → `EXECUTION_BOUNDARY_BLOCKED` (can_execute false, no review_reasons).
- No B7 evidence → `EXECUTION_BOUNDARY_BLOCKED` (can_execute false, no review_reasons).
- `CHECKOUT_FINAL_REVIEW_READY` → does not automatically imply READY. Remaining unavailable gates keep can_execute false.

In the current Front Desk runtime, READY and execution remain unreachable because checkout execution is not implemented and `can_execute` remains explicitly false. FD-B12 consumes Night Audit close-lock evidence without fabricating checkout readiness.

### Stable Blocker Codes

When authoritative evidence is missing or incomplete, the current boundary returns stable, non-fabricated blocker codes:

| Blocker Code | Meaning | Source |
|---|---|---|
| `FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE` | The accepted GLF-D source cannot provide complete authoritative settlement evidence for the evaluated stay. This does not mean no GLF-D projection exists. | PMS Guest Ledger owns this evidence; PMS Cashiering owns guest payment allocation lifecycle; Accounting / AR owns accepted transfer decisions where applicable |
| `CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE` | The accepted GC-A1 source cannot provide complete authoritative cashier-accountability evidence. This does not mean no GC-A1 projection exists. | General Cashier owns this evidence |
| `BUSINESS_DATE_EVIDENCE_UNAVAILABLE` | The implemented BD-A1 source cannot provide complete authoritative Property Business Date evidence | Business Date / Night Audit owns this evidence through BD-A1 |
| `NIGHT_AUDIT_CLOSE_LOCK_ACTIVE` | The accepted NA-A1 Night Audit source reports an active close lock for the current open Property Business Date | Night Audit owns this evidence through NA-A1 |
| `NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE` | The accepted NA-A1 source cannot provide complete authoritative Night Audit close-lock evidence | Business Date / Night Audit owns this evidence through NA-A1 |
| `FD_B7_NOT_READY` | Latest FD-B7 final review is not CHECKOUT_FINAL_REVIEW_READY | Front Desk owns this evidence |
| `FD_B7_EVIDENCE_MISSING` | No FD-B7 final review evidence exists | Front Desk owns this evidence |
| `STAY_NOT_IN_HOUSE` | Stay is not in IN_HOUSE status | Front Desk owns this evidence |
| `CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED` | Historical pre-Package-9 checkout execution package had not been implemented | Historical pre-Package-9 evidence only |

### Future Command Contract

The future checkout execution action must use:

1. **Identifier-only browser inputs** — the browser submits only identifiers (stay ID, idempotency key). No amount, currency, status, actor, property, or financial data.
2. **Current-property server resolution** — property context resolved from the authenticated session.
3. **Independent revalidation** — every gate re-resolved at execution time, regardless of FD-B8 projection state.
4. **Dedicated idempotency key** — property_id + idempotency_key → at most one execution outcome.
5. **Database uniqueness/locking** — FrontDeskStay row locked FOR UPDATE; unique constraint on idempotency key.
6. **Dedicated Sensitive Action Confirmation purpose** — `frontdesk-checkout-execution` (new intent to be registered per ADR-066).
7. **Audit actor and source snapshot** — immutable audit evidence with actor, property, stay, occurred_at, source_hash.
8. **Transactional consistency** — all mutations within a single database transaction.
9. **Safe post-commit integration** — repository-approved event/outbox pattern for downstream domains.
10. **No direct foreign-domain table mutation** — Front Desk must not INSERT/UPDATE/DELETE in Finance, Cashier, Housekeeping, Engineering, Night Audit, or Business Date tables.

### Failure Behavior

1. All preconditions re-resolved at execution time — no stale cached readiness.
2. No partial stay closure — if any gate fails, the stay remains IN_HOUSE.
3. No checkout completion if authoritative financial gate fails.
4. No Housekeeping room-status mutation inside the Front Desk transaction.
5. No Engineering room-status mutation inside the Front Desk transaction.
6. No silent downgrade from blocked/review-required to ready.
7. Retries must remain idempotent — same idempotency key returns the original outcome.

### Non-Goals

FD-B8 does not:
- perform checkout;
- create checkout execution evidence;
- change stay status;
- close a stay;
- alter folio;
- process payment;
- mutate cashier state;
- post accounting entries;
- change room status;
- run Night Audit;
- bypass any authoritative domain;
- create a checkout write endpoint;
- add a POST/PUT/PATCH/DELETE route for checkout execution;
- implement Sensitive Action Confirmation for checkout (future scope).

### Permission Boundary

- `frontdesk.checkout-execution-boundary.view` — narrow, non-delegable read-only view authority.

Finance, Engineering, Housekeeping, Banking, GL, AR, Tax, Cashier, and Night Audit roles do not receive this permission by default.

### Read-Only Guarantee

The projection service:
- Performs only SELECT queries.
- Does not INSERT, UPDATE, or DELETE any record.
- Does not mutate FrontDeskStay status.
- Does not mutate FD-B3 through FD-B7 records.
- Does not mutate folio, payment, cashier, Housekeeping, Engineering, or business date state.
- Does not create audit log entries (read-only projections do not create operational facts).

### Concurrency Policy

FD-B8 is a read-only projection. No write path exists. `CONCURRENCY_NOT_REQUIRED_READ_ONLY_PROJECTION` is recorded and proven by the absence of any mutation path in the service, controller, or route layer.

## FD-B13 Checkout Execution Readiness Review Synchronization

Canonical reviewed SHA: `286c7f491ea82385ee369ff0020625448eea671d`

Contract version: 1.5

Readiness verdict:

```text
CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES
```

FD-B13 is a source-backed readiness review only. It creates no runtime checkout authority, does not set `can_execute=true`, does not register a checkout route, permission, sensitive-confirmation intent, command, service, migration, seeder, or UI action, and does not mutate Front Desk, PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date, Night Audit, Housekeeping, Engineering, Accounting, GL, AR, tax, revenue, or room-turnover state.

### Source-Proven Findings

- Front Desk source contains `FrontDeskStay` and the active stay enum, but `FrontDeskStayStatusEnum` currently contains only `ARRIVAL_READY`, `ROOM_ASSIGNED`, `CHECK_IN_CONFIRMATION_PENDING`, and `IN_HOUSE`. A checked-out/departed terminal state is not source-proven.
- FD-B3 through FD-B7 provide immutable operational evidence patterns with `property_id + idempotency_key`, `property_id + front_desk_stay_id + source_hash`, stay-row locks, app-level immutability, and PostgreSQL immutability triggers. Those packages do not close a stay.
- FD-B8 through FD-B12 provide historical pre-Package-9 read-only checkout execution boundary behavior. The boundary consumed GLF-D, GC-A1, BD-A1, and NA-A1 evidence without foreign-domain mutation and still returned `can_execute=false`.
- GLF-D proves settlement readiness as a PMS Guest Ledger-owned read-only projection, but it is a top-level `REPEATABLE READ, READ ONLY` transaction and rejects nested transaction participation. It does not provide checkout-time folio/settlement freeze or terminal financial attestation.
- GC-A1 proves General Cashier checkout-obligation readiness as a General Cashier-owned read-only projection, but it is also a top-level `REPEATABLE READ, READ ONLY` transaction and rejects nested transaction participation. It does not provide checkout-time cashier obligation terminalization.
- BD-A1 proves the current open Property Business Date projection. NA-A1 proves active close-lock projection and Night Audit run identity. Night Audit start locks the active `properties` row and `property_business_dates` row; current Front Desk boundary reads this evidence but does not share those locks.
- `SensitiveActionConfirmationService::REGISTERED_INTENTS` does not include `frontdesk-checkout-execution`; the future checkout intent remains unregistered.
- `PermissionSeeder` registers `frontdesk.checkout-execution-boundary.view`, but no `frontdesk.checkout-execution.execute` permission is source-proven.
- `routes/web.php` has only a GET `departure-checkout-execution-boundary` route. No POST, PUT, PATCH, or DELETE checkout execution route exists.
- Current `outbox_messages` schema is inventory-shaped through `source_inventory_transaction_id`; a checkout room-turnover handoff/outbox contract is not source-proven.

### Frozen Future Command Contract

The future browser input contract remains identifier-only:

```json
{
  "front_desk_stay_id": "ULID",
  "idempotency_key": "opaque client-generated key"
}
```

The browser must not supply property, company, tenant, actor, guest, reservation, room, status, business date, amount, currency, folio balance, payment result, cashier result, Night Audit result, source fingerprint, audit timestamp, or execution result.

Future authorization and sensitive-confirmation requirements are frozen as:

- execute permission: `frontdesk.checkout-execution.execute`, pending later runtime implementation;
- sensitive intent: `frontdesk-checkout-execution`, pending later runtime registration;
- boundary-view permission never implies execute permission;
- Finance, Cashier, Night Audit, Housekeeping, Engineering, Banking, GL, AR, tax, revenue, and broad operational roles do not receive execute authority by default;
- browser-supplied property, actor, permission, or authorization state is never trusted;
- sensitive confirmation is a prerequisite, not a permission grant;
- all authorization and property membership must be revalidated server-side.

Future checkout execution command ordering is frozen as:

1. Resolve the authenticated actor and server-owned active company/property context.
2. Authorize `frontdesk.checkout-execution.execute` before stay lookup.
3. Resolve `front_desk_stay_id` scoped to the active property.
4. Return non-disclosing 404 where applicable, but only after the actor has passed the execute authorization gate.
5. Perform pre-transaction confirmation validation.
6. Enter the controlled PostgreSQL transaction.
7. Acquire Property, Business Date, stay, idempotency, Night Audit, PMS, and General Cashier locks in the approved order.
8. Obtain and validate all execution-time attestations.
9. Revalidate the same confirmation identity immediately before mutation.
10. Lock or atomically claim the confirmation identity as unconsumed and bind it to this property, stay, and idempotency identity.
11. Persist immutable checkout evidence, including the confirmation identity or safe fingerprint.
12. Persist the terminal stay transition.
13. Persist the transactional Housekeeping handoff/outbox record.
14. Commit the transaction.
15. Perform idempotent session confirmation cleanup after commit.

The future checkout Sensitive Action Confirmation package must include `CHECKOUT_CONFIRMATION_ONE_TIME_CONSUMPTION_REQUIRED`. Durable confirmation consumption occurs inside the checkout transaction; session invalidation occurs only after commit as idempotent cleanup and is not authoritative durable consumption. The future contract must lock or atomically claim the authoritative confirmation identity, verify actor/company/property/intent/session/expiry/unconsumed state, bind consumption to property, stay, and idempotency identity, commit the consumption claim with checkout evidence and handoff, and roll back the claim when the checkout transaction rolls back. Database-enforced duplicate protection is required through a unique successful checkout confirmation identity, a unique safe confirmation fingerprint on successful checkout evidence, or both. Same-idempotency replay of an already committed checkout requires and consumes no new confirmation; it returns immutable committed checkout evidence and must not attempt to consume the confirmation again. A new checkout attempt, changed stay, changed idempotency identity, or uncommitted retry requires a fresh unconsumed confirmation unless a rollback also rolled back the durable consumption claim and the same confirmation remains unexpired. A confirmation bound to one checkout idempotency identity must not authorize a different identity.

Future idempotency requirement is frozen as:

- `property_id + idempotency_key` permits at most one checkout execution outcome;
- successful terminal checkout evidence must also prevent a second successful checkout for the same `front_desk_stay_id`;
- retries after response loss must return the committed immutable outcome;
- same-idempotency replay after a committed checkout must not require or consume a new sensitive confirmation because no new mutation occurs;
- same-idempotency replay after a committed checkout must not attempt to consume the confirmation again;
- a new checkout attempt, changed stay, changed idempotency identity, or uncommitted retry requires fresh unconsumed confirmation unless the uncommitted retry follows a rollback that also rolled back the durable consumption claim and the same confirmation remains unexpired;
- a confirmation bound to one checkout idempotency identity must not authorize a different identity;
- downstream handoff retries must not re-close the stay.

Future mutation and handoff requirements are frozen as:

- no direct foreign-domain table mutation;
- no partial stay closure;
- no fabricated financial, cashier, Business Date, or Night Audit readiness;
- post-commit Housekeeping room-turnover handoff must go through an approved event/outbox or owner-domain handoff contract before checkout implementation can be production-safe;
- Housekeeping owns post-checkout room-turnover transition, and Front Desk must not mutate Housekeeping room readiness directly;
- Housekeeping readiness is not currently a checkout execution gate;
- Engineering availability is not currently a checkout execution gate;
- Engineering checkout handoff is optional and requires separate approval when applicable.

### Unresolved Prerequisites

FD-B13 records these source-backed prerequisite categories before checkout implementation can be authorized:

- Front Desk terminal checkout state and immutable checkout execution evidence foundation.
- Night Audit / checkout shared concurrency guard using the source-proven Property and Business Date lock order or a new approved shared primitive.
- PMS Guest Ledger checkout financial terminal attestation/freeze contract.
- General Cashier checkout obligation terminalization/attestation contract.
- Transactional Housekeeping room-turnover handoff/outbox or event contract for room-turnover recovery.
- Sensitive Action Confirmation registration for `frontdesk-checkout-execution`, including durable one-time confirmation consumption for checkout successful use.
- Checkout execution permission and command package after all prerequisite owner-domain packages are accepted.

Material architecture boundary:

```text
NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION
```

Reason: current ADRs establish read-only projections and ownership boundaries, but they do not fully define the cross-domain execution-time freeze/attestation and shared lock/orchestration primitive required to atomically combine Front Desk checkout with PMS Guest Ledger, General Cashier, Business Date, and Night Audit.

Checkout implementation remains unauthorized.

## Consequences

* **Positive:** Establishes a clear, governance-backed boundary that tells Front Desk operators exactly why checkout cannot proceed, without fabricating readiness.
* **Positive:** Identifies all missing authoritative sources explicitly, guiding future PMS Guest Ledger, PMS Cashiering, General Cashier, Accounting / AR, Accounting, Business Date, and Night Audit package implementation.
* **Negative:** Front Desk still cannot execute checkout because checkout execution is not implemented and `can_execute` remains explicitly false. This is correct behavior because it prevents premature checkout.
* **Tradeoffs:** The projection is intentionally pessimistic. It defers to authoritative domains rather than inventing settlement evidence.
