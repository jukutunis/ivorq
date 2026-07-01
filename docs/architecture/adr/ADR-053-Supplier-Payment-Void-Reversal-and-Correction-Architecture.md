# ADR-053: Supplier Payment Void, Reversal, and Correction Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ has a controlled CASH supplier payment lifecycle and accepted architecture for future cashbook, cash count, cash reconciliation, Banking source evidence, BANK payment confirmation, and bank reconciliation. Current runtime does not authorize supplier payment void, payment reversal, payment correction, cash return evidence, bank return evidence, AP settlement allocation rewrite, or direct GL reversal.

Payment correction can affect Payables, General Cashier, Banking, General Ledger, cashbook evidence, bank evidence, AP liability visibility, Financial Period state, Business Date state, property scope, vendor scope, audit, and authorization. The correction boundary must be governed before runtime code can void or reverse payment evidence.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, payment void, payment reversal, correction source evidence, JournalCandidates, JournalEntry Drafts, posting, AP settlement allocation behavior, cash return evidence, bank return evidence, or source mutation.

## Decision

### 1. Correction states

The initial supplier payment correction scope separates:

- Pre-post VOID before payment candidate approval, materialization, or posting.
- Post-posting full reversal after the original supplier payment JournalEntry is posted.

Void and reversal are not interchangeable. Void prevents an unposted payment execution from proceeding to the posting lifecycle. Reversal creates a new controlled accounting lifecycle that offsets a posted payment only when independent return evidence exists.

### 2. Pre-post VOID

Pre-post VOID is possible only before candidate approval, materialization, or posting.

Void requires immutable evidence:

- Actor.
- Reason.
- Timestamp.
- Original PaymentExecution identity.
- Source state proving the execution has not passed the approved candidate, draft, or posted boundary.

VOID never deletes or rewrites PaymentExecution, PaymentProposal, PaymentProposalItem, source AP liability, Supplier Invoice, cashbook evidence, bank evidence, JournalCandidate, JournalEntry, JournalEntryLine, or posting evidence.

VOID does not reopen AP liability, create AP settlement allocation, mutate cash or bank evidence, post GL, or mutate balances.

### 3. Post-posting reversal

Post-posting correction is a full reversal only in the initial scope.

A posted payment reversal requires independent source evidence of one of:

- Cash return for a CASH payment.
- Bank reversal or bank return for a BANK payment.

PaymentExecution, PaymentProposal, JournalEntry, CashbookTransaction, BankStatementLine, or user-entered reason alone is not sufficient return evidence unless the relevant return source table and policy explicitly authorize it.

### 4. Reversal lifecycle

Reversal is candidate-first and must follow:

```text
Source return evidence
-> reversal execution evidence
-> reversal JournalCandidate
-> Candidate Review
-> JournalEntry Draft
-> Finalization Authorization
-> Controlled General Ledger Posting
```

The reversal lifecycle must use the accepted General Ledger review, draft, authorization, Financial Period, Business Date, lock-order, and controlled posting boundaries.

No direct GL reversal, direct cash mutation, direct bank mutation, direct Payables mutation, or automatic reversal is allowed.

### 5. One active reversal chain

One posted payment can have at most one active reversal chain.

The identity must be durable and database-enforced before runtime reversal is allowed. Identical semantic replay may return existing reversal evidence only when every controlled source identity, amount, currency, property, account, actor, and return evidence reference matches. Conflicting replay must fail controlled.

### 6. AP liability and settlement visibility

Reversal does not silently reopen or mutate original AP liability evidence.

Any AP re-availability, settlement visibility change, or outstanding amount recalculation must be future settlement-allocation behavior. It must not be inferred by rewriting original PaymentExecution, source AP liability, supplier invoice, payment proposal, cashbook, bank statement, or original JournalEntry evidence.

### 7. Explicit non-goals

This ADR does not authorize:

- Partial reversal.
- Bank chargeback workflow.
- Automatic void.
- Automatic reversal.
- Direct GL reversal.
- Direct cash mutation.
- Direct bank mutation.
- Direct Payables mutation.
- Source deletion.
- Source rewrite.
- Settlement allocation rewrite.
- AP liability reopening.
- Payment allocation.
- Cashbook mutation.
- Bank statement mutation.
- FX.
- Tax.
- Withholding.
- Discount.
- Generic correction engine.
- Generic payment engine.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Separates pre-post void from posted accounting reversal.
- Prevents deletion or rewrite of payment and posting evidence.
- Requires independent cash or bank return evidence before posted reversal.
- Preserves the accepted JournalCandidate, review, draft, authorization, and controlled posting lifecycle for accounting effects.
- Prevents correction from silently reopening AP liability or rewriting future allocation state.

### Limitations

- Runtime void cannot proceed until the system can distinguish unposted PaymentExecution state from approved candidate, draft, and posted state.
- Runtime reversal cannot proceed until source-proven cash return or bank return evidence exists.
- Partial reversal, bank chargeback workflow, allocation rewrite, AP reopening, FX, tax, withholding, and discount remain future decisions.

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
- ADR-051: Cashbook, Cash Count, and Cash Reconciliation Architecture.
- ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture.

---

**Implementation status:** Policy accepted; supplier payment void and full posted reversal require separate source-proven runtime implementation, and posted reversal remains blocked without independent cash-return or bank-return source evidence.
