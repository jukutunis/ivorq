# ADR-046: Inventory Reversal v1 Eligible Original Transaction Matrix

**Status:** Accepted
**Date:** 2026-06-28

## Context

ADR-044 defines the Reversal v1 contract but deliberately does not automatically authorize every inventory transaction type for reversal. Additionally, ADR-045 establishes the conservative same-scope later-movement blocker, which blocks reversals if subsequent controlled valuation movements exist.

`TransactionTypeEnum` defines:
- `opening_balance`
- `purchase_receipt`
- `issue`
- `transfer_out`
- `transfer_in`
- `adjustment_in`
- `adjustment_out`
- `return`

To maintain absolute accounting integrity and prevent cost contamination, a clear boundaries matrix is required to distinguish which transaction types are eligible for future Reversal v1 analysis and which must remain fail-closed.

## Decision

### A. Future-analysis candidates

Only the following original `InventoryTransaction` types are eligible to move forward to future Reversal v1 analysis:
- `purchase_receipt`
- `issue`

This candidate classification is for future-analysis purposes only. It does not authorize runtime posting, database writes, UI availability, or automatic approval.

### B. Explicit fail-closed types

The following transaction types are unsupported for Reversal v1 and must fail closed:
- `adjustment_in`
- `adjustment_out`
- `return`
- `transfer_out`
- `transfer_in`
- `opening_balance`
- `reversal`
- `correction`
- All other unknown, legacy, null-sequence, malformed, or future transaction types unless approved by a subsequent ADR.

### C. Candidate eligibility preconditions

Even the eligible candidates (`purchase_receipt` and `issue`) cannot be reversed operationally unless a future implementation validates and satisfies all of the following preconditions:
- The target transaction is immutable and has a validated database identity.
- The original transaction type is exactly `purchase_receipt` or `issue`.
- The original transaction is not itself a reversal.
- The original transaction has not already been reversed.
- The original transaction has exact property, location, and item identity matching the reversal scope.
- The original transaction has a canonical valuation scope.
- The original transaction has a non-null controlled `valuation_sequence`.
- No later controlled valuation movement exists in the exact same scope, satisfying the conservative blocker rule defined in ADR-045.
- The original transaction is not an `opening_balance` or a `transfer` (inbound or outbound).
- The current posting Business Date and Financial Period are open.
- Manager or Finance approval is verified, and audit logging requirements are met.
- The database anti-double-reversal unique index exists.
- The future Cost Ledger and CostAvcoState reversal transition semantics are fully implemented.

### D. Operational separation

- Physical stock count or variance must use `AdjustmentIn` / `AdjustmentOut` only. Adjustments must not be used as a substitute for reversals, and reversals must not be used as adjustments.
- `return` remains fail-closed because its controlled valuation semantics are not approved.
- `transfer_out` and `transfer_in` remain excluded because paired cross-location reversal semantics are not approved.
- Value-only cost correction remains deferred pending separate Finance / GL ADR approval.

### E. Valuation policy inheritance

For any future approved `purchase_receipt` or `issue` reversal implementation:
- The valuation authority remains the original immutable transaction unit cost and total cost, sign-negated.
- The use of current WAUC, legacy Item WAC, adjustment line cost, or caller-supplied cost is prohibited.
- No historical AVCO or Cost Ledger rewrites are authorized.

## Non-Goals

This ADR does not authorize:

- Runtime Inventory Reversal posting.
- Adding new transaction type enum cases.
- Schema updates, database migrations, or index creation.
- Repository query implementation.
- Planner, coordinator, apply coordinator, or operational caller implementation.
- Cost Ledger entry creation for reversals.
- CostAvcoState transition logic for reversals.
- Reversal approval or audit logging workflows.
- Executing purchase receipt or issue reversals.
- Executing adjustment, return, transfer, or opening-balance reversals.
- Partial reversal.
- Correction implementation.
- Closed-period or back-posting exceptions.

## Future Implementation Gates

No runtime candidate may move forward until a later approved slice implements:

1. Exact original-to-reversal immutable evidence correlation.
2. Race-safe same-scope later-movement query and lock contract under ADR-045.
3. Database anti-double-reversal unique index required by ADR-044.
4. Original transaction locking and revalidation point.
5. Current open Business Date and Financial Period validation point.
6. Manager/Finance approval source, required reason, and audit event logging.
7. Receipt-specific reversal valuation transition semantics.
8. Issue-specific reversal valuation transition semantics.
9. Cost Ledger and CostAvcoState effects for each eligible candidate.
10. Deterministic idempotency, replay protection, and full rollback proof.

## Consequences

### Positive

- Constrains Reversal v1 to two narrow future-analysis candidates.
- Prevents accidental expansion of reversals into Adjustments, Returns, Transfers, Opening Balances, or generic Corrections.
- Gives separate treatment to receipt and issue semantics.
- Preserves fail-closed behavior until every runtime gate is proven.

### Limitations

- Neither `purchase_receipt` nor `issue` can be reversed operationally. Runtime remains fail-closed.
- All other transaction types remain blocked.
- Future receipt and issue treatments may require distinct planners or distinct controlled transition rules.
- Implementation requires database, locking, approval, audit, Cost Ledger, CostAvcoState, and PostgreSQL proof slices.

---

**Implementation status:** Policy accepted; no transaction type is runtime-authorized for reversal.
