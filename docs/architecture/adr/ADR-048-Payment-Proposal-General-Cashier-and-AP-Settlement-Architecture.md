# ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture

**Status:** Accepted
**Date:** 2026-06-30

## Context

IVORQ now has an accepted GRNI/AP lifecycle where an approved Supplier Invoice can proceed through a controlled GRNI/AP clearing candidate, Finance review, JournalEntry Draft, finalization authorization, and controlled General Ledger posting. The resulting posted JournalEntry is the first supported AP liability evidence for this slice.

The next product area is AP/GRNI settlement visibility and future supplier payment intent. Payment intent crosses Payables, General Ledger, future General Cashier behavior, Banking, Financial Period, Business Date, audit, vendor, and property boundaries. That boundary must be governed before runtime payment proposal or General Cashier work begins.

This ADR is policy only. It does not implement models, migrations, services, controllers, routes, user interface, tests, payment execution, payment posting, cash movement, bank movement, reconciliation, or settlement mutation.

## Decision

### 1. Domain ownership

The ownership boundary is:

- **Payables** owns payment eligibility derived from posted AP liability evidence, AP/GRNI settlement visibility, Payment Proposal Draft creation, Payment Proposal Draft cancellation, supplier and invoice payment intent evidence, and source-obligation selection validation.
- **General Cashier** owns future approved payment execution, cash and bank disbursement workflow, payment instrument evidence, cashier session and cashier control where applicable, executed payment evidence, and payment cancellation or reversal after execution where explicitly authorized.
- **General Ledger** owns JournalEntry, accounting posting, Financial Period guard, Business Date guard, GL balance mutation, and the controlled posting path.
- **Banking** owns future bank account source of truth, bank transaction and settlement evidence, and bank reconciliation.

A Payment Proposal must not transfer ownership of AP liability source evidence, Supplier Invoice, Purchase Order, Receiving, Inventory, Cost Ledger, or posted GRNI evidence into Payables. Payables may reference and validate those sources, but it must not rewrite, backfill, mutate, settle, or become owner of them.

### 2. Payment Proposal lifecycle

The initial supported lifecycle is:

```text
DRAFT
-> CANCELLED
```

Future lifecycle states, not implemented by this package, are:

```text
DRAFT
-> submitted for approval
-> approved
-> General Cashier execution
-> posted payment
-> reconciled or reversed under later explicit policy
```

A DRAFT Payment Proposal is an operational payment intent only. It is not payment authorization, payment execution, AP settlement, a cash event, a bank event, or a GL posting trigger.

### 3. Source obligation eligibility

A future Payment Proposal Item may reference only a posted AP liability JournalEntry where source proof confirms:

- Candidate provenance is SupplierInvoice GRNI/AP clearing.
- Source Supplier Invoice is approved.
- Source property is active and valid.
- Source vendor is valid.
- Source liability is posted.
- Source journal remains traceable.
- Unsupported tax, variance, FX, partial payment, credit-note, debit-note, or correction conditions fail closed.

The initial payment proposal scope is:

- Full obligation only.
- One source AP liability JournalEntry per proposal item.
- No partial payment.
- No payment splitting.
- No payment merging across vendors.
- No FX conversion.
- No tax or withholding calculation.
- No discount.
- No payment allocation.
- No prepayment.
- No advance.
- No supplier statement reconciliation.

### 4. Payment Proposal exclusivity

For the initial scope:

- One active Draft Payment Proposal Item may reference one posted AP liability source obligation at a time.
- Duplicate active draft selection must fail controlled.
- Cancellation of a Draft releases only proposal selection eligibility.
- Cancellation must not mutate AP liability, source JournalEntry, Supplier Invoice, GRNI evidence, cash, bank, or GL.
- A Payment Proposal Draft is not durable accounting settlement.
- Payment proposal item exclusivity is not a GRNI allocation model.

### 5. Aging and settlement visibility

AP/GRNI aging is read-only operational visibility.

Aging must use source-proven posted AP liability business date and current source-proven active Property Business Date. If either source date is unavailable, the result must show age as unavailable rather than infer wall-clock age, browser date, server date, or fabricate a date.

No aging bucket may change accounting, payment eligibility, Financial Period, Business Date, GL balances, AP liability, source GRNI evidence, or proposal eligibility. The first workspace may expose days outstanding and status filters. It must not establish accounting reserve, impairment, write-off, or close policy.

### 6. Payment execution boundary

This ADR explicitly prohibits:

- Direct payment posting from Payables.
- Direct payment posting from General Cashier outside controlled GL behavior.
- Direct cash or bank balance mutation.
- Payment execution from a DRAFT Payment Proposal.
- Auto-payment.
- Auto-settlement.
- Automatic retry.
- Payment approval without future explicit authorization policy.
- Bank reconciliation.
- Supplier statement reconciliation.

### 7. Provenance, audit, and idempotency

The Payment Proposal source chain must be:

```text
Supplier Invoice
-> GRNI/AP candidate
-> posted AP liability JournalEntry
-> Payment Proposal
-> Payment Proposal Item
-> future General Cashier execution
-> future controlled payment posting
```

Runtime implementation must require:

- Real active database-backed actor.
- Property-scoped and vendor-scoped validation.
- Immutable proposal creator evidence.
- Immutable cancellation actor, reason, and timestamp evidence.
- Stable proposal source identity.
- Duplicate active item prevention.
- Identical replay returns an existing draft only when semantically identical.
- Conflicting replay fails controlled.
- Zero automatic retries.
- No fabricated legacy provenance.
- No mutation of posted source evidence.

### 8. Financial Period and Business Date

Payment Proposal Draft creation and cancellation do not change Financial Period, Business Date, cash, bank, AP liability, or GL balances.

Future actual payment posting must reuse the accepted Financial Period, Business Date, and controlled General Ledger posting boundaries. This ADR does not create a new Financial Period lifecycle, Business Date lifecycle, or Payables-specific posting path.

## Non-Goals

This ADR does not authorize:

- Payment approval.
- Payment execution.
- Payment posting.
- Payment allocation.
- Partial payment.
- Split payment.
- FX.
- Tax.
- Withholding.
- Discounts.
- Prepayments.
- Advances.
- Credit notes.
- Debit notes.
- Supplier reconciliation.
- Bank reconciliation.
- Cash reconciliation.
- AP aging accounting adjustment.
- Impairment.
- Write-off.
- Automatic payment.
- Generic workflow.
- Generic payment engine.
- Source document mutation.
- Runtime General Cashier implementation.

## High-Level Future Sequence

1. AP/GRNI settlement visibility and aging workspace.
2. Draft Payment Proposal foundation.
3. Payment Proposal review and approval policy.
4. General Cashier controlled execution architecture.
5. Controlled payment JournalEntry candidate, draft, authorization, and posting.
6. Bank and cash reconciliation.
7. Payment exceptions, reversals, partial payment, FX, tax, and correction policies through explicit future decisions.

## Consequences

### Positive

- Preserves Payables ownership of payment intent while keeping payment execution outside this slice.
- Prevents posted AP liability evidence from being silently treated as paid or settled.
- Keeps aging visible without creating accounting reserves, write-offs, or settlement effects.
- Establishes active Draft exclusivity as proposal-selection control only, not payment allocation.
- Preserves the existing General Ledger posting, Financial Period, and Business Date boundaries.

### Limitations

- No payment approval, payment execution, General Cashier runtime, payment posting, cash movement, bank movement, payment allocation, settlement mutation, or reconciliation capability exists after this ADR.
- Draft Payment Proposal runtime work must still prove source obligation eligibility, durable exclusivity, actor authority, property/vendor/currency scope, idempotency, and no source mutation before implementation.
- Partial payments, splits, FX, tax, withholding, discounts, advances, credit notes, debit notes, supplier reconciliation, bank reconciliation, payment exceptions, and reversals remain future decisions.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-003: Approval Engine Architecture.
- ADR-004: Finance Module Boundary Architecture.
- ADR-011: Goods Received Not Invoiced Architecture.
- ADR-013: Period Closing Strategy.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-047: GRNI Clearing and AP Liability Architecture.

---

**Implementation status:** Policy accepted; Payment Proposal Draft, General Cashier execution, payment posting, AP settlement, cash or bank movement, and reconciliation runtime capabilities are not authorized by this ADR.
