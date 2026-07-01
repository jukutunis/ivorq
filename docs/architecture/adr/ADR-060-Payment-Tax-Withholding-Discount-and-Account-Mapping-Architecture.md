# ADR-060: Payment Tax, Withholding, Discount, and Account Mapping Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

ADR-055 blocks payment tax, withholding, and discount runtime until IVORQ has source-proven ownership for approved configuration evidence, account-mapping references, authorization, and candidate-first accounting treatment. Current Payables source documents can carry supplier invoice tax and discount amounts, and General Ledger owns operational identity mappings, but no bounded owner exists for approved payment adjustment configuration evidence.

This ADR establishes only the ownership, evidence, approval, and account-mapping architecture for future payment adjustment configuration. It does not implement models, migrations, services, tests, routes, controllers, UI, APIs, tax calculation, withholding calculation, discount calculation, adjustment posting, payment mutation, allocation mutation, account creation, configuration runtime, Financial Period transition, or Business Date transition.

## Decision

### 1. Ownership

IVORQ will create a bounded Finance Payment Adjustment Reference responsibility.

Payment Adjustment Reference owns immutable approved configuration evidence for:

- Payment tax treatment.
- Payment withholding treatment.
- Payment discount treatment.
- Adjustment account-mapping references.
- Effective-date and applicability evidence.

Payables remains the owner of source supplier evidence, supplier invoice evidence, commercial payment intent, and commercial discount source evidence. General Ledger remains the owner of JournalCandidate creation, review, JournalEntry Draft materialization, finalization authorization, controlled posting, Financial Period guards, Business Date guards, and GL balance mutation.

General Cashier and Banking do not calculate tax, withholding, or discount.

### 2. Configuration evidence contract

Future PaymentAdjustmentConfigurationEvidence must preserve:

- Adjustment type: TAX, WITHHOLDING, or DISCOUNT.
- Property or jurisdiction scope.
- Vendor applicability only when source-proven.
- Currency scope where applicable.
- Rate, fixed rule, or source-policy reference.
- Adjustment account-mapping reference.
- Effective date.
- Source reference.
- Recorded actor.
- Approval actor.
- Timestamps.
- Immutable identity.

Rules:

- No caller-supplied tax, withholding, or discount result.
- No inferred configuration.
- No default implicit rate.
- No backfill of historical statutory treatment.
- No in-place mutation of approved evidence.

### 3. Account-mapping boundary

Payment adjustment configuration may reference an active source-proven GL account mapping only.

Configuration cannot create or mutate GL accounts. Configuration cannot mutate GL balances. Account compatibility must be resolved server-side. Property scope must match. Missing, inactive, ambiguous, cross-property, or unsupported account mapping fails closed.

An account mapping reference is evidence for future eligibility. It is not a posting instruction and does not itself create JournalCandidate lines.

### 4. Approval and segregation of duties

The future initial lifecycle is:

```text
RECORDED
-> APPROVED or REJECTED
```

The creator cannot approve their own configuration. Active database-backed actor and narrow permission are required for record, approval, and rejection actions. Rejection requires a reason.

Approved or rejected configuration is immutable except for identical semantic replay. Conflicting replay fails controlled.

### 5. Future runtime eligibility

Future payment adjustment calculation or posting may proceed only when all of these source categories are present:

- Posted allocation evidence.
- Active approved configuration evidence.
- Source supplier, invoice, or commercial discount evidence.
- Valid property, vendor, and currency scope.
- Active account mapping.
- Explicit authorization.
- Exact payment amount.
- Open Financial Period.
- Open Business Date.
- Candidate-first lifecycle.

Configuration by itself does not calculate, post, settle, allocate, mutate payment evidence, mutate source supplier evidence, mutate supplier invoice evidence, mutate AP liability evidence, or mutate allocation evidence.

Any accounting effect for tax, withholding, or discount must pass through:

```text
Source evidence
-> JournalCandidate
-> review
-> JournalEntry Draft
-> finalization authorization
-> controlled posting
```

### 6. Explicit non-goals

This ADR does not authorize:

- Tax engine.
- Withholding engine.
- Discount engine.
- Automatic calculation.
- Generic statutory engine.
- Generic configuration engine.
- FX.
- Caller-supplied result.
- Direct GL mutation.
- Direct AP mutation.
- Direct cash mutation.
- Direct bank mutation.
- Direct posting.
- Payment mutation.
- Allocation mutation.
- Automatic write-off.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Establishes a bounded owner for approved payment adjustment configuration evidence.
- Keeps commercial supplier and invoice evidence in Payables.
- Keeps posting and accounting mutation inside General Ledger controls.
- Prevents caller-supplied tax, withholding, and discount results from becoming accounting truth.
- Provides an account-mapping boundary for future candidate-first payment adjustment runtime.

### Limitations

- Payment adjustment runtime remains blocked until configuration evidence, account mapping references, allocation evidence, and posting treatment are implemented.
- Configuration evidence does not calculate or post adjustments by itself.
- FX remains governed by ADR-059 and separate runtime controls.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture.
- ADR-013: Period Closing Strategy.
- ADR-025: Revenue Recognition and Tax Engine.
- ADR-033: Global Tax and Jurisdiction Compliance Architecture.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture.
- ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture.
- ADR-058: Partial and Sequential Supplier Payment Migration Architecture.
- ADR-059: FX Rate Evidence Ownership and Approval Architecture.

---

**Implementation status:** Policy accepted; payment tax, withholding, and discount runtime remains blocked until immutable approved configuration evidence and source-proven account-mapping references are implemented.
