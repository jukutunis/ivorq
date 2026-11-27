# PMS-P01 implementation record

Package: `PMS-P01_PROPERTY_SCOPED_RESERVATION_AND_GUEST_AUTHORITY_WITH_LEGACY_PATH_CONTAINMENT`

Contract: IVORQ Package Execution Contract v1.22

Canonical predecessor: `3e722ec8d7e7bb63594916024c07611d3563ac62`
Predecessor tree: `c0f4e6ae02e067e229ed67a4e2590915877e338b`

## Implemented authority

The PMS web group now requires the accepted active Property middleware. PMS service commands resolve an active actor, explicit server or canonical session Property, Company, active membership, and Property team permission before target lookup. The compatibility `active_property_id` must agree with canonical context. Sensitive commands do not use default Property fallback. Reservation, Guest, Rate Plan, and Room Block repositories use an explicit Property predicate and reject missing explicit context. The command services also scope targets and validate related Property ownership. Browser fields that attempt to control Property, reservation identity/status/nights, or room assignment are prohibited.

Reservation numbers are allocated inside a PostgreSQL transaction while holding the Property row lock. The allocator advances beyond all historical `RES-<digits>` numbers, including soft-deleted rows, without renumbering them. Existing ULID identity and the existing PostgreSQL unique index on `(property_id, reservation_number)` remain authoritative. The same lock serializes confirmation and commercial mutation against other PMS commands. Dates are validated after merging a partial amendment; nights are derived on the server. Confirmation uses the existing room-type availability rule with explicit Property predicates. No overbooking tolerance or guarantee rule was added.

Commercial mutations lock the reservation and consult one Property and reservation scoped PMS mutation-evidence contract. It consumes the Front Desk owner read contract plus retained legacy Stay, Folio, payment, deposit, refund, AR-transfer and assigned-room evidence, including historical and reversed source facts. This protects reservations that remain `CONFIRMED` after controlled stay creation and reservations with financial evidence before a Folio exists. Primary and accompanying guest links are Property checked, duplicate checked, and protected from changes after operational or financial evidence. Guest deletion fails when retained reservation, stay, folio, payment, deposit, refund, or AR evidence exists. Guest projections omit document identifiers and notes outside the guest detail/edit workspace, and reservation projections omit contact fields.

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

## PMS-P01 R1 independent-review correction

Reviewed implementation parent: `0ac6c9269c49366be065537d75c445b15a22c313` (tree `d155d861502886120c98ff335b77eacadf9be40f`). Draft PR #102 remains the review vehicle. This section closes the four R1 findings without activating another package.

| Finding | Correction and evidence |
| --- | --- |
| R1-01 retained financial evidence | `ReservationMutationEvidence` uses explicit Property and reservation IDs. It reads all retained rows in the PMS payment, deposit, refund, AR-transfer, Folio and legacy Stay sources and consumes the Front Desk owner read contract. Reservation mutations, guest links and server capabilities share it. Tests insert voided payment and deposit records without a Folio and prove amendment, confirmation, cancellation, deletion, no-show, guest add and guest remove deny without success audit or pivot mutation. Reversal and zero balance do not erase the source fact. |
| R1-02 transactional identity | Folio opening now locks Property → Reservation, checks a valid replay first, and derives a new window's guest from the locked reservation and a same-Property, active guest row locked after it. New opening and payment/deposit recording reject cancelled or no-show reservations under the reservation lock while valid replay remains allowed; refund and reversal paths remain untouched. PMS reservation create/update and guest add/remove/delete serialize on Property, then lock Reservation and Guest rows as appropriate and revalidate active identity. Pivot updates include Property and reservation predicates; malformed cross-Property or multiple primary pivot evidence fails closed. Three independent PostgreSQL two-process races prove Folio opening versus replacement, deletion versus reservation creation, and deletion versus accompanying association. No stale Folio guest or soft-deleted guest reference committed. |
| R1-03 fresh Property permission | Authority resolves a fresh active actor, verifies canonical Company/Property membership, refreshes Spatie's permission registry, and evaluates each permission in a temporary exact Property team context restored afterward. Capability permissions use the same path. Focused tests prove stale loaded roles and revoked role permissions cannot authorize a command, Property A roles do not authorize Property B, and valid same-Property authority still works. |
| R1-04 direct service input | `PmsServiceInput` applies Laravel validation at Reservation, Guest, Rate Plan and Room Block service commands. Reservation dates are exact calendar `YYYY-MM-DD` before nights are derived from the merged state; relative dates, invalid calendar dates, invalid enums, occupancy bounds and null required values fail before mutation. Same-Property guest and rate-plan references are rechecked inside the PMS transaction. Rate-plan amount, unique code and database precision and Room Block enum, owner room, local date-time and window order are checked at the command boundary. Tests prove invalid create/update inputs leave aggregates unchanged. |

R1 focused PostgreSQL evidence: PMS authority and isolated concurrency classes **14 tests / 152 assertions passed**; Guest Ledger Folio aggregate **41 / 89 passed**; new payment/deposit terminal replay checks **2 / 4 passed**. Total: **57 tests / 245 assertions passed**. The earlier accepted Front Desk and Guest Ledger **47 / 104**, Package 9 **2 / 25**, syntax, frontend, and scoped secret evidence remain reused from the original PMS-P01 review; no broad regression or frontend build was repeated for R1. Changed PHP syntax and `git diff --check` passed. No schema migration, historical data repair, dependency, seeder, permission or frontend change was needed.

## PMS-P01 R2 residual integrity correction

Reviewed R1 parent: `b2cb49d1ebad72e7dc4d6d4a780f6395a242e801` (tree `e0e6165460d69d1f83f7c324ea6f1612b02d3dca`). Draft PR #102 remains the review vehicle. No accepted R1 behavior or separate package was reopened.

| Finding | Correction |
| --- | --- |
| R2-01 historical guest relationship integrity | `ReservationGuestRelationshipIntegrity` is shared by reservation mutations, guest link/unlink commands, and new Guest Ledger Folio windows. Under Property and Reservation locks it locks all retained pivot rows, checks their Property, resolves every referenced Guest, rejects missing, soft-deleted, or foreign-Property guests, and rejects multiple or contradictory primary pivots. The reservation's primary Guest must itself be live and belong to the Property. Legacy reservations with no primary pivot, including those with accompanying pivots, remain operable when the stored primary identity is valid. No historical rows are repaired or removed. Guest deletion also sees retained reservation and pivot references even if their Property field is malformed. |
| R2-02 atomic compatibility command validation | Room Block create, amendment, release, expiry, and overdue expiry now serialize on the Property row. Create and amendment validate inside the transaction; amendment locks the target and validates the fully merged window and room after the lock. Overlap is checked after serialization and before write. Rate Plan create, amendment, and soft-delete use the same Property lock; reservation reference validation locks the active Rate Plan. Soft-delete refuses any retained reservation reference, including a soft-deleted reservation, so competing creation and deletion cannot commit a dangling reference. No pricing, guarantee, or overbooking policy was added. |

Focused PostgreSQL evidence: the three new R2 tests initially passed with **43 assertions**, including independent PHP/PostgreSQL sessions for two competing Room Block creates and Rate Plan delete versus reservation create. The affected authority, concurrency, and Guest Ledger Folio aggregate classes were run together: **58 tests, 57 passed, 279 assertions**, with one existing foreign-Room denial precedence test exposing a validation-order regression. The denial order was restored under the Property lock; that test and all three R2 tests were rerun and passed: **4 tests, 52 assertions**. A direct malformed-pivot Folio opening assertion was then added; its targeted historical-relationship test passed **1 test, 20 assertions**. The remaining tests from the combined run had already passed and were not repeated. This is targeted proof, not a claim of a fresh full-suite green run. No broad regression or frontend build was run.

No migration, dependency, permission, seeder, frontend, or historical data change was required. Production/live writes remained 0/0.

## Deferred boundaries

- PMS-P02: rate commitments, pricing, guarantee and cancellation policy, overbooking tolerance, waitlist conversion, and financial effects of stay-date changes. The existing source-supported room-type availability rule is the only confirmation inventory rule in PMS-P01.
- PMS-P03: accepted controlled whole-folio close/void authority and any remaining cashiering lifecycle work. Legacy containment is not completion of controlled closure.
- PMS-G1: not activated. Any unresolved commercial policy requires a later Owner decision.
- ADR-031 remains Proposed. This package adds no enterprise privacy runtime, consent administration, retention policy, identity-document upload, biometric collection, or cross-Property matching.

## Operational state

Finance and Procurement current implementable scopes remain COMPLETE; Security current implementable scope remains COMPLETE; Housekeeping turnover/readiness remains CLOSED. Inventory bounded universes are preserved. CostControl remains SYNCHRONOUS_TRANSITIONAL_ACTIVE, with no deferred production activation. AWS production enablement and live actions remain unauthorized (0/0). Production/live writes: NO. Operational First Trust: DISABLED. Operational MFA expansion, privileged-role assignment, and break-glass: NOT AUTHORIZED. Business Date and Financial Period: NOT STARTED. Pilot: NO. Cutover: NO. ADR-029, ADR-030, and ADR-031 remain Proposed. No PMS-P02, PMS-P03, PMS-G1, SEC-P03, or Package 21 activation occurred.
