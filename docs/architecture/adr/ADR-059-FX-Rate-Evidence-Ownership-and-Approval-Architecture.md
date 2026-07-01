# ADR-059: FX Rate Evidence Ownership and Approval Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

ADR-055 requires immutable approved rate-source evidence before any payment FX behavior can proceed. Current IVORQ runtime preserves property base currency and source transaction currency, but no bounded Finance owner has been established for approved ExchangeRateEvidence. Without that owner, payment FX runtime cannot safely select rates, calculate FX effects, or create candidate-first accounting lines.

This ADR establishes only the ownership and approval architecture for future FX rate evidence. It does not implement models, migrations, services, tests, routes, controllers, UI, APIs, imports, FX calculation, FX posting, FX gain or loss treatment, allocation changes, payment changes, General Ledger posting changes, cash mutation, bank mutation, AP mutation, Financial Period transition, or Business Date transition.

## Decision

### 1. Ownership

IVORQ will create a bounded Finance-owned Foreign Exchange Reference responsibility.

Foreign Exchange Reference owns immutable approved ExchangeRateEvidence and is the future authoritative source for payment FX rate selection. It does not own General Ledger posting, Banking external statement evidence, PaymentExecution, AP allocation, cash balances, bank balances, or payment execution state.

General Ledger remains the owner of JournalCandidate creation, candidate review, JournalEntry Draft materialization, finalization authorization, controlled posting, Financial Period guards, Business Date guards, and GL balance mutation.

Banking remains the owner of external bank source evidence. Payables remains the owner of supplier invoice and AP settlement source evidence. General Cashier remains the owner of payment execution evidence.

### 2. Evidence contract

Future ExchangeRateEvidence must preserve at minimum:

- Base currency.
- Quote currency.
- Rate.
- Rate quote convention.
- Effective date.
- Source reference.
- Property or enterprise applicability only when explicitly approved.
- Recorded actor.
- Approval actor.
- Recorded timestamp.
- Approval timestamp.
- Immutable technical identity.

Rules:

- No caller-supplied trusted rate.
- No browser-calculated rate.
- No implicit default.
- No inferred rate from monetary amounts.
- No historical backfill.
- No unapproved import.
- No mutation of approved rate evidence.

### 3. Approval and segregation of duties

The initial future lifecycle is:

```text
RECORDED
-> APPROVED or REJECTED
```

The creator cannot approve their own rate. Approval or rejection requires an active database-backed actor and a narrow permission. Property or enterprise scope must be validated server-side. Rejection requires a reason.

Approved or rejected evidence is immutable except for identical semantic replay. Conflicting replay fails controlled. This ADR does not authorize runtime FX calculation or posting.

### 4. Rate selection contract

Future payment FX runtime may select a rate only when it can resolve:

- Exact source currency.
- Exact target currency.
- Explicit approved scope.
- Applicable effective date.
- Approved rate evidence.
- Unambiguous quote convention.

The first scope does not allow nearest-date fallback, inverse-rate inference, triangulation, default rate, stale-rate fallback, or source fabrication.

### 5. Correction contract

Rate correction is append-only.

Original approved ExchangeRateEvidence remains immutable. Replacement evidence may reference the correction context when future source design supports it. No implementation may update approved rate evidence in place.

Rate correction must not mutate posted JournalEntries, AP allocations, PaymentExecutions, PaymentProposals, Banking evidence, cash evidence, bank evidence, or historical source evidence.

### 6. Explicit non-goals

This ADR does not authorize:

- FX rate import.
- FX rate API.
- FX calculation.
- FX posting.
- FX gain or loss posting.
- Rate triangulation.
- Inverse-rate inference.
- Historical backfill.
- Tax.
- Withholding.
- Discount.
- Mixed-currency allocation.
- Direct GL mutation.
- Direct AP mutation.
- Direct cash mutation.
- Direct bank mutation.

## Consequences

### Positive

- Establishes a bounded owner for approved FX rate evidence.
- Prevents caller-supplied or inferred rates from becoming accounting truth.
- Preserves General Ledger ownership of posting and period/date controls.
- Keeps Banking external evidence separate from rate reference evidence.
- Provides a source-proven prerequisite for future candidate-first FX runtime.

### Limitations

- FX calculation and posting remain blocked until ExchangeRateEvidence runtime exists.
- FX gain/loss treatment still requires source-proven account mappings and candidate-first posting design.
- Mixed-currency allocation remains blocked until approved rate selection and allocation treatment are implemented.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-018: Foreign Currency Revaluation Strategy.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture.
- ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture.
- ADR-058: Partial and Sequential Supplier Payment Migration Architecture.

---

**Implementation status:** Policy accepted; FX runtime remains blocked until immutable approved ExchangeRateEvidence and exact rate selection controls are implemented.
