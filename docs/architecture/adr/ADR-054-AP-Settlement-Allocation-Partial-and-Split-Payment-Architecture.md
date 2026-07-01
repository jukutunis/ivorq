# ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ currently supports full-obligation supplier payment only. A Payment Proposal Item references one posted AP liability source JournalEntry, a PaymentExecution preserves full source amount, and supplier payment posting follows the controlled General Ledger lifecycle. Current runtime explicitly does not implement AP settlement allocation, partial payment, split payment, outstanding amount derivation, or allocation-aware reversal behavior.

AP settlement allocation affects Payables visibility, General Cashier payment execution, posted supplier payment JournalEntries, posted AP liability JournalEntries, Financial Period and Business Date boundaries, property scope, vendor scope, currency scope, audit, and authorization. Allocation must be governed before runtime behavior can move beyond full-obligation-only payment.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, allocation records, partial payment runtime, split payment runtime, outstanding amount calculation, payment proposal changes, reversal allocation behavior, FX, tax, withholding, discount, or source mutation.

## Decision

### 1. Domain ownership

Payables owns payment allocation intent and AP settlement visibility.

General Ledger remains the source of posted liability and posted payment accounting evidence.

General Cashier owns payment execution evidence but PaymentExecution is not settlement and must not become the allocation owner.

Payment Proposal selection is not allocation. A Payment Proposal Item may express payment intent, but settlement allocation requires posted accounting evidence.

### 2. Allocation evidence

Allocation is append-only, evidence-based, and linked only to:

- A posted supplier payment JournalEntry.
- A posted AP liability JournalEntry.

Allocation must never be based on an unposted JournalCandidate, unposted JournalEntry Draft, unposted PaymentExecution, unapproved proposal, approved proposal alone, cashbook transaction alone, bank statement line alone, or user-entered settlement status.

Allocation records must preserve property, vendor, currency, allocation amount, source AP liability JournalEntry, supplier payment JournalEntry, actor, timestamp, and immutable source identity.

### 3. Outstanding amount derivation

Outstanding amount is derived from posted allocations only.

Outstanding amount must not be stored as a mutable AP balance, inferred from PaymentExecution, inferred from proposal status, inferred from cashbook, inferred from bank statement lines, or directly mutated by General Cashier.

No over-allocation is allowed.

### 4. Initial partial payment scope

Initial partial payment is limited to:

- Same property.
- Same vendor.
- Same currency.
- One posted AP liability source.
- One payment instruction.
- Source-proven amount less than or equal to the outstanding amount.
- Controlled JournalCandidate review, JournalEntry Draft, finalization authorization, and posting.
- Append-only allocation after posted payment evidence exists.

Partial payment does not authorize FX, tax, withholding, discount, credit note, debit note, advance, prepayment, or write-off behavior.

### 5. Initial split payment scope

Initial split payment is one posted AP liability across sequential posted payment allocations.

It is not a parallel generic batch, not a payment batch splitting engine, not cross-vendor grouping, not cross-property grouping, not cross-currency grouping, and not an automatic settlement engine.

Each split payment must pass through its own source-proven payment instruction, execution evidence, JournalCandidate review, JournalEntry Draft, finalization authorization, controlled posting, and append-only allocation.

### 6. Scope prohibitions

Allocation must fail closed for:

- Cross-vendor allocation.
- Cross-property allocation.
- Cross-currency allocation.
- Unposted AP liability evidence.
- Unposted supplier payment evidence.
- Over-allocation.
- Missing property, vendor, currency, account, amount, actor, or source identity.
- Reversed payment when reversal treatment is not source-proven by the runtime allocation policy.

### 7. Explicit non-goals

This ADR does not authorize:

- Settlement based on unposted candidate evidence.
- Settlement based on unposted draft evidence.
- Settlement based on unposted PaymentExecution.
- Direct AP balance mutation.
- Direct General Ledger mutation.
- Direct cash mutation.
- Direct bank mutation.
- Automatic settlement.
- Automatic write-off.
- FX.
- Tax.
- Withholding.
- Discount.
- Credit note.
- Debit note.
- Advance.
- Prepayment.
- Generic allocation engine.
- Generic payment engine.
- Supplier statement reconciliation.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Establishes Payables as the owner of allocation intent and AP settlement visibility.
- Keeps General Ledger as the accounting source for posted liability and payment evidence.
- Prevents Payment Proposal selection and PaymentExecution from being treated as settlement.
- Supports future partial and sequential split payment only through posted allocation evidence.
- Prevents over-allocation and cross-scope settlement.

### Limitations

- Runtime allocation cannot proceed until posted AP liability evidence, posted supplier payment evidence, durable allocation identity, and outstanding amount derivation from posted allocations are source-proven.
- Existing full-obligation-only behavior must remain until a controlled migration path is proven.
- Reversed payment treatment must be explicit before allocation runtime can include reversed payments.
- FX, tax, withholding, discount, credit note, debit note, advance, prepayment, and write-off remain future decisions.

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
- ADR-050: General Cashier Operational Foundation Architecture.
- ADR-053: Supplier Payment Void, Reversal, and Correction Architecture.

---

**Implementation status:** Policy accepted; AP settlement allocation, partial payment, and sequential split payment require separate source-proven runtime implementation, and full-obligation-only behavior remains in force until that implementation is committed.
