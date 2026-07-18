# ADR-088: Guest Ledger, Folio and Hospitality Financial Subledger Architecture

## ADR Metadata
* **ADR Number:** ADR-088
* **ADR Title:** Guest Ledger, Folio and Hospitality Financial Subledger Architecture
* **Date:** 2026-07-11
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-004 (Finance Module Boundary), ADR-028 (Accounts Receivable and Guest Ledger / City Ledger Strategy), ADR-034 (Night Audit and Hospitality Business Date Architecture), ADR-040 (IVORQ Interaction Layer Standard), ADR-049 (Payment Approval, General Cashier Posting, and Reconciliation Architecture), ADR-066 (Sensitive Action Reauthentication and Session Confirmation Boundary), ADR-067 (Finance Sensitive Decision Confirmation Enforcement), ADR-068 (Supplier Payment and Settlement Operational Workspaces), ADR-070 (Banking Operations Workspace and Controlled Bank Execution), ADR-084 (Controlled Front Desk Arrival, Stay, and Room Assignment Boundary), ADR-087 (Controlled Front Desk Departure Checkout Execution Boundary)
* **Clarifies:** ADR-004, ADR-028, ADR-084, ADR-087

## Context

FD-B9 exposed a durable ownership ambiguity across the current architecture records. ADR-004 broadly identifies PMS as Front Office-owned with Finance as consumer, while ADR-084 and ADR-087 used ambiguous Finance and PMS combined-owner language for folio, payment, revenue, and settlement evidence. That ambiguity is unsafe for checkout because the system must not treat Finance, Accounting, PMS, PMS Cashiering, General Cashier, and Front Desk as a single domain.

Front Office is the organizational and business steward for PMS operations. Front Desk is a controlled operational domain that owns arrival, in-house, departure preparation, and future checkout command boundaries. Front Desk is not the owner of guest folios, guest payments, deposits, refunds, AR transfer, cashier state, revenue recognition, tax posting, GL posting, or Night Audit.

The current PMS folio runtime is skeleton infrastructure. `Folio` and `FolioItem` exist with numeric totals, a basic open/closed/void lifecycle, and item categories. The folio migration explicitly describes the totals as skeleton financial totals and defers full ledger posting to future accounting integration. A newly created folio can have a zero balance before room, tax, service, payment, deposit, refund, or transfer evidence is complete. Therefore zero balance alone cannot prove checkout settlement.

The current supplier/AP payment execution runtime is not guest-folio evidence. `PaymentExecution` is tied to vendor, supplier invoice, payment proposal, payment proposal item, AP liability journal, cash/bank evidence, and supplier payment posting. It must not be reused as guest payment, guest deposit, guest refund, or guest folio allocation evidence.

This ADR is triggered by the blocked FD-B9 investigation. It records the Owner decision that PMS Guest Ledger owns guest folio settlement evidence, PMS Cashiering owns guest tender and guest payment allocation lifecycle, General Cashier owns cashier accountability and cash custody, Accounting/AR owns receivables after accepted transfer, Accounting/GL owns accounting postings, Finance governs and consumes outcomes, and Front Desk consumes settlement readiness read-only.

## ADR-089 Synchronization Note

ADR-089 does not transfer PMS Guest Ledger or PMS Cashiering financial ownership to Front Desk. A future execution-time participating attestation port is a distinct runtime contract and does not make Front Desk the owner of folios, payments, deposits, refunds, reversals, AR transfer, settlement, or cashier lifecycle facts. Current GLF-D remains a read-only projection for readiness/review evidence. Front Desk must not mutate folio, payment, deposit, refund, reversal, AR, or settlement tables.

## Ownership Matrix

| Domain | Owned facts | Permitted commands | Consumed evidence | Prohibited mutation |
|---|---|---|---|---|
| PMS Guest Ledger | Guest folio aggregate, folio identity and lifecycle, folio items, room-charge and operational guest-charge facts, guest ledger balance, canonical folio balance, folio-side effect of accepted payment-allocation evidence, settlement readiness, folio settlement evidence, folio closure after controlled settlement, checkout-relevant multi-folio aggregation | Future controlled folio posting, settlement evaluation, folio closure, settlement-readiness projection publication | PMS Reservation, FrontDeskStay identity, PMS Cashiering accepted payment-allocation evidence, deposit/refund state, accepted AR transfer, Night Audit posting-completeness checkpoints, Accounting posting references where relevant | Front Desk stay mutation, General Cashier session mutation, cash drawer mutation, GL journal posting, AR collection lifecycle mutation |
| PMS Cashiering | Guest payment-allocation command, allocation identity and lifecycle, allocation status, tender transaction, payment void/reversal relationship, deposit application command, guest refund transaction | Future guest tender recording, guest payment allocation, deposit application, guest refund, guest payment void/reversal | PMS Guest Ledger folio identity, General Cashier cash-session accountability for cash tender, Banking evidence for bank/card settlement where relevant | Guest folio aggregate ownership, canonical folio balance, General Cashier session close, Accounting journal posting, AR invoice lifecycle |
| Front Desk | FrontDeskStay lifecycle, arrival and departure operational evidence, future controlled checkout command boundary | Future checkout command after revalidating authoritative dependencies | PMS Guest Ledger settlement readiness projection, PMS Reservation/Guest identity, Housekeeping, Engineering, Business Date/Night Audit locks, FD-B evidence | Folios, folio items, guest payments, deposits, refunds, AR transfers, cashier state, accounting records |
| General Cashier | Cashier session, cashier identity and responsibility, drawer or till accountability, cash custody, cashier handover, cashier close and reconciliation, physical cash execution evidence where relevant | Open/close/handover cashier session, record cash count/accountability, reconcile cash custody under approved packages | PMS Cashiering cash guest-payment execution reference when cash tender is involved, Accounting/Banking evidence where relevant | Guest folio balance, guest settlement decision, room-charge posting, guest payment allocation semantics, folio closure |
| Accounting / AR | City Ledger / Accounts Receivable after accepted transfer, transferred receivable lifecycle, client invoice and collection lifecycle, AR reconciliation | Accept/reject/reverse AR transfer, invoice, collect, reconcile, write off under approved controls | PMS Guest Ledger transfer request and accepted transfer evidence, Accounting/GL postings, payment/bank evidence | PMS guest folio before accepted transfer, Front Desk stay mutation, General Cashier drawer state |
| Accounting / GL | General Ledger, journal entries, accounting revenue recognition, accounting tax posting, financial-period enforcement, accounting reconciliation | Journal candidate/draft/posting/reversal, revenue/tax posting, period control | Accepted PMS operational postings, accepted AR transfer, General Cashier/Banking evidence | Operational guest folio execution, guest payment allocation, Front Desk checkout state |
| Finance | Financial governance, policy, configuration oversight, review, reporting, financial control | Configure/review/govern finance policy and reports; participate in approvals where approved | PMS Guest Ledger, PMS Cashiering, General Cashier, Accounting/AR, Accounting/GL, Night Audit outputs | Operational guest folio execution, guest payment allocation, folio closure, direct Front Desk checkout mutation |
| Night Audit / Business Date | Hospitality business-date lifecycle, close locks, posting-completeness checkpoint, Night Audit checkpoint orchestration | Open/close business date, run/check close checkpoints, enforce close locks under approved package | PMS Guest Ledger unresolved-folio and posting-completeness evidence, General Cashier close evidence, Accounting/Tax/AR evidence where relevant | Folio items, guest payments, folio closure, Front Desk stay mutation |
| Revenue Management | Rate, yield, and revenue policy inputs where approved | Maintain rate/revenue configuration through approved PMS/Revenue packages | PMS operational postings and Accounting revenue reports | Guest folio settlement, payment allocation, GL journal posting |
| Tax configuration/governance | Tax rule configuration and review where approved | Configure and review tax policy through approved Tax/Finance packages | PMS operational tax charge facts and Accounting tax postings | PMS guest folio lifecycle, guest payment execution, checkout execution |

## Operational Versus Accounting Facts

PMS Cashiering owns payment allocation as a transaction lifecycle. PMS Guest Ledger consumes accepted allocation evidence and owns its folio-side financial effect.

PMS operational financial facts include:

- room charge;
- service charge;
- operational tax charge;
- adjustment;
- guest payment allocation (command lifecycle owned by PMS Cashiering; folio-side effect owned by PMS Guest Ledger);
- deposit application;
- refund transaction;
- folio balance (canonical, owned by PMS Guest ledger);
- settlement readiness;
- folio closure.

Accounting facts include:

- revenue-recognition posting;
- tax journal posting;
- AR recognition after accepted transfer;
- GL journal;
- reconciliation;
- financial-period control.

An operational charge in PMS does not itself mean the GL has been posted. A GL posting does not give Accounting ownership of the operational guest folio. PMS generates operational hospitality financial facts; Accounting consumes accepted postings and owns accounting outcomes.

## Guest Ledger Aggregate

The future authoritative Guest Ledger aggregate must be:

- property-scoped;
- linked to Reservation and/or FrontDeskStay through server-resolved identifiers;
- linked to the Guest identity;
- capable of representing one or more checkout-relevant folios;
- currency-explicit;
- based on immutable folio items;
- explicit about posting state;
- explicit about guest payment allocation state;
- explicit about void/reversal chains;
- explicit about deposit state;
- explicit about refund state;
- explicit about AR-transfer state;
- explicit about settlement state;
- supported by source snapshots and audit evidence.

This ADR does not prescribe migrations, tables, services, routes, or enum implementations.

## Multi-Folio Policy

A reservation or stay may have multiple folios/windows. Checkout readiness must evaluate every checkout-relevant folio, not merely the first open folio or a single zero-balance folio.

A single zero-balance folio is insufficient. Missing folio linkage, ambiguous reservation/stay linkage, conflicting active folios, unresolved closed/void semantics, or unclear transferred-folio semantics requires review or evidence-unavailable status.

Closed, void, transferred, and active folios must have explicit semantics before they can affect settlement readiness.

## Settlement Readiness Contract

Future settlement readiness statuses are:

- `GUEST_LEDGER_SETTLEMENT_READY`
- `GUEST_LEDGER_SETTLEMENT_BLOCKED`
- `GUEST_LEDGER_SETTLEMENT_REVIEW_REQUIRED`
- `GUEST_LEDGER_SETTLEMENT_EVIDENCE_UNAVAILABLE`

`GUEST_LEDGER_SETTLEMENT_READY` requires source-proven evidence that:

- all mandatory operational charges are posted;
- no unresolved posting gaps exist;
- canonical aggregate balance equals zero;
- all guest payments are terminal and allocated;
- no unresolved payment void or reversal exists;
- deposits are applied, refunded, transferred, or otherwise resolved;
- no pending guest refund exists;
- no unresolved or pending AR transfer exists; only an accepted transfer may satisfy the transferred amount; requested transfer is not settlement; failed or rejected transfer is not settlement; reversed transfer is not settlement; failed, rejected, or reversed attempts must be terminal and resolved, closed, or superseded before readiness can be reevaluated; a historical resolved failure does not permanently block settlement when another valid settlement method has completed;
- currency is consistent across checkout-relevant folios and settlement facts;
- all checkout-relevant folios are resolved;
- no settlement hold exists;
- the authoritative property and stay relationship is resolved;
- no already completed conflicting settlement exists.

Zero balance alone is explicitly insufficient. `GUEST_LEDGER_SETTLEMENT_BLOCKED` means source evidence proves at least one mandatory requirement is not satisfied. `GUEST_LEDGER_SETTLEMENT_REVIEW_REQUIRED` means evidence is present but ambiguous, conflicting, exception-based, or policy-dependent and requires authorized review. `GUEST_LEDGER_SETTLEMENT_EVIDENCE_UNAVAILABLE` means the authoritative source projection or required source facts do not yet exist or cannot be safely evaluated.

## Settlement Versus Folio Closure

Settlement readiness is read-only evidence. `GUEST_LEDGER_SETTLEMENT_READY` does not itself close the folio.

Folio closure is a separate controlled command. Future checkout execution must revalidate settlement readiness immediately before execution. Folio closure and stay departure must not partially complete. Future orchestration must define idempotency, locking, failure recovery, and safe retry behavior before implementation.

## PMS Cashiering Versus General Cashier

PMS Cashiering owns guest payment-allocation command, allocation identity and lifecycle, allocation status, tender transaction, guest refund, deposit application command, and payment reversal.

General Cashier owns cashier session ownership, cash custody, till accountability, cashier handover, and cashier close/reconciliation.

A cash guest payment may require evidence from both domains: PMS Cashiering for the guest payment and folio allocation, and General Cashier for the cash-session and cash-custody evidence. Neither domain may fabricate the other's evidence.

PMS Guest Ledger must not be described as owning the guest payment-allocation command or lifecycle. PMS Cashiering must not be described as owning the folio aggregate or canonical balance.

## AR Transfer Boundary

PMS Guest Ledger owns the guest balance before accepted transfer. Accounting/AR owns the receivable only after an accepted transfer.

An AR transfer lifecycle must include requested, accepted, failed/rejected, and reversed semantics. Settlement readiness may recognize only a completed accepted transfer. Merely creating an AR request is insufficient.

Pending or unresolved transfers block settlement readiness. Only an accepted transfer satisfies the transferred amount. A requested transfer is not settlement. A failed or rejected transfer is not settlement. A reversed transfer is not settlement. Failed, rejected, or reversed attempts must be terminal and resolved, closed, or superseded before readiness can be reevaluated. A historical resolved failure does not permanently block settlement when another valid settlement method has completed.

This aligns with ADR-028's Guest Ledger and City Ledger strategy: Direct Bill moves a governed guest balance to City Ledger only through an accepted transfer boundary with authorization evidence.

## Night Audit Relationship

Night Audit consumes posting-completeness and unresolved-folio checkpoints. Business Date may lock settlement-affecting commands. Night Audit does not own folio items. Guest Ledger does not advance the Business Date. Future checkout must respect close locks.

## Cross-Domain Read Boundary

The future PMS Guest Ledger-owned projection is:

```text
GuestLedgerCheckoutSettlementReadinessProjection
```

Suggested identifier-only input:

- `front_desk_stay_id`

Server-resolved output may include:

- `property_id`
- `front_desk_stay_id`
- `reservation_id`
- relevant folio identifiers
- settlement status
- canonical aggregate balance
- currency
- blocker codes
- blocker messages
- review reasons
- posting-completeness marker
- payment-allocation marker
- deposit/refund/AR markers
- `evaluated_at`
- source snapshot identifiers

The browser must not submit balance, currency, folio status, payment status, settlement status, actor, property, or accounting outcome.

## Required Implementation Sequence

1. GLF-A - Guest Ledger/Folio aggregate hardening.
2. GLF-B - Guest payment allocation and immutable void/reversal foundation.
3. GLF-C - Deposit, refund and AR-transfer lifecycle foundation.
4. GLF-D - Authoritative checkout settlement readiness projection.
5. Retry FD-B9 - Front Desk read dependency integration.
6. Later checkout execution package only after Cashier, Business Date, and Night Audit dependencies also exist.

These stages are not implemented by this ADR.

## Consequences And Non-Goals

This package establishes a canonical owner for guest folio settlement and removes the combined Finance-and-PMS ownership ambiguity from active architecture records and current FD-B8 labels.

This package does not:

- create a migration;
- change a folio;
- process payment;
- implement settlement;
- implement checkout;
- change a stay;
- post accounting;
- transfer AR;
- execute Night Audit;
- create routes;
- create permissions;
- create candidate baseline changes;
- create accepted debt.
