# ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ has a CASH-only supplier payment lifecycle and a newly accepted cashbook, cash count, and cash reconciliation architecture. Existing General Cashier BANK instruments are operational payment instrument configuration only. They are not a Banking source of truth and do not prove external bank activity.

The next governed boundary is Banking source evidence, BANK payment confirmation, and bank reconciliation. These concerns cross Banking, General Cashier, Payables, General Ledger, Financial Period, Business Date, property scope, vendor scope, account provenance, audit, and authorization boundaries.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, bank-account runtime, bank-statement import, bank statement line registration, BANK payment execution, journal candidates, JournalEntry drafts, posting, bank reconciliation, cash reconciliation, payment reversal, or bank balance mutation.

## Decision

### 1. Domain ownership

Banking owns:

- BankAccount operational identity.
- Bank-statement source evidence.
- Bank transaction evidence.
- Bank reconciliation.

General Cashier may request or confirm an operational payment only through source-proven Banking evidence. General Cashier must not invent bank transactions, bank statement lines, bank balances, bank accounts, or external bank confirmations.

General Ledger remains the sole owner of JournalEntry posting and GL balance mutation. Payables remains owner of payment proposal and AP settlement intent.

### 2. BankAccount boundary

A BankAccount is Banking-owned operational identity.

A BankAccount references a property-scoped active GL bank control account, but this mapping does not mutate the GL account balance and does not establish bank balance truth.

A General Cashier BANK instrument is not itself a BankAccount source of truth. A BANK instrument may become eligible for future BANK payment confirmation only when it can be mapped to an active Banking-owned BankAccount under an approved source-proven boundary.

### 3. Bank statement line evidence

A BankStatementLine is immutable external evidence.

A BankStatementLine cannot originate from PaymentExecution, PaymentProposal, JournalEntry, JournalEntryLine, Payment Proposal approval, or a General Cashier BANK instrument.

Initial source evidence must be manually registered from an external source reference. This ADR does not authorize automatic bank import, automatic parsing, automatic matching, bank balance mutation, or reconciliation.

### 4. BANK payment confirmation boundary

BANK payment must move through a distinct payment request and confirmation boundary. It cannot be silently treated as executed merely because a proposal is approved.

A confirmed BANK PaymentExecution requires:

- An approved Payment Proposal Item.
- An active BANK CashierPaymentInstrument.
- A mapped active BankAccount.
- Immutable external BankStatementLine evidence proving bank payment.
- Same property.
- Same vendor where the source can prove vendor identity.
- Same currency.
- Exact amount.
- Same bank control account.
- Durable no-double-payment identity.
- A clear distinction between a payment request and confirmed payment execution.

A confirmed BANK PaymentExecution must be one-to-one with the approved proposal item and source bank evidence.

### 5. Controlled accounting lifecycle

The initial governed BANK payment accounting lifecycle, if later source proof exists, is:

```text
External BankStatementLine
+ Approved PaymentProposalItem
+ Active BANK instrument / BankAccount
-> immutable confirmed BANK PaymentExecution
-> Supplier Payment JournalCandidate
-> Candidate Review
-> JournalEntry Draft
-> Finalization Authorization
-> Controlled General Ledger Posting
```

No manual BANK execution is allowed without actual external source evidence.

No General Cashier, Payables, or Banking code may bypass the accepted JournalCandidate review, JournalEntry Draft, finalization authorization, and controlled General Ledger posting path.

### 6. Manual bank reconciliation boundary

Bank reconciliation must be manual and source-proven in the initial scope.

Initial bank reconciliation may proceed only when all source evidence exists:

- Posted BANK supplier payment JournalEntry.
- Linked confirmed BANK PaymentExecution.
- Independent immutable BankStatementLine.
- Exact same property.
- Same BankAccount.
- Same currency.
- Exact amount.
- Supported date scope.
- Active reconciler.
- Narrow reconciliation permission.

Bank reconciliation must not create journals, mutate bank balances, mutate GL balances, mutate BankStatementLine evidence, mutate PaymentExecution, close a Financial Period, close a Business Date, or automatically clear discrepancies.

### 7. Explicit non-goals

This ADR does not authorize:

- Automatic bank import.
- Automatic bank parsing.
- Automatic matching.
- Automatic reconciliation.
- Direct bank balance mutation.
- Direct GL posting.
- Direct Payables posting.
- Manual BANK execution without external bank evidence.
- Payment reversal.
- Payment void.
- Cash reconciliation.
- Bank close.
- Cash close.
- Partial payment.
- Split payment.
- FX.
- Tax.
- Withholding.
- Discount.
- Generic payment engine.
- Generic reconciliation engine.
- Financial Period transition.
- Business Date transition.

### 8. First Banking scope exclusions

The first Banking scope excludes partial payment, split payment, FX, tax, withholding, discount, automatic bank import, automatic matching, payment reversal, cash reconciliation, bank balance mutation, and bank close.

## Consequences

### Positive

- Establishes Banking as the source owner for bank account identity and external bank evidence.
- Prevents BANK instruments from being mistaken for bank-account or bank-transaction source records.
- Requires external BankStatementLine evidence before any BANK payment can be confirmed.
- Preserves the existing controlled General Ledger posting lifecycle for accounting effects.
- Keeps bank reconciliation manual and source-proven until automation is explicitly approved.

### Limitations

- BANK payment confirmation cannot proceed until BankAccount and immutable external BankStatementLine evidence exist.
- Bank reconciliation cannot proceed until posted BANK supplier payment evidence, linked confirmed payment execution, and independent bank statement line evidence exist.
- No automatic import, auto-match, payment reversal, partial payment, split payment, FX, tax, withholding, or discount behavior exists after this ADR.

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

---

**Implementation status:** Policy accepted; Banking source foundation may be implemented as narrow BankAccount identity and immutable external BankStatementLine evidence, while BANK payment confirmation and bank reconciliation require separate source-proven implementation.
