# ADR-087: Controlled Front Desk Departure Checkout Execution Boundary

## ADR Metadata
* **ADR Number:** ADR-087
* **ADR Title:** Controlled Front Desk Departure Checkout Execution Boundary
* **Date:** 2026-07-11
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-004 (Finance Module Boundary), ADR-029 (Security Roles and Permissions Governance), ADR-034 (Night Audit and Hospitality Business Date Architecture), ADR-040 (IVORQ Interaction Layer Standard), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-067 (Finance Sensitive Decision Confirmation Enforcement), ADR-084 (Controlled Front Desk Arrival, Stay, and Room Assignment Boundary), ADR-085 (Engineering Room Availability and Block Evidence Boundary), ADR-086 (Controlled Housekeeping Room Readiness Lifecycle Boundary), ADR-088 (Guest Ledger, Folio and Hospitality Financial Subledger Architecture)

## Context

FD-B7 delivered the checkout final review evidence layer — the last Front Desk-owned operational checkpoint before a future checkout execution package. FD-B8 defines, for the first time, the explicit boundary between Front Desk operational readiness and the future checkout execution action.

The repository contains no existing ADR that defines the Front Desk checkout execution boundary. ADR-084 covers checkout readiness (non-financial operational evidence) but explicitly excludes checkout execution, folio, payment, settlement, cashier, business date, and Night Audit concerns. ADR-066 defines the Sensitive Action Confirmation primitive that future checkout execution will require. ADR-034 defines the approved Night Audit and Business Date architecture authority; no Business Date or Night Audit runtime implementation exists.

This ADR establishes the governance-backed Front Desk checkout execution boundary as a read-only projection. It identifies which authoritative sources exist, which are missing, and what must be in place before a future checkout execution package can perform any checkout mutation.

Ownership clarified by ADR-088: guest folio settlement and canonical folio balance are owned by PMS Guest Ledger; guest payment-allocation command and transaction lifecycle are owned by PMS Cashiering; PMS Guest Ledger consumes accepted allocation evidence and owns the folio-side financial effect; cashier session and cash accountability are owned by General Cashier; AR transfer after accepted transfer is owned by Accounting / AR; revenue, tax, and GL posting are downstream accounting outcomes owned by Accounting; Finance governs and consumes financial outcomes.

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

Each gate must be re-resolved independently at execution time. FD-B8 evaluates available gates now as a read-only projection.

| Gate | Owner | Available in Repository | FD-B8 Behavior |
|---|---|---|---|
| Stay belongs to current property | Front Desk | Yes — FrontDeskStay.property_id | Resolved server-side |
| Stay is IN_HOUSE | Front Desk | Yes — FrontDeskStayStatusEnum | Verified |
| Latest FD-B7 is CHECKOUT_FINAL_REVIEW_READY | Front Desk | Yes — FrontDeskDepartureCheckoutFinalReview | Verified |
| Actor is authorized | Foundation/Authorization | Yes — Spatie Permission | Verified server-side |
| No existing completed checkout execution | Front Desk (future) | No — future checkout execution package | Blocked: CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED |
| Folio balance settled or transferred | PMS Guest Ledger | Folio/FolioItem models exist in PMS module but no authoritative PMS Guest Ledger settlement projection exists | Blocked: FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE |
| Guest payment allocation terminal and resolved | PMS Cashiering | No authoritative PMS Cashiering guest payment-allocation projection exists | Blocked: FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE |
| Cashier session/accountability obligations resolved | General Cashier | General Cashier module exists but no Front Desk-accessible cashier session/accountability projection exists | Blocked: CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE |
| AR transfer accepted when applicable | Accounting / AR | No Front Desk-accessible accepted-transfer projection exists | Blocked: FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE |
| Business date permits checkout | Business Date/Night Audit | ADR-034 exists as approved architecture; no implementation exists | Blocked: BUSINESS_DATE_EVIDENCE_UNAVAILABLE |
| No active Night Audit close lock | Night Audit | ADR-034 exists as approved architecture; no implementation exists | Blocked: NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE |
| Room readiness (Housekeeping) | Housekeeping | Yes — HousekeepingRoomReadinessProjectionService | Read-only dependency available |
| Engineering availability | Engineering | Yes — EngineeringRoomAvailabilityProjectionService | Read-only dependency available |

### Downstream Accounting Ownership

- Accounting owns revenue recognition, tax journal posting, GL journals, and financial-period control.
- These are downstream accounting outcomes.
- They are not FD-B8 checkout-readiness gates.
- Accounting posting completion must not be inferred as a prerequisite for operational folio settlement unless a future approved ADR explicitly introduces such a gate.
- Accounting ownership does not transfer guest-folio ownership away from PMS Guest Ledger.

### Execution Boundary Statuses

FD-B8 exposes these projection statuses:

- `EXECUTION_BOUNDARY_READY` — every repository-backed mandatory gate is resolved and satisfied. **Cannot be returned in FD-B8** because authoritative financial settlement, cashier, business date, and Night Audit evidence is unavailable.
- `EXECUTION_BOUNDARY_BLOCKED` — at least one mandatory gate is not satisfied and no review reason exists requiring explicit human review action.
- `EXECUTION_BOUNDARY_REVIEW_REQUIRED` — at least one gate requires a specific human review decision before execution can proceed (e.g., FD-B7 CHECKOUT_FINAL_REVIEW_REVIEWED).

### Stay Resolution and Non-Disclosure

- **Unknown stay ID or cross-property stay**: return 404. Do not disclose whether the stay exists in another property.
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

In FD-B8, READY remains unreachable because mandatory PMS Guest Ledger settlement, PMS Cashiering payment-allocation, General Cashier accountability, business date, and Night Audit evidence is unavailable. The future READY contract is preserved without fabricating readiness.

### Stable Blocker Codes

When authoritative evidence is missing, FD-B8 returns stable, non-fabricated blocker codes:

| Blocker Code | Meaning | Source |
|---|---|---|
| `FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE` | No authoritative PMS Guest Ledger settlement projection exists for Front Desk. Current folio totals cannot prove settlement readiness. | PMS Guest Ledger owns this evidence; PMS Cashiering owns guest payment allocation evidence; Accounting / AR owns accepted transfer evidence where applicable |
| `CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE` | No authoritative cashier session, cashier responsibility, cash accountability, close, or handover source exists for Front Desk | General Cashier owns this evidence |
| `BUSINESS_DATE_EVIDENCE_UNAVAILABLE` | No implemented business-date source exists | ADR-034 is approved architecture only |
| `NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE` | No implemented Night Audit close-lock source exists | ADR-034 is approved architecture only |
| `FD_B7_NOT_READY` | Latest FD-B7 final review is not CHECKOUT_FINAL_REVIEW_READY | Front Desk owns this evidence |
| `FD_B7_EVIDENCE_MISSING` | No FD-B7 final review evidence exists | Front Desk owns this evidence |
| `STAY_NOT_IN_HOUSE` | Stay is not in IN_HOUSE status | Front Desk owns this evidence |
| `CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED` | Checkout execution package has not been implemented | Future scope |

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

## Consequences

* **Positive:** Establishes a clear, governance-backed boundary that tells Front Desk operators exactly why checkout cannot proceed, without fabricating readiness.
* **Positive:** Identifies all missing authoritative sources explicitly, guiding future PMS Guest Ledger, PMS Cashiering, General Cashier, Accounting / AR, Accounting, Business Date, and Night Audit package implementation.
* **Negative:** FD-B8 can never return EXECUTION_BOUNDARY_READY because mandatory PMS Guest Ledger settlement, PMS Cashiering payment-allocation, General Cashier accountability, business date, and Night Audit evidence is unavailable. This is correct behavior — it prevents premature checkout.
* **Tradeoffs:** The projection is intentionally pessimistic. It defers to authoritative domains rather than inventing settlement evidence.
