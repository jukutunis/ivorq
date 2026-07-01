# ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ supplier payment runtime currently supports same-currency full-obligation payment only. Existing approved payment, posting, cashbook, Banking, correction, and allocation policies explicitly exclude FX, tax, withholding, and discount behavior until source ownership, authorization, rate evidence, account mapping, and posting treatment are governed.

Payment currency and statutory adjustments can affect Payables, General Cashier, Banking, General Ledger, Financial Periods, Business Dates, property base currency, transaction currency, exchange-rate evidence, tax configuration, withholding configuration, discount authorization, account mappings, posted allocations, audit, and source-traceability. These rules must be governed before runtime can calculate or post adjustments.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, exchange-rate runtime, tax calculation, withholding calculation, discount calculation, adjustment JournalCandidates, allocation changes, payment execution changes, posting changes, or source mutation.

## Decision

### 1. Currency boundaries

Each Property has a base currency boundary. Source transactions also carry transaction currency.

Payment runtime must preserve both boundaries when they differ:

- Property base currency.
- Source transaction currency.
- Payment currency.
- Posted allocation currency.
- JournalEntry currency context where supported by the approved General Ledger model.

No mixed-currency AP settlement may proceed without explicit posted allocation evidence and approved FX treatment.

### 2. Exchange-rate evidence

FX treatment requires immutable approved rate-source evidence.

The system must not trust caller-supplied exchange rates, browser-calculated rates, imported rates without approval evidence, default hardcoded rates, historical backfill, or inferred rates from existing monetary amounts.

Rate evidence must preserve source, effective date, currency pair, rate, approval authority, actor, timestamp, property or enterprise applicability where approved, and immutable identity.

### 3. FX gain and loss

FX gain or loss account source and posting lifecycle must be source-proven before runtime implementation.

Any FX gain or loss line is an accounting effect and must pass through:

```text
Source evidence
-> JournalCandidate
-> Candidate Review
-> JournalEntry Draft
-> Finalization Authorization
-> Controlled General Ledger Posting
```

No direct FX posting, direct GL mutation, direct AP mutation, or automatic adjustment is allowed.

### 4. Tax, withholding, and discount ownership

Tax, withholding, and discount behavior requires source ownership and authorization before runtime implementation.

Tax, withholding, and discount results must not be caller supplied or inferred from a payment amount. Runtime must use approved configuration, source documents, statutory or contractual authority, actor evidence, timestamps, property scope, vendor scope, currency scope, and source identity.

Payment discounts, withholding, and FX adjustments need independent traceability and cannot mutate original AP liability evidence.

### 5. Additional accounting lines

Any additional accounting line for FX, tax, withholding, or discount requires JournalCandidate review, JournalEntry Draft materialization, finalization authorization, and controlled General Ledger posting.

Additional lines must fail closed when source evidence, authorization, account mapping, Financial Period, Business Date, property, vendor, currency, amount, allocation, or payment evidence is missing or ambiguous.

### 6. Initial runtime prohibition

No automatic tax, withholding, discount, or FX calculation is authorized in initial runtime without source-proven rates and configuration.

No historical rates, historical tax treatment, withholding treatment, discount treatment, or adjustment source evidence may be backfilled or fabricated to satisfy a runtime gate.

### 7. Explicit non-goals

This ADR does not authorize:

- Caller-supplied exchange rates.
- Caller-supplied tax result.
- Caller-supplied withholding result.
- Caller-supplied discount result.
- Automatic FX calculation without approved rate evidence.
- Automatic tax calculation without approved configuration.
- Automatic withholding calculation without approved configuration.
- Automatic discount calculation without approved authorization.
- Mixed-currency AP settlement without posted allocation evidence.
- Original AP liability mutation.
- Direct GL posting.
- Direct AP mutation.
- Direct cash mutation.
- Direct bank mutation.
- Historical rate fabrication.
- Historical tax-treatment fabrication.
- Write-off.
- Credit note.
- Debit note.
- Generic tax engine.
- Generic FX engine.
- Generic payment engine.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Defines property base currency and transaction currency as separate controlled boundaries.
- Requires immutable rate-source evidence before FX behavior.
- Prevents caller-supplied tax, withholding, discount, or rate results from becoming accounting truth.
- Keeps every additional accounting line inside the accepted JournalCandidate, review, draft, authorization, and controlled posting lifecycle.
- Preserves original AP liability evidence and requires independent traceability for adjustments.

### Limitations

- Runtime FX, tax, withholding, and discount behavior cannot proceed until authoritative rate evidence, approved configuration, account mappings, authorization, allocation/payment evidence, Financial Period, and Business Date controls are source-proven.
- Mixed-currency AP settlement remains blocked without explicit posted allocation evidence.
- No backfill or fabrication of historical rate or statutory treatment is allowed.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-018: Foreign Currency Revaluation Strategy.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-025: Revenue Recognition and Tax Engine.
- ADR-033: Global Tax and Jurisdiction Compliance Architecture.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture.

---

**Implementation status:** Policy accepted; FX, tax, withholding, and discount runtime remains blocked until all authoritative sources, mappings, allocation/payment evidence, authorization, Financial Period, and Business Date controls are source-proven.
