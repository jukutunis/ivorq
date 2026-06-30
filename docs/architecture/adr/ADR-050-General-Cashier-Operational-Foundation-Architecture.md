# ADR-050: General Cashier Operational Foundation Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ now has a controlled Supplier Invoice, GRNI/AP clearing, AP liability posting, Payment Proposal Draft, Payment Proposal cancellation, and Payment Proposal approval lifecycle. ADR-049 defines that future supplier payment execution must be owned by General Cashier and must remain separate from Payables approval, General Ledger posting, Banking source of truth, and reconciliation.

No General Cashier operational module currently exists. Before runtime payment execution can be created, IVORQ needs a narrow operational foundation for cashier sessions, cashier operator identity, and property-scoped payment instrument configuration.

This ADR is policy only. It does not create models, migrations, services, tests, routes, controllers, API, UI, Payment Execution evidence, payment candidates, JournalEntry Drafts, JournalEntries, posting, AP settlement, cashbook records, bank transactions, reconciliation, Financial Period transitions, or Business Date transitions.

## Decision

### 1. Domain ownership

General Cashier owns:

- Cashier Session operational evidence.
- Cashier operator identity within a property.
- Payment Instrument operational identity.
- Property-scoped instrument eligibility.
- Operational cash or bank control-account reference validation.
- Future Payment Execution evidence.
- Future executed payment operational evidence.

General Cashier does not own:

- Supplier Invoice.
- Payment Proposal.
- AP liability source JournalEntry.
- GRNI evidence.
- Purchase Order.
- Receiving.
- Inventory.
- Cost Ledger.
- GL posting.
- Financial Period.
- Business Date.
- Bank transaction source of truth.
- Bank reconciliation.
- Cashbook balance.
- Cash count close.

Payables owns proposal, approval, vendor, and payment-eligibility evidence.

General Ledger owns JournalCandidate, JournalEntry, controlled posting, Financial Period guard, Business Date guard, and GL balance mutation.

Banking owns future bank-account source of truth, bank transaction evidence, and reconciliation.

### 2. Initial Cashier Session contract

The initial supported Cashier Session lifecycle is:

```text
OPEN
-> CLOSED
```

A session is property-scoped and actor-scoped.

Rules:

- One OPEN CashierSession may exist per property and cashier actor.
- Opening requires an active database-backed actor, active property membership, and a narrow permission.
- Closing requires the same source-proven session owner unless a future explicitly authorized override policy is accepted.
- Closing records actor and timestamp.
- Closing is operational only.
- Closing is not cash count close.
- Closing is not cashier close.
- Closing does not calculate expected cash.
- Closing does not create cashbook evidence.
- Closing does not post.
- Closing does not mutate Financial Period, Business Date, cash, bank, AP, or GL.

### 3. Initial Payment Instrument contract

A CashierPaymentInstrument is property-scoped operational configuration.

The initial supported instrument types are:

```text
CASH
BANK
```

Rules:

- Each instrument has a human-readable business name.
- Each instrument references one active existing GL operational control account.
- Instrument type and property are immutable after future execution evidence uses the instrument.
- Active or inactive status controls future eligibility only.
- Deactivation must not alter historical evidence.
- An instrument must never create or own GL account balances.
- The reference to a GL account is operational mapping only.
- A BANK instrument is not a bank transaction source and is not bank reconciliation evidence.
- A BANK instrument must fail closed for future execution if a required Banking source-of-truth reference is unavailable.
- No cash drawer, bank account, cashbook, or bank transaction table is authorized here.

### 4. Future Payment Execution boundary

Future Payment Execution may begin only from:

- APPROVED Payment Proposal Item.
- OPEN Cashier Session.
- Active property-scoped CashierPaymentInstrument.
- Valid property, vendor, currency, and full-source obligation.
- Active operational GL control account.
- Real active database-backed cashier actor.
- Durable one-to-one Payment Proposal Item to Payment Execution identity.

Execution evidence must be immutable and candidate-first.

Payment Execution itself must not directly mutate cash, bank, AP, Supplier Invoice, GRNI, or GL.

### 5. Instrument and account safety

General Cashier must:

- Resolve account references server-side.
- Validate the account is active.
- Validate property compatibility through source-proven property ownership.
- Never accept caller-supplied account identity as trusted for execution.
- Never infer cash or bank availability.
- Never calculate cash or bank balances.
- Fail closed on missing, inactive, cross-property, unsupported, or ambiguous account or instrument configuration.

### 6. Audit, scope, idempotency, and retry

General Cashier requires:

- Property-scoped validation.
- Active database-backed actor.
- Immutable session open and close evidence.
- Immutable future instrument and execution provenance.
- Source-proven permission checks.
- No fabricated legacy provenance.
- No automatic retry.
- One controlled request attempt.
- Semantically identical replay returns durable evidence only where a future service explicitly supports it.
- Conflicting replay fails controlled.

### 7. Explicit non-goals

This ADR does not authorize:

- Payment execution.
- Payment posting.
- Payment candidate.
- Payment allocation.
- Cash disbursement.
- Bank disbursement.
- Cashier float.
- Cash count.
- Cashbook.
- Bank account source of truth.
- Bank transaction.
- Bank reconciliation.
- Cash reconciliation.
- Payment reversal.
- Payment void.
- Partial payment.
- FX.
- Tax.
- Withholding.
- Discount.
- Prepayment.
- Advance.
- Generic cashier framework.
- Generic payment engine.
- Generic workflow.
- Direct GL posting.
- Financial Period transition.
- Business Date transition.
- Source-document mutation.

### 8. High-level implementation sequence

1. Cashier Session and Payment Instrument operational foundation.
2. Approved Payment Proposal Item to immutable Payment Execution evidence.
3. Payment Execution to Supplier Payment JournalCandidate.
4. Existing candidate review, draft, authorization, and controlled posting reuse.
5. Banking source-of-truth and bank or cash reconciliation foundations.
6. Separate future decisions for cash count, cash float, payment reversal, partial payment, FX, tax, withholding, and close procedures.

## Consequences

### Positive

- Gives General Cashier a narrow operational foundation without creating payment execution.
- Preserves Payables ownership of proposal and approval evidence.
- Keeps GL posting, Financial Period, Business Date, and balance mutation inside General Ledger.
- Avoids inventing cashbook, bank transaction, bank account source-of-truth, cash count, or cashier float behavior.
- Establishes property-scoped session and instrument controls needed by a later execution slice.

### Limitations

- No Payment Execution exists after this ADR.
- No supplier payment candidate, JournalEntry Draft, JournalEntry, posting, AP settlement, cash movement, bank movement, or reconciliation exists after this ADR.
- BANK instruments are operational account references only and are not Banking source-of-truth records.
- Future execution must still prove approved proposal item, one-to-one identity, payment instrument, source obligation, account, actor, property, vendor, currency, and no-posting boundaries before implementation.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-047: GRNI Clearing and AP Liability Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.

---

**Implementation status:** Policy accepted; General Cashier operational sessions and instruments may be implemented as a narrow foundation, but Payment Execution, payment candidates, payment posting, cash or bank mutation, settlement, and reconciliation remain unauthorized by this ADR.
