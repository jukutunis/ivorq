# ADR-085: Engineering Room Availability and Block Evidence Boundary

## ADR Metadata
* **ADR Number:** ADR-085
* **ADR Title:** Engineering Room Availability and Block Evidence Boundary
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001, ADR-002, ADR-029, ADR-030, ADR-066, ADR-084

## Context

ADR-084 requires Front Desk room assignment and check-in to stop until Engineering room-block or out-of-order evidence is source-proven and read-only to Front Desk. Repository inspection shows `PMS room_blocks` is PMS-owned: it has PMS models, PMS service, PMS permissions, PMS routes, PMS UI, and PMS availability behavior. Engineering work orders and preventive maintenance records are property-scoped and may be room-linked, but their lifecycle statuses describe maintenance work, not canonical room technical availability.

## Decision

Engineering owns:
- room-linked maintenance availability evidence;
- Engineering out-of-order and out-of-service block evidence;
- room technical availability projection;
- controlled Engineering release and clearance evidence where implemented.

Engineering does not own:
- Housekeeping cleanliness readiness;
- PMS reservation lifecycle;
- Front Desk room assignment;
- Front Desk check-in;
- Room master data;
- folio;
- payment;
- revenue;
- tax;
- AR;
- GL;
- Night Audit;
- final check-out settlement.

`PMS room_blocks` is not Engineering-owned canonical evidence unless source inspection proves the repository already treats it as Engineering-owned. Current source inspection did not prove that ownership.

Front Desk may read Engineering availability only through the server-side Engineering availability projection. Front Desk must never write or clear Engineering blocks.

## Source Ownership Matrix

| Source | Owner | Engineering Use | Front Desk Use |
| --- | --- | --- | --- |
| Property and tenant context | Foundation Property | Server-resolved scope | Server-resolved scope |
| Room and room type | Housekeeping Room master data | Read and validate room identity only | Read through approved projections only |
| PMS `room_blocks` | PMS | Not canonical Engineering evidence | Not Engineering availability evidence |
| Engineering work order | Engineering | Optional source reference only | Not read directly |
| Engineering preventive maintenance | Engineering | Optional source reference only | Not read directly |
| Engineering room availability block | Engineering | Canonical technical block evidence | Read-only projection only |
| Housekeeping readiness | Housekeeping | Not owned or mutated | Read through Housekeeping boundary |

## Availability Status Semantics

```text
ENGINEERING_AVAILABLE
```

Engineering source is configured and no active Engineering availability block exists for the active property and room.

```text
ENGINEERING_BLOCKED
```

An active Engineering-owned availability block exists for the active property and room, or a future source-proven Engineering maintenance state is explicitly classified as room-blocking by ADR-approved policy.

```text
ENGINEERING_UNKNOWN
```

The room is missing, outside the active property, cross-tenant, source configuration is unsafe, or the caller is not allowed to resolve the projection.

Front Desk FD-A2 may later treat only `ENGINEERING_AVAILABLE` as assignment-eligible. `ENGINEERING_BLOCKED` and `ENGINEERING_UNKNOWN` are assignment-blocking.

## Block And Release State Policy

Engineering room availability blocks use only:

```text
ACTIVE
RELEASED
```

An active block means the room is technically unavailable for Front Desk assignment/check-in. A released block no longer blocks the room. Block creation and release are server-side controlled actions. Browser input cannot set property, status, actor, timestamps, availability outcome, audit evidence, or source ownership.

## Property And Room Isolation

Every projection, block, and release resolves the active property on the server. The room must belong to the active property, and the active property must belong to the active tenant/company when company context is present. Cross-property and cross-tenant source references fail closed.

## Authorization Boundary

Use narrow permissions:

```text
engineering.room-availability.view
engineering.room-availability.block
engineering.room-availability.release
frontdesk.engineering-availability.view
```

Engineering view/block/release permissions do not grant Front Desk assignment or check-in authority. Front Desk view permission does not grant Engineering mutation authority. Finance, GL, AP, Cashier, Banking, Tax, AR, and Revenue roles do not receive Engineering block/release authority through finance access.

## Sensitive Confirmation Policy

Engineering availability release is operationally sensitive and uses:

```text
engineering-room-availability-release
```

Confirmation binds the active property, room, block id, current block status, block reason, source type/id, release reason, and idempotency context through a server-generated evidence hash. Execution re-resolves and compares this evidence. Replays, stale evidence, cross-property context, missing permission, and changed block/source state fail closed.

## Immutability And Evidence

The block record is the Engineering evidence aggregate. Create evidence is append-like and server-authored. Release updates only the lifecycle release fields required to close the active block and writes audit evidence. Historical source, started_at, started_by, and block_reason are not browser-controlled.

## Idempotency

Within one property, `idempotency_key` produces at most one block/release outcome. Duplicate calls with the same property and key return the existing controlled outcome where the operation and target are compatible; incompatible reuse fails closed.

## Concurrency And Lock Policy

Block and release actions run in a short PostgreSQL transaction. The service locks the active Room row before evaluating or mutating Engineering block evidence. Release also locks the Engineering block row. PostgreSQL enforces at most one active Engineering availability block per property and room.

## Front Desk Read Boundary

Front Desk may consume only the read-only Engineering projection. It must not write, release, clear, or infer Engineering availability by directly reading PMS `room_blocks`, Engineering work orders, PM tasks, Housekeeping readiness, Room master data, Reservation, Guest, Folio, Finance, GL, AR, Tax, Cashier, Banking, Financial Period, Business Date, queue, worker, broker, event bus, outbox, or integration state.

## Explicit Non-Goals

This ADR does not authorize Front Desk room assignment, Front Desk check-in, `FrontDeskStay`, `FrontDeskRoomAssignment`, room move, check-out readiness, Housekeeping mutation, PMS room-block ownership takeover, Room master overwrite, Reservation mutation, Guest mutation, folio, deposit, payment, refund, revenue, tax, AR, GL, Night Audit, final checkout, Cashier, Banking, Financial Period, Business Date, queue, worker, broker, event bus, outbox, external integration, generic room availability framework, generic maintenance framework, or Package C Cost Ledger runtime.

## Consequences

Front Desk FD-A2 can start only after PostgreSQL tests prove the Engineering availability projection is source-proven, property-scoped, room-scoped, server-resolved, read-only to Front Desk, protected from browser outcome control, and safe under concurrent Engineering block/release attempts.
