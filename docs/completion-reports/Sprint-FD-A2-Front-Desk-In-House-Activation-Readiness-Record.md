# Sprint FD-A2 Front Desk In-House Activation Readiness Record

## Result

`FD_A2_DELIVERED`

`FRONT_DESK_FD_A3_READY`

FD-A2 delivers controlled initial room assignment evidence, `frontdesk-check-in`
sensitive confirmation, controlled check-in execution, and `IN_HOUSE`
FrontDeskStay state.

## Runtime Boundary

Front Desk owns `front_desk_stays` and immutable
`front_desk_room_assignments` as operational evidence. PMS Reservation and Guest,
Housekeeping Room readiness, and ADR-085 Engineering availability remain
read-only source dependencies.

No room move, check-out readiness, final checkout, folio, deposit, payment,
refund, revenue, tax, AR, GL, Night Audit, Cashier, Banking, Financial Period,
Business Date, queue, worker, broker, event bus, outbox, external integration,
generic framework, Package C Cost Ledger runtime, runner modification, or
fallback database was introduced.

## Validation Summary

PostgreSQL validation on `pgsql / ivorq_testing`:

- `FrontDeskRoomAssignmentTest`: 4 tests, 29 assertions, 0 failures, 0 errors.
- `FrontDeskCheckInTest`: 4 tests, 17 assertions, 0 failures, 0 errors.
- `FrontDeskWorkspaceRoomAssignmentTest`: 3 tests, 51 assertions, 0 failures, 0 errors.
- `FrontDeskAssignmentIsolatedConcurrencyProofTest`: 1 test, 26 assertions, 0 failures, 0 errors.

Accepted FD-A1 and ENG-A1 regressions passed. Banking master regression passed
with 194 tests, 0 failures, and 0 errors. Inventory Reversal remains accepted
inherited trigger-related test debt: 8 tests, 72 assertions, 0 failures,
2 inherited errors.
