# ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ now has controlled Supplier Invoice approval, GRNI/AP clearing candidate review, JournalEntry Draft materialization, finalization authorization, controlled General Ledger posting, AP/GRNI settlement visibility, and Payment Proposal Draft creation and cancellation. The current durable payment intent stops at a Draft Payment Proposal or Cancelled proposal selection. It does not approve payment, execute payment, settle AP, mutate cash or bank records, post GL, or reconcile bank or cash evidence.

The next payment lifecycle crosses Payables, General Cashier, General Ledger, Banking, Financial Period, Business Date, property scope, vendor scope, account provenance, audit, and authorization boundaries. This ADR records the architecture decision before runtime payment approval, General Cashier payment execution, supplier payment posting, or reconciliation foundation code is implemented.

This ADR is policy only. It does not implement migrations, models, services, controllers, routes, UI, tests, payment approval, payment execution, journal candidates, JournalEntry posting, AP settlement, cash movement, bank movement, or reconciliation runtime behavior.

## Decision

### 1. Payment Proposal approval lifecycle

The initial controlled Payment Proposal approval lifecycle is:

```text
DRAFT
-> PENDING_APPROVAL
-> APPROVED or REJECTED
-> future execution
```

The cancellation lifecycle remains:

```text
DRAFT
-> CANCELLED
```

Rules:

- The Payment Proposal creator cannot approve their own proposal.
- Submission, approval, and rejection require an active database-backed actor.
- Submission, approval, and rejection require active property scope.
- Submission, approval, and rejection require narrow source-proven permissions.
- Rejection requires a meaningful reason.
- Approved and rejected decisions are immutable except identical semantic replay.
- Payment Proposal approval does not execute payment, settle AP, mutate cash or bank, create a JournalEntry, post GL, or reconcile.
- Cancellation applies only while the source-proven lifecycle permits it.
- Cancelled or rejected proposals cannot execute.

### 2. General Cashier execution boundary

General Cashier may create immutable payment execution evidence only from an Approved Payment Proposal Item.

A payment execution must:

- Reference one Payment Proposal Item.
- Reference one posted AP liability source obligation.
- Use a source-proven payment instrument and cash or bank operational account.
- Preserve property, vendor, currency, full amount, proposal item, and source obligation provenance.
- Be idempotent.
- Use database-enforced one-to-one identity between approved Payment Proposal Item and execution evidence.
- Never mutate cash, bank, AP balance, Supplier Invoice, GRNI, source JournalEntry, or General Ledger.

Execution evidence is not accounting settlement until the controlled supplier payment posting lifecycle succeeds.

### 3. Controlled payment accounting lifecycle

The controlled accounting lifecycle is:

```text
Approved Payment Proposal Item
-> General Cashier Payment Execution Evidence
-> Supplier Payment JournalCandidate
-> Candidate Review
-> JournalEntry Draft
-> Finalization Authorization
-> Controlled General Ledger Posting
```

No Payables-specific or General-Cashier-specific direct posting path is allowed.

The expected accounting direction is conceptual until account source proof is available:

```text
Debit AP liability control account
Credit source-proven cash or bank control account
```

Runtime implementation must fail closed when account, currency, amount, payment instrument, property, vendor, or source provenance cannot be proven.

### 4. No-double-payment identity

The initial full-obligation scope must enforce:

- One active Payment Proposal Item per posted AP liability source.
- One execution evidence record per approved Payment Proposal Item.
- One Supplier Payment JournalCandidate per execution evidence.
- One JournalEntry per payment candidate.
- Identical replay returns existing durable evidence only when semantically identical.
- Conflicting replay fails controlled.
- A posted source AP liability cannot be paid twice.

No allocation table, partial payment, split payment, prepayment, advance, supplier-statement settlement, FX, tax, withholding, discount, or payment batch splitting is authorized.

### 5. Reconciliation boundary

Bank and cash reconciliation must use actual durable source records:

- Posted supplier payment JournalEntry.
- General Cashier payment execution evidence.
- Source-proven cashbook or bank transaction.
- Source-proven bank statement or cash count evidence where applicable.

The initial reconciliation scope, when source data exists, is:

- One posted payment.
- One source bank or cash transaction.
- Same property.
- Same currency.
- Exact supported amount.
- Manual evidence-based match.
- Immutable actor, timestamp, and reason evidence.
- No automatic reconciliation.

Reconciliation must not create a journal, mutate cash or bank balance, alter payment source, close a Financial Period, close a Business Date, or infer source records.

### 6. Explicit non-goals

This ADR does not authorize:

- Partial payment.
- Split payment.
- Payment batch splitting.
- Cross-vendor proposal.
- Cross-currency proposal.
- FX.
- Tax.
- Withholding.
- Discount.
- Prepayment.
- Advance.
- Credit note.
- Debit note.
- Supplier statement settlement.
- Automated bank matching.
- Cash count close.
- Bank close.
- Payment reversal.
- Payment void after posting.
- Direct cash or bank mutation.
- Direct payment posting.
- Generic payment engine.
- Generic reconciliation engine.
- Generic approval engine.
- Source document mutation.

### 7. Financial Period and Business Date

Payment Proposal Draft creation, submission, approval, rejection, and cancellation do not mutate Financial Period, Business Date, cash, bank, AP balance, or GL.

General Cashier payment execution evidence does not mutate Financial Period, Business Date, cash, bank, AP balance, or GL.

Actual controlled supplier payment JournalEntry posting must reuse the existing Financial Period, Business Date, lock-order, and General Ledger posting controls.

Reconciliation does not post and does not change Financial Period or Business Date state.

### 8. High-level sequence

1. Payment Proposal approval.
2. General Cashier execution evidence.
3. Supplier payment candidate.
4. Existing review, draft, authorization, and controlled posting lifecycle.
5. Manual source-proven bank or cash reconciliation foundation.
6. Future decisions for payment reversals, partial payment, FX, tax, withholding, reconciliation automation, and cash or bank close.

## Consequences

### Positive

- Preserves Payables ownership of payment intent and proposal approval.
- Keeps General Cashier responsible for future operational payment execution evidence.
- Keeps General Ledger as the only owner of controlled accounting posting and GL balance mutation.
- Prevents payment approval or execution evidence from being silently treated as AP settlement.
- Establishes no-double-payment identity before execution and posting code begins.
- Keeps reconciliation source-proven and manual until later evidence supports automation.

### Limitations

- Payment approval does not execute payment.
- General Cashier execution evidence does not post GL or mutate cash or bank balance.
- Supplier payment accounting must still pass through JournalCandidate review, JournalEntry Draft, finalization authorization, and controlled posting.
- Reconciliation cannot proceed without source-proven bank or cash transaction evidence.
- Partial payments, split payments, FX, tax, withholding, discounts, reversals, voids, bank close, cash count close, and automated matching remain future decisions.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-003: Approval Engine Architecture.
- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-047: GRNI Clearing and AP Liability Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.

---

**Implementation status:** Policy accepted; Payment Proposal approval, General Cashier payment execution, supplier payment posting, AP settlement mutation, cash or bank movement, and reconciliation runtime capabilities require separate source-proven implementation.
