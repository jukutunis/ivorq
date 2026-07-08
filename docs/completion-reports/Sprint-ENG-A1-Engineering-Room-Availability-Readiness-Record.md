# Sprint ENG-A1: Engineering Room Availability Readiness Record

## Status

```text
ENGINEERING_AVAILABILITY_READY
FRONT_DESK_ENGINEERING_AVAILABILITY_READY
ENGINEERING_AVAILABILITY_SOURCE_REQUIRES_CONTROLLED_EVIDENCE
```

## Boundary

ADR-085 defines Engineering-owned room technical availability evidence. PMS `room_blocks` remains PMS-owned and is not Engineering canonical evidence. Front Desk consumes Engineering room availability only through the server-side Engineering projection.

## Runtime Evidence

- `engineering_room_availability_blocks` records Engineering-owned active/released technical room blocks.
- PostgreSQL enforces one active Engineering availability block per property and room.
- Block and release actions resolve active property, room ownership, actor, status, timestamps, source references, idempotency context, and audit evidence on the server.
- Release requires `engineering-room-availability-release` sensitive confirmation with a server-generated evidence hash.
- Front Desk read access is limited to `frontdesk.engineering-availability.view` and does not grant block or release authority.

## Non-Goals Confirmed

No Front Desk room assignment, Front Desk check-in, `FrontDeskStay`, `FrontDeskRoomAssignment`, room move, check-out readiness, Housekeeping mutation, PMS room block takeover, Room master overwrite, Reservation mutation, Guest mutation, folio, payment, revenue, tax, AR, GL, Night Audit, final checkout, Cashier, Banking, Financial Period, Business Date, queue, worker, broker, event bus, outbox, external integration, generic framework, or Package C Cost Ledger runtime was introduced.

## Validation

PostgreSQL validation proved available, blocked, unknown, authorization-denied, Front Desk read-only, duplicate-active-block, confirmation-bound-release, replay-denied, cross-property-denied, and isolated concurrent block/release outcomes.
