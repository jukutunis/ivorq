# ADR-051: Cashbook, Cash Count, and Cash Reconciliation Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ now has a CASH-only supplier payment lifecycle that can start from an approved Payment Proposal Item, create immutable General Cashier Payment Execution evidence, create a Supplier Payment JournalCandidate, pass candidate review, materialize a JournalEntry Draft, record finalization authorization, and post through the controlled General Ledger posting path.

That accepted lifecycle stops at controlled General Ledger posting. It does not create cashbook evidence, cash count evidence, cash reconciliation, cash close, cash float, payment reversal, AP settlement allocation, or any direct cash balance mutation.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, cashbook projection, cash count capture, reconciliation, cash close, payment reversal, AP settlement allocation, Financial Period transition, Business Date transition, or cash balance mutation.

## Decision

### 1. Domain ownership

General Cashier owns operational cashbook transaction evidence and future cash-count evidence.

General Ledger remains the sole owner of JournalEntry posting and GL balance mutation. General Cashier must not mutate GL balances, cash balances, AP liability balances, Financial Period state, or Business Date state.

Payables remains owner of Payment Proposal and AP settlement intent. Banking remains owner of bank-account source of truth, bank statement evidence, bank transaction evidence, and bank reconciliation.

### 2. Cashbook transaction evidence

A CashbookTransaction is immutable operational evidence created only from one of these sources:

- A posted CASH supplier payment JournalEntry created through the accepted controlled posting lifecycle.
- Another explicitly authorized posted cash JournalEntry source accepted by a later ADR and implementation slice.

A CashbookTransaction:

- Is not a bank transaction.
- Is not a GL account balance.
- Is not a ledger replacement.
- Is not a direct cash mutation.
- Does not calculate a running balance.
- Does not prove physical cash on hand.
- Does not settle AP by itself.

Initial runtime implementation, if source proof exists, may create at most one immutable CashbookTransaction per posted CASH supplier payment JournalEntry.

### 3. Cash count evidence

Cash Count must be separately recorded from actual observed physical cash evidence.

Cash Count must never be inferred from PaymentExecution, JournalEntry, JournalEntryLine, CashbookTransaction, cashier session activity, expected cash, or any derived accounting amount.

Future Cash Count evidence must preserve property, cash account or approved cash scope, currency, count date, observed amount, counter actor, timestamp, and source evidence. This ADR does not authorize a cash count runtime implementation.

### 4. Manual cash reconciliation boundary

Cash reconciliation must be:

- Manual.
- Evidence-based.
- Property-scoped.
- Operational cash-account-scoped.
- Currency-scoped.
- Actor-resolved.
- Permission-gated.
- Auditable.

Cash reconciliation must not create journals, mutate cash balances, mutate GL balances, mutate cashbook transactions, mutate cash count evidence, close a cashier session, close a Business Date, close a Financial Period, or automatically clear discrepancies.

Cash reconciliation cannot proceed without all of:

- Source-proven opening position or controlled scope baseline.
- Source-proven cashbook scope.
- Actual observed physical cash count evidence.
- Same property.
- Same operational cash account.
- Same currency.
- Active reconciler.
- Narrow reconciliation permission.

### 5. Discrepancy treatment

Reconciliation discrepancies remain visible operational exceptions.

Discrepancies do not post automatically and do not create write-off, impairment, variance, adjustment, cash over/short, reversal, or correction records. A separate correction policy is required before any accounting effect can be created from a reconciliation discrepancy.

### 6. Explicit non-goals

This ADR does not authorize:

- Cash float.
- Cash close.
- Cash drawer management.
- Night-audit close.
- Automatic matching.
- Automatic reconciliation.
- Payment reversal.
- Payment void.
- AP settlement allocation.
- Partial payment.
- Split payment.
- Direct cash mutation.
- Direct GL posting.
- Direct Payables posting.
- Reconciliation adjustment posting.
- Write-off.
- Impairment.
- Cash over/short posting.
- Generic reconciliation engine.
- Generic payment engine.
- Bank transaction creation.
- Bank reconciliation.
- Financial Period transition.
- Business Date transition.

### 7. High-level future sequence

The approved future sequence is:

```text
Cashbook evidence
-> Cash Count evidence
-> Manual Cash Reconciliation
-> separate correction policy
```

No dependent runtime phase may skip the required source evidence for the prior step.

## Consequences

### Positive

- Preserves General Ledger as the only owner of journal posting and GL balance mutation.
- Establishes cashbook transactions as operational evidence rather than a cash balance ledger.
- Prevents physical cash count evidence from being fabricated from expected or posted accounting records.
- Keeps reconciliation manual and source-proven until opening position, cashbook scope, and count evidence exist.
- Prevents reconciliation discrepancies from silently becoming accounting adjustments.

### Limitations

- Cashbook foundation may proceed only when posted CASH supplier payment JournalEntry evidence can be identified durably and atomically from the accepted controlled posting path.
- Manual cash reconciliation cannot proceed until real cash count evidence and opening position or controlled scope baseline exist.
- Cash float, cash close, night-audit close, automatic matching, payment reversal, and AP settlement allocation remain future decisions.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-050: General Cashier Operational Foundation Architecture.

---

**Implementation status:** Policy accepted; CashbookTransaction foundation may be implemented only from posted CASH supplier payment JournalEntry evidence, while Cash Count, Cash Reconciliation, correction, cash close, cash float, and AP settlement allocation require separate source-proven implementation.
