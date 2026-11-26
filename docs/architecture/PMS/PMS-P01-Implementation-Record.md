# PMS-P01 implementation record

Package: `PMS-P01_PROPERTY_SCOPED_RESERVATION_AND_GUEST_AUTHORITY_WITH_LEGACY_PATH_CONTAINMENT`

Contract: IVORQ Package Execution Contract v1.22

Canonical predecessor: `3e722ec8d7e7bb63594916024c07611d3563ac62`
Predecessor tree: `c0f4e6ae02e067e229ed67a4e2590915877e338b`

## Implemented authority

The PMS web group now requires the accepted active Property middleware. PMS service commands resolve an active actor, explicit server or canonical session Property, Company, active membership, and Property team permission before target lookup. The compatibility `active_property_id` must agree with canonical context. Sensitive commands do not use default Property fallback. Reservation, Guest, Rate Plan, and Room Block repositories use an explicit Property predicate and reject missing explicit context. The command services also scope targets and validate related Property ownership. Browser fields that attempt to control Property, reservation identity/status/nights, or room assignment are prohibited.

Reservation numbers are allocated inside a PostgreSQL transaction while holding the Property row lock. The allocator advances beyond all historical `RES-<digits>` numbers, including soft-deleted rows, without renumbering them. Existing ULID identity and the existing PostgreSQL unique index on `(property_id, reservation_number)` remain authoritative. The same lock serializes confirmation and commercial mutation against other PMS commands. Dates are validated after merging a partial amendment; nights are derived on the server. Confirmation uses the existing room-type availability rule with explicit Property predicates. No overbooking tolerance or guarantee rule was added.

Commercial mutations lock the reservation and reject existing Front Desk stay, legacy stay, folio, or legacy assigned-room evidence. The Front Desk owner exposes a read-only reservation evidence contract. This protects reservations that remain `CONFIRMED` after controlled stay creation. Primary and accompanying guest links are Property checked, duplicate checked, and protected from changes after operational or financial evidence. Guest deletion fails when retained reservation, stay, folio, payment, deposit, refund, or AR evidence exists. Guest projections omit document identifiers and notes outside the guest detail/edit workspace, and reservation projections omit contact fields.

Reservation creation, amendment, confirmation, cancellation, no-show, and deletion write minimized immutable audit records. Cancellation reason is durable in the audit record and omitted from the general activity description. Guest names are omitted from reservation creation activity. No new audit ledger or generic workflow was introduced.

## Legacy path disposition

| Path | Disposition | Enforced result |
| --- | --- | --- |
| PMS reservation create/amend/status | SAFE_BOUNDED_COMPATIBILITY | Property-authorized commercial commands, locked lifecycle and evidence checks |
| PMS reservation `assigned_room_id` create/update and `assign-room` | MUST_FAIL_CLOSED | Browser field rejected; direct service returns 409; controlled Front Desk workspace guides operators |
| PMS reservation `check-in` | MUST_FAIL_CLOSED | Direct service and HTTP path return 409 before legacy Stay, Room, Folio, or event effects |
| PMS stay `check-out` | MUST_FAIL_CLOSED | Direct service and HTTP path return 409; Package 9 remains terminal authority |
| PMS folio whole close/void | BLOCKED_BY_ARCHITECTURE | Direct service and HTTP path return 409; controlled closure/void remains PMS-P03 work |
| Existing legacy Stay and Folio records | READ_ONLY_HISTORICAL | No migration, cleanup, or reconciliation; dashboard labels legacy stay count separately |
| Controlled Guest Ledger open/post/item void | REQUIRES_CONTROLLED_DELEGATION | Existing `GuestLedgerFolioAggregateService` remains the only controlled aggregate command |

The contained legacy commands cannot dispatch their prior `GuestCheckedIn` or `GuestCheckedOut` events. Their listeners therefore cannot mutate Room occupancy or create a Folio through these paths. Existing Front Desk room assignment, check-in, checkout, confirmation, idempotency, financial attestation, and housekeeping handoff contracts were not weakened.

## Workspaces

The existing PMS dashboard distinguishes controlled `FrontDeskStay` in-house facts from historical `Stay` records. The vacant-room card is labeled operational and is not presented as commercial booking availability. Arrival and departure projections exclude incompatible stay states and navigate to controlled Front Desk workspaces. Reservation detail actions consume server-projected eligibility and show Property context, error state, accompanying guest search/link controls, and controlled Front Desk guidance. Reservation create/edit forms no longer submit a legacy assigned room. The Folio page no longer offers whole-folio close/void.

## Validation and schema

- Focused PostgreSQL authority and lifecycle class: 7 tests, with direct service and HTTP denial, Property isolation, guest relationships, durable audit, and legacy containment. Combined with the concurrency proof: 8 tests, 76 assertions passed.
- Isolated PostgreSQL two-process proof: separate PHP and database sessions create two reservations with distinct numbers and complete primary-link/audit evidence; competing cancel and no-show commands produce one committed terminal transition and one rejected transition.
- Focused accepted Front Desk arrival and Guest Ledger aggregate suites: 47 tests, 104 assertions passed.
- Package 9 checkout execution foundation: 2 tests, 25 assertions passed.
- Changed PHP syntax: 37 files passed. Scoped Pint and diff checks passed. Vite frontend build passed. TypeScript emitted 57 repository diagnostics and none in changed PMS frontend files. Scoped secret scan evidence is recorded in the independent-review bundle.

No migration is required: PostgreSQL already enforces the Property reservation-number unique index and the reservation/guest pivot unique index. Historical records were not rewritten. Dependency delta is 0/0. RoleSeeder and PermissionSeeder are unchanged.

## Deferred boundaries

- PMS-P02: rate commitments, pricing, guarantee and cancellation policy, overbooking tolerance, waitlist conversion, and financial effects of stay-date changes. The existing source-supported room-type availability rule is the only confirmation inventory rule in PMS-P01.
- PMS-P03: accepted controlled whole-folio close/void authority and any remaining cashiering lifecycle work. Legacy containment is not completion of controlled closure.
- PMS-G1: not activated. Any unresolved commercial policy requires a later Owner decision.
- ADR-031 remains Proposed. This package adds no enterprise privacy runtime, consent administration, retention policy, identity-document upload, biometric collection, or cross-Property matching.

## Operational state

Finance and Procurement current implementable scopes remain COMPLETE; Security current implementable scope remains COMPLETE; Housekeeping turnover/readiness remains CLOSED. Inventory bounded universes are preserved. CostControl remains SYNCHRONOUS_TRANSITIONAL_ACTIVE, with no deferred production activation. AWS production enablement and live actions remain unauthorized (0/0). Production/live writes: NO. Operational First Trust: DISABLED. Operational MFA expansion, privileged-role assignment, and break-glass: NOT AUTHORIZED. Business Date and Financial Period: NOT STARTED. Pilot: NO. Cutover: NO. ADR-029, ADR-030, and ADR-031 remain Proposed. No PMS-P02, PMS-P03, PMS-G1, SEC-P03, or Package 21 activation occurred.
