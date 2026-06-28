# ADR-044: Inventory Reversal v1 Contract

**Status:** Accepted
**Date:** 2026-06-28

## Context

The IVORQ Inventory Ledger is an immutable, append-only record of all physical stock movements. The following conditions are source-proven at the time of this decision:

- `InventoryTransaction` supports `reverses_inventory_transaction_id` as a nullable foreign-key relation capability. No production posting workflow currently populates this field.
- `TransactionTypeEnum` defines `opening_balance`, `purchase_receipt`, `issue`, `transfer_out`, `transfer_in`, `adjustment_in`, `adjustment_out`, and `return`. It does not contain a reversal case.
- ADR-036 established that reversal creates a new opposite-signed ledger entry linked to the original, with its own idempotency key, and that ledger entries are never edited. ADR-036 explicitly deferred all reversal and correction runtime authorization.
- CostControl valuation planners (`ControlledValuationStateTransitionPlanner`, `ControlledAdjustmentValuationPlanner`) currently fail closed for unsupported entry types including `reversal`.
- General Ledger has a separate `JournalReversalService` with database-enforced anti-double-reversal constraints. This is a Finance/GL capability and does not establish Inventory Ledger reversal semantics.
- Physical stock variance is handled exclusively through `AdjustmentIn` / `AdjustmentOut`.
- Value-only cost correction is not approved for implementation.
- Correction remains unsupported and fail-closed.

## Decision

### A. Reversal form

- Full transaction-linked reversal only. No partial reversal.
- One new immutable reversal `InventoryTransaction` references exactly one original immutable `InventoryTransaction`.
- The original transaction is never updated, deleted, replaced, or revalued in place.
- A reversal transaction cannot itself be reversed.

### B. Anti-duplication

- One original transaction can have at most one reversal.
- Future implementation requires database-enforced anti-double-reversal protection (e.g., a partial unique index on `reverses_inventory_transaction_id WHERE reverses_inventory_transaction_id IS NOT NULL`).
- Application-level validation alone is insufficient.

### C. Valuation authority

- Reversal valuation uses the original immutable `InventoryTransaction` unit cost and total cost (sign-negated).
- Current WAUC, legacy Item WAC, adjustment line cost, and caller-supplied cost are never used for reversal valuation.
- No retrospective recalculation of earlier inventory valuation history is performed.

### D. Eligibility and exclusions

- **Opening Balance** is excluded from Reversal v1.
- **Transfer** (`transfer_out`, `transfer_in`) is excluded from Reversal v1. Paired cross-location reversal semantics are not approved.
- Any original transaction with downstream inventory valuation movement that depends on it (e.g., a later issue whose WAUC was derived from the original receipt) is excluded. Downstream detection rules must be defined before runtime implementation.
- This ADR does not silently authorize every remaining transaction type. Exact eligible original transaction types remain implementation-gated and require a source-proven eligibility matrix before any runtime work.

### E. Date and period policy

- New reversal postings must use the current open Business Date and current open Financial Period.
- No back-posting to a closed Business Date or Financial Period is permitted.
- The original immutable evidence remains historical; it is not reopened or altered.

### F. Operational separation

- Physical stock variance remains exclusively `AdjustmentIn` / `AdjustmentOut`. Reversal must not be used as a variance mechanism.
- Correction remains unsupported and fail-closed. This ADR does not authorize correction implementation.
- Value-only cost correction remains deferred pending a separate Finance / GL ADR approval.
- Inventory Reversal must not be used as a generic correction, cancellation, or void mechanism.

### G. Governance

- Manager or Finance approval is mandatory before a reversal can be posted.
- A mandatory audit trail must record the reversal actor, reason, timestamp, and link to the original transaction.
- Runtime implementation requires explicit, separately authorized implementation slices.

## Non-Goals

This ADR does not authorize:

- New enum cases in `TransactionTypeEnum`.
- Database migrations or schema constraints.
- Repository or model changes.
- Mutation of existing `InventoryTransaction` records.
- Planner, coordinator, or apply-coordinator implementation.
- Operational service callers.
- General Ledger posting for reversals.
- Cost Ledger entry creation for reversals.
- CostAvcoState transition logic for reversals.
- UI workflows for reversals.
- Partial reversal.
- Transfer reversal.
- Closed-period exceptions.
- Value-only cost correction.

## Future Implementation Gates

Runtime reversal implementation cannot begin until a later approved slice proves and defines:

1. Exact eligible original transaction-type matrix (receipt, issue, adjustment, return candidates).
2. Immutable original evidence correlation requirements (property, item, location, type, quantity, unit cost, total cost, business date, occurred-at, currency, source identity, source-line identity, idempotency key).
3. Downstream inventory valuation movement detection rule (how to determine if later movements depend on the original transaction's WAUC contribution).
4. Database unique anti-double-reversal enforcement (partial unique index on `reverses_inventory_transaction_id`).
5. Business-date and Financial Period validation point (must verify open status within the outer transaction, after lock acquisition).
6. Approval and audit actor source (who is authorized, what reason is required, what audit event is recorded).
7. Cost Ledger and CostAvcoState reversal transition semantics (how a reversal entry type affects quantity, carrying value, WAUC, and sequence in the controlled valuation planner).
8. Replay/idempotency and rollback behavior (deterministic idempotency key generation, collision detection, full-transaction rollback on any failure).
9. Exact treatment of receipt, issue, adjustment, and return reversal candidates (each may have distinct sign, direction, and downstream impact rules).

## Consequences

### Positive

- Preserves the immutable Inventory Ledger; original transactions are never altered.
- Prevents silent duplicate or partial reversal through mandatory database-level enforcement.
- Prevents overlap with Adjustment (physical variance) and Correction (deferred).
- Prevents back-posting into closed Business Dates or Financial Periods.
- Provides a controlled architectural basis for future Cost Ledger and AVCO state reversal treatment.
- Establishes clear governance requirements (approval, audit) before runtime activation.

### Limitations

- No Inventory Reversal is operational after this ADR. Runtime remains fail-closed.
- No existing transaction type becomes automatically eligible for reversal.
- Requests outside this contract remain fail-closed.
- Reversal implementation will require separate database, domain, valuation, approval, audit, and PostgreSQL proof slices.
- Transfer reversal and value-only correction are explicitly excluded and require separate future ADRs.

---

**Implementation status:** Policy accepted; runtime implementation not authorized.
