# Sprint FD-A3 Front Desk Room Move Activation Readiness Record

## Result

`FD_A3_DELIVERED`

`FRONT_DESK_FD_A4_READY`

FD-A3 delivers an in-house stay workspace, read-only current-room and assignment
history evidence, `frontdesk-room-move` sensitive confirmation, controlled room
move execution, immutable `ROOM_MOVE` FrontDeskRoomAssignment evidence, and
continued `IN_HOUSE` FrontDeskStay state.

## Runtime Boundary

Front Desk owns operational in-house stay evidence and immutable room assignment
evidence. PMS Reservation and Guest, Housekeeping Room readiness, ADR-085
Engineering availability, Room master data, PMS room blocks, and financial
domains remain read-only or untouched source dependencies.

No check-out readiness, final checkout, folio, deposit, payment, refund,
revenue, tax, AR, GL, Night Audit, Cashier, Banking, Financial Period, Business
Date, queue, worker, broker, event bus, outbox, external integration, generic
framework, Package C Cost Ledger runtime, runner modification, or fallback
database was introduced.

## Validation Summary

PostgreSQL validation on `pgsql / ivorq_testing`:

- `FrontDeskInHouseWorkspaceTest`: 3 tests, 28 assertions, 0 failures, 0 errors.
- `FrontDeskRoomMoveTest`: 4 tests, 26 assertions, 0 failures, 0 errors.
- `FrontDeskRoomMoveWorkspaceTest`: 2 tests, 21 assertions, 0 failures, 0 errors.
- `FrontDeskRoomMoveIsolatedConcurrencyProofTest`: 1 test, 23 assertions, 0 failures, 0 errors.

Accepted FD-A2, FD-A1, and ENG-A1 regressions passed. Banking master regression
passed with 194 tests, 0 failures, and 0 errors. Inventory Reversal remains
accepted inherited trigger-related test debt: 8 tests, 72 assertions,
0 failures, 2 inherited errors.
