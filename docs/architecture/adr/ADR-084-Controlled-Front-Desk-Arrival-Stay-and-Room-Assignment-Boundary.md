# ADR-084: Controlled Front Desk Arrival, Stay, and Room Assignment Boundary

## ADR Metadata
* **ADR Number:** ADR-084
* **ADR Title:** Controlled Front Desk Arrival, Stay, and Room Assignment Boundary
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-004 (Finance Module Boundary), ADR-029 (Security Roles and Permissions Governance), ADR-030 (Identity Authentication and Session Governance), ADR-034 (Night Audit and Hospitality Business Date Architecture), ADR-040 (IVORQ Interaction Layer Standard), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-085 (Engineering Room Availability and Block Evidence Boundary)

## Context

The existing PMS runtime contains reservation, guest, stay, room assignment, room status, and folio behaviors that predate the controlled operational boundaries used by later IVORQ packages. In particular, the legacy PMS check-in flow mutates Reservation status, mutates Room occupancy through listeners, and creates Folio records through a check-in listener. That behavior is not acceptable for Front Desk Package A, whose scope is operational arrival, room assignment evidence, controlled check-in evidence, in-house stay evidence, room move evidence, and read-only check-out readiness evidence.

Front Desk Package A must therefore define a narrow Front Desk-owned operational boundary that can read canonical source evidence but must not own or mutate commercial reservation lifecycle, Housekeeping readiness, Engineering blocks, Room master data, payment, folio, revenue, tax, Accounts Receivable, General Ledger, Financial Period, Business Date, Night Audit, or final check-out settlement.

## Decision

### Ownership

Front Desk owns:
- arrival eligibility projection;
- room assignment evidence;
- Front Desk stay lifecycle;
- check-in state;
- room move evidence;
- check-out readiness evidence.

Front Desk does not own:
- reservation commercial lifecycle;
- room master data;
- Housekeeping cleanliness/readiness status;
- Engineering room block or out-of-order status;
- payment;
- deposit;
- folio;
- revenue;
- tax;
- Accounts Receivable;
- General Ledger;
- Financial Period;
- Business Date;
- Night Audit;
- final check-out settlement.

### Canonical Source Ownership

* Property and tenant context are owned by Foundation Property and the authenticated active-property session.
* Guest identity and profile are owned by PMS Guest.
* Reservation identity, reservation dates, room-type requirement, and reservation status are owned by PMS Reservation.
* Room identity and room type are owned by Housekeeping Room master data in the current runtime.
* Housekeeping cleanliness and readiness status are owned by Housekeeping.
* Engineering room-block or out-of-order evidence must be read only from a source-proven Engineering-owned or approved room-block source. If that source cannot be proven safe, Front Desk assignment and check-in runtime must stop.
* The existing PMS Stay and PMS FrontDeskService are not the canonical Front Desk Package A stay boundary because they are coupled to Reservation status mutation, Room occupancy mutation, Folio creation, and final check-out behavior.

### State Machine

Front Desk Package A permits only these Front Desk stay states:

```text
ARRIVAL_READY
ROOM_ASSIGNED
CHECK_IN_CONFIRMATION_PENDING
IN_HOUSE
```

Allowed transitions:

```text
ARRIVAL_READY -> ROOM_ASSIGNED
ROOM_ASSIGNED -> CHECK_IN_CONFIRMATION_PENDING
CHECK_IN_CONFIRMATION_PENDING -> IN_HOUSE
IN_HOUSE -> IN_HOUSE
  when a valid ROOM_MOVE assignment evidence is posted
```

Not allowed in this package:

```text
IN_HOUSE -> CHECKED_OUT
IN_HOUSE -> CANCELLED
IN_HOUSE -> NO_SHOW
IN_HOUSE -> SETTLED
ROOM_ASSIGNED -> cancelled assignment
room assignment deletion
room move reversal
check-in reversal
final checkout
```

Check-out readiness is a derived read-only projection, not a mutable final status.

### FD-A4 Check-out Readiness Evidence

Check-out readiness is a deterministic server-resolved read-only projection delivered by `FrontDeskCheckoutReadinessProjectionService`. It evaluates operational readiness from non-financial source evidence and must never:

- execute final checkout;
- transition FrontDeskStay to CHECKED_OUT, SETTLED, DEPARTED, CANCELLED, or NO_SHOW;
- create, read, or evaluate folio, deposit, payment, refund, room charge, revenue, tax, AR, GL, Cashier, Banking, Financial Period, Business Date, or Night Audit state;
- mutate Housekeeping, Engineering, Room master, Guest, Reservation, or any source aggregate.

**Readiness statuses (non-financial):**
- `CHECKOUT_OPERATIONALLY_READY` — all operational non-financial evidence is consistent.
- `CHECKOUT_OPERATIONALLY_BLOCKED` — at least one operational blocker is source-proven.
- `CHECKOUT_READINESS_UNKNOWN` — a dependency is ambiguous or unsafe to evaluate.

**Permitted readiness rules:**
- READY requires: stay IN_HOUSE, current room exists and matches property, current assignment exists and matches stay, assignment matches current_room_id, guest and reservation identity resolvable, Housekeeping not blocking, Engineering AVAILABLE.
- BLOCKED when any of the above is violated or inconsistent.
- UNKNOWN only when a dependency is not configured, ambiguous, or unsafe.

**Financial boundary marker:**
Every readiness result must include exactly:
`Financial settlement: Not evaluated in Front Desk Package A.`

**Permission:**
- `frontdesk.checkout-readiness.view` — narrow, non-delegable read-only view authority.

**ADRs governing FD-A4:**
- ADR-084 covers the checkout readiness projection boundary.
- ADR-085 covers Engineering availability as a read-only dependency.
- ADR-066 covers any future sensitive confirmation needs (not activated here).

### Property And Tenant Isolation

Every Front Desk projection and controlled action must resolve the active property and tenant on the server. Browser-provided property, tenant, actor, audit timestamp, eligibility, status, readiness, or blocker values are ignored for authority. A reservation, guest, room, room block, Housekeeping status, Engineering availability fact, Front Desk stay, and Front Desk room assignment must all belong to the same active property. The active property must belong to the active tenant/company.

### Read-Only Housekeeping And Engineering Dependency

Front Desk may read Housekeeping readiness and Engineering availability evidence only where the source is canonical and safe to read. Front Desk must never:
- set Housekeeping room status;
- clear an Engineering block;
- overwrite Room master data;
- create Front Desk-owned Housekeeping or Engineering surrogate status.

When canonical readiness or Engineering availability evidence is absent, ambiguous, mutable without ownership protection, or unsafe to read, room assignment, check-in, and room move runtime must be deferred rather than assuming readiness.

### Server-Owned Room Eligibility

Room eligibility is resolved server-side from:
- active property;
- canonical Reservation;
- canonical Guest;
- canonical Room;
- reservation room-type rule;
- Housekeeping readiness evidence;
- Engineering availability evidence;
- active Front Desk occupancy conflict;
- actor permission;
- idempotency identity.

No browser field may determine eligibility, assignment kind, occurred_at, actor, property, room readiness, Engineering availability, stay state, or idempotency outcome.

### Authorization

Use existing roles only. Add narrow permissions only when missing:

```text
frontdesk.arrival.view
frontdesk.room-assignment.create
frontdesk.check-in.execute
frontdesk.in-house.view
frontdesk.room-move.execute
frontdesk.checkout-readiness.view
```

Finance, General Ledger, Accounts Payable, General Cashier, and Banking-only roles do not receive Front Desk action authority through Finance access.

### Sensitive Confirmation Policy

Controlled check-in must use intent:

```text
frontdesk-check-in
```

Controlled room move must use intent:

```text
frontdesk-room-move
```

Confirmation issuance must bind server-resolved property, reservation, guest, stay, assignment, room, readiness, Engineering availability, state hash, and idempotency evidence. Execution must re-resolve and compare the bound evidence. Replays, stale evidence, cross-property context, missing permission, and changed state fail closed.

### Idempotency And Source Correlation

Room assignment and room move posting must use a server-owned idempotency key. Within one property, the same idempotency key must produce at most one assignment outcome. When a source assignment identity exists, one property plus source identity must produce at most one immutable assignment record.

Each Front Desk assignment evidence record must correlate to the source Reservation, Guest, Room, room type where source-proven, Front Desk stay, actor, occurred_at, and idempotency identity.

### Audit Evidence

Controlled Front Desk actions must write audit evidence with server-resolved actor, property, event, target, source correlation, and occurred_at. Read-only projections do not create operational facts and must not mutate audit, source, Room, Housekeeping, Engineering, Reservation, Guest, Finance, Folio, Payment, Tax, AR, GL, Cashier, Banking, Financial Period, Business Date, Night Audit, queue, worker, broker, event bus, or outbox records.

### Concurrency And Row Locks

Every stay transition, initial room assignment, check-in execution, and room move must occur in one database transaction. The service must lock the Front Desk stay row and relevant Room rows. When two Room rows are locked, they must be locked in stable sorted identity order. PostgreSQL must enforce that one active Front Desk stay in `ROOM_ASSIGNED` or `IN_HOUSE` holds a given property plus current room at a time.

### Immutable Room-Assignment Evidence

Front Desk room assignment evidence is immutable. Initial assignment and room move create new records. Historical assignment evidence is not updated, deleted, reversed, or corrected in Package A.

Allowed assignment kinds:

```text
INITIAL_ASSIGNMENT
ROOM_MOVE
```

### Runtime Activation

```text
FD-A1: Arrival Queue and Reservation Eligibility
Allowed when implemented as a read-only projection from canonical Reservation,
Guest, Room, Housekeeping, and room-block evidence.

FD-A2: Room Assignment and Controlled Check-in
Allowed only after canonical and read-only room readiness, Engineering
availability, and occupancy protection are proven.

FD-A2 activation note:
Controlled initial room assignment and controlled check-in may use the
FrontDeskStay and FrontDeskRoomAssignment evidence boundary after ADR-085
Engineering availability is read through the server-side Engineering
availability projection. FD-A2 keeps Reservation, Guest, Room, Housekeeping,
Engineering, folio, payment, revenue, tax, AR, GL, Night Audit, final
checkout, Financial Period, and Business Date records read-only or untouched.
FD-A2 does not activate room move or check-out readiness.

FD-A3: In-House Stay and Controlled Room Move
Allowed only after FD-A2 passes assignment, check-in, sensitive confirmation,
and actual two-context PostgreSQL concurrency proof.

FD-A3 activation note:
In-house operational workspace and controlled room move may use the existing
FrontDeskStay and immutable FrontDeskRoomAssignment evidence boundary. ROOM_MOVE
assignment evidence is allowed only for an IN_HOUSE stay, must keep the stay
IN_HOUSE, and must update the current room through server-side controlled
execution. Room move execution locks the stay row and source/target Room rows in
stable sorted room identity order, revalidates Housekeeping readiness and ADR-085
Engineering availability for the target room, and preserves historical
INITIAL_ASSIGNMENT evidence. FD-A3 does not activate check-out readiness, final
checkout, folio, payment, revenue, tax, AR, GL, Night Audit, Financial Period,
Business Date, Housekeeping mutation, Engineering mutation, or Room master
mutation.

FD-A4: Check-out Readiness Evidence
Allowed only after FD-A3 passes room move and concurrency proof.

FD-A4 activation note:
Check-out readiness is a derived read-only projection from the FrontDeskStay
aggregate, current room assignment evidence, Housekeeping readiness, and
Engineering availability. It does not create a final state transition, does not
evaluate or mutate any financial source, and does not persist a separate
readiness record. The projection returns deterministic server-resolved evidence:
CHECKOUT_OPERATIONALLY_READY, CHECKOUT_OPERATIONALLY_BLOCKED, or
CHECKOUT_READINESS_UNKNOWN. Every result includes the mandatory non-financial
marker. A dedicated workspace panel displays readiness evidence, operational
blockers, Housekeeping and Engineering dependency state, current room evidence,
and the financial exclusion marker. No Check Out, Settle, Pay, Deposit, Refund,
Folio, Revenue, Tax, AR, GL, Night Audit, Cashier, Banking, Financial Period, or
Business Date control is rendered. FD-A4 does not activate final checkout, folio
settlement, or any financial behavior.
```

If any gate fails, downstream Front Desk work must stop. Do not bypass readiness evidence, weaken property isolation, create fake room-status sources, or introduce placeholder runtime pages.

## Explicit Non-Goals

This ADR does not authorize:
- folio creation;
- folio posting;
- room charge;
- deposit;
- payment;
- refund;
- payment allocation;
- invoice;
- Accounts Receivable;
- revenue recognition;
- tax calculation or tax posting;
- General Ledger journal;
- Night Audit posting;
- final check-out;
- settlement;
- cashier, banking, cashbook, Financial Period, or Business Date mutation;
- POS room charge;
- PMS financial integration;
- reservation cancellation;
- no-show settlement;
- rate override or rate calculation;
- discount or package posting;
- direct Housekeeping status mutation;
- direct Engineering status mutation;
- direct Room master overwrite;
- queue, worker, broker, event bus, outbox, external PMS or channel-manager integration;
- generic workflow, room-assignment, or stay engine.

## Consequences

* **Positive:** Establishes a controlled operational Front Desk boundary and prevents legacy PMS check-in side effects from leaking into Package A.
* **Negative:** Downstream assignment, check-in, in-house, room move, and readiness runtime must be deferred if canonical readiness or Engineering availability cannot be proven.
* **Tradeoffs:** Front Desk Package A may need narrow Front Desk-owned stay and room assignment aggregates because the existing PMS Stay is coupled to folio and room mutation behavior.
