# ADR-084: Controlled Front Desk Arrival, Stay, and Room Assignment Boundary

## ADR Metadata
* **ADR Number:** ADR-084
* **ADR Title:** Controlled Front Desk Arrival, Stay, and Room Assignment Boundary
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-004 (Finance Module Boundary), ADR-029 (Security Roles and Permissions Governance), ADR-030 (Identity Authentication and Session Governance), ADR-034 (Night Audit and Hospitality Business Date Architecture), ADR-040 (IVORQ Interaction Layer Standard), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary)

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

FD-A3: In-House Stay and Controlled Room Move
Allowed only after FD-A2 passes assignment, check-in, sensitive confirmation,
and actual two-context PostgreSQL concurrency proof.

FD-A4: Check-out Readiness Evidence
Allowed only after FD-A3 passes room move and concurrency proof.
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
