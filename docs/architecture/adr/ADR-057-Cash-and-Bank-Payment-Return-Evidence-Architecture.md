# ADR-057: Cash and Bank Payment Return Evidence Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ has a controlled CASH supplier payment lifecycle, cashbook transaction evidence, Banking source architecture, Banking legacy coexistence architecture, and supplier payment correction architecture. ADR-053 requires independent source evidence before any posted payment reversal can proceed.

Current runtime does not have CashReturnEvidence, BankReturnEvidence, or any source-proven return chain. A user-entered reason, PaymentExecution, PaymentProposal, CashbookTransaction, JournalEntry, or General Cashier payment instrument is not enough to prove returned cash or reversed bank movement.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, payment void, return evidence, reversal execution evidence, JournalCandidates, JournalEntry Drafts, posting, AP settlement changes, cash mutation, bank mutation, Financial Period transition, or Business Date transition.

## Decision

### 1. Return evidence ownership

General Cashier owns immutable CashReturnEvidence for actual observed physical CASH return.

Banking owns immutable BankReturnEvidence only through independently registered Banking external evidence.

Return evidence is separate from PaymentExecution, PaymentProposal, PaymentProposalItem, CashbookTransaction, BankStatementLine, JournalEntry, JournalEntryLine, or a user-entered reversal reason.

### 2. CASH return evidence

A CASH return must preserve:

- Property.
- Operational cash account.
- Currency.
- Exact full amount.
- Observed return date.
- Original posted payment identity.
- Source or evidence reference.
- Recorder actor.
- Verifier actor when source convention supports it.
- Recorded timestamp.
- Immutable technical identity.

CASH return evidence must be based on actual observed physical cash return. It must not be inferred from expected cash, cashbook transactions, PaymentExecution, JournalEntry, proposal status, or a reconciliation exception.

### 3. BANK return evidence

A BANK return must derive only from Banking-owned immutable external evidence.

No BANK return may be manually invented from an approved proposal, PaymentExecution, posted JournalEntry, General Cashier BANK instrument, bank control account, or user-entered reason.

The Banking source path must preserve independent external reference, source reference, property, BankAccount, currency, direction, exact amount, date, recorder actor, timestamp, and durable identity before future BankReturnEvidence can be considered.

### 4. No mutation boundary

Return evidence does not mutate:

- Cash.
- Bank.
- AP liability.
- PaymentExecution.
- PaymentProposal.
- CashbookTransaction.
- BankStatementLine.
- JournalEntry.
- JournalEntryLine.
- Financial Period.
- Business Date.
- GL balance.

Return evidence enables future candidate-first reversal only. It is not itself reversal, accounting settlement, cash balance correction, bank balance correction, AP reopening, or allocation rewrite.

### 5. One active full return chain

One posted payment may have one active full return evidence chain only.

The initial scope is full original amount only. Duplicate identical replay may return existing evidence only when all controlled source identities match. Conflicting replay must fail controlled.

### 6. Explicit non-goals

This ADR does not authorize:

- Partial return.
- Partial reversal.
- Chargeback workflow.
- Direct cash mutation.
- Direct bank mutation.
- Direct GL posting.
- Direct Payables mutation.
- Source rewrite.
- Source deletion.
- Automatic reversal.
- Automatic retry.
- Cash close.
- Bank close.
- AP allocation rewrite.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Establishes independent source evidence before any posted payment reversal.
- Keeps physical CASH return evidence inside General Cashier and external BANK return evidence inside Banking.
- Prevents user-entered reversal reasons or accounting records from fabricating return evidence.
- Preserves original payment, cashbook, bank, AP, and journal records.

### Limitations

- Posted payment reversal remains blocked until the relevant return evidence foundation exists.
- BANK return evidence remains dependent on ADR-056/ADR-052-compliant external Banking evidence.
- Partial returns, chargebacks, and automatic reversals remain future decisions.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-051: Cashbook, Cash Count, and Cash Reconciliation Architecture.
- ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture.
- ADR-053: Supplier Payment Void, Reversal, and Correction Architecture.
- ADR-056: Banking Legacy Isolation and Manual Evidence Coexistence Architecture.

---

**Implementation status:** Policy accepted; CashReturnEvidence and BankReturnEvidence require separate source-proven runtime foundations, and posted payment reversal remains blocked until independent return evidence exists.
