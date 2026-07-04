# ADR-061: Realized FX Adjustment Candidate Boundary

**Status:** Accepted (Accepted for architecture boundary only)
**Date:** 2026-07-05

## Context

IVORQ supports multi-currency procurement operations where a supplier invoice can be booked in a foreign currency (carrying basis) and subsequently paid using a cash or bank instrument in a different currency (settlement basis). While the FX Rate Evidence foundation and FX operational identity validations have been established, a formal architecture boundary is required to govern how foreign exchange gain and loss adjustments are calculated, stored, and resolved as journal entry candidates.

Because foreign exchange adjustments affect the General Ledger and have statutory compliance implications, IVORQ must enforce a candidate-first lifecycle. It must not silently calculate, draft, or post adjustments without clear, immutable audit trails and strict idempotency controls.

## Decision

This ADR defines the allowed scope and candidate boundary for realized foreign exchange adjustments.

### 1. Scope: Realized Supplier Payment FX Only

The initial IVORQ FX scope is strictly limited to **REALIZED SUPPLIER PAYMENT FX ONLY**. This applies exclusively when all of the following source evidence exists in the repository:
- A posted AP journal entry representing the original carrying basis of a supplier invoice.
- A posted payment journal entry representing the settlement-side cashier disbursement.
- An immutable AP settlement allocation binding the invoice-side and payment-side evidence.
- An exact approved ExchangeRateEvidence record matching the property, currencies, and payment date.
- Active, resolved operational mappings for `FX_GAIN` and `FX_LOSS` identities.

### 2. Full-Settlement Initial Restriction

Initial candidate generation supports only a **one-to-one, fully settled** context. The allocation amount must equal the supplier invoice grand total. Any partial, split, sequential, multi-allocation, or multi-payment settlement contexts fail closed and are excluded.

### 3. Explicit Exclusions

The following scenarios are explicitly excluded from this candidate boundary:
- Unrealized FX remeasurement.
- Period-end FX revaluation.
- Open AP remeasurement.
- Receivables FX.
- Guest deposits or advances.
- Intercompany FX.
- Tax, withholding, or discount interactions.
- FX reversal or void handling.
- Multi-currency/triangulated settlement where base currency is not part of the pair.
- Direct posting without manual review.
- Automatic retry logic.

### 4. Source Ownership & Evidence Policy

The General Ledger module remains the sole owner of JournalCandidate persistence and downstream draft generation. Candidate generation must be entirely server-derived using immutable source-owned relationships.

No caller or API client may supply:
- FX rate.
- Carrying or settlement amount.
- Debit or credit assignments.
- GL account IDs.
- Mapping snapshots or IDs.
- Realization/valuation dates.

All basis calculations must rely strictly on property-base-currency ledger values retrieved from the posted GL journal lines.

### 5. Candidate Idempotency Boundary

A canonical idempotency key must be enforced for each unique allocation context. The system must prevent duplicate or conflicting realized FX candidate creation for the same source AP settlement allocation.

### 6. No Automatic Retry & No Direct Posting

Under no circumstances will the system perform automatic retries on candidate failures or directly post FX adjustments to the General Ledger. Every realized FX candidate must require human review before transitioning to draft journal entries.

## Consequences

### Positive
- Prevents cross-property data leakage by validating property scope on all source documents.
- Ensures a candidate-first audit trail before any general ledger balance is mutated.
- Bypasses unsafe client-supplied rates and amounts, keeping accounting calculations on the server.
- Simplifies debugging by restricting the initial scope to full, one-to-one settlements.

### Limitations
- Multi-allocation, sequential payments, and partial settlement adjustments remain blocked.
- Automatic revaluation of open invoices is deferred.

## Deferred Decisions
- Allocation basis rules for partial and split payment FX.
- Unrealized revaluation scheduling.
- Reversal/void accounting flow.
- Exact rounding policies for fractions of base-currency cents.
- Integration of payment adjustments (tax, withholding, discount) into the FX candidate flow.
- Historical correction and backfill.

## Related ADRs
- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-004: Finance Module Boundary Architecture.
- ADR-011: GRNI Architecture.
- ADR-013: Period Closing Strategy.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture.
- ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture.
- ADR-059: FX Rate Evidence Ownership and Approval Architecture.

---

**Implementation status:** Architecture boundary accepted. Realized FX adjustment candidate logic remains restricted to one-to-one full settlements.
