# ADR-045: Inventory Reversal v1 Conservative Same-Scope Blocker

**Status:** Accepted
**Date:** 2026-06-28

## Context

ADR-044 requires the exclusion of any original transaction that has downstream inventory valuation movement before a future Reversal v1 runtime workflow can proceed.

Through read-only architecture discovery of the CostControl and Inventory modules, the following facts are source-proven:
- The system can identify the original `InventoryTransaction` identity.
- The system can resolve the exact physical cost scope via `property_id`, `location_id`, and `item_id`.
- The system allocates and tracks a monotonic `valuation_sequence` on each controlled `InventoryTransaction`.
- The system can locate later same-scope movements by querying for a `valuation_sequence` greater than the target sequence.
- Each `CostLedgerEntry` maps to its source transaction via `source_inventory_transaction_id`.
- However, all active cost control invocation services currently pass `prior_cost_ledger_entry_id` as `null` when creating cost ledger entries. As a result, the database does not build a directed parent-child lineage tree showing mathematical dependency.
- Chronological order or a later sequence is not, on its own, mathematical proof that a later movement depends on or was influenced by the original transaction's cost contribution.

Because the system cannot determine mathematical dependency programmatically without a historical AVCO recalculation engine, a conservative blocker is required to safeguard ledger integrity for Reversal v1.

## Decision

### A. Conservative blocker

A future Reversal v1 request is blocked when a later controlled valuation movement exists in the same exact physical cost scope:

`property_id + location_id + item_id`

with a `valuation_sequence` greater than the original transaction's `valuation_sequence`.

### B. Original eligibility baseline

The original transaction must have:
- An immutable database identity.
- Exact property, location, and item identity.
- A non-null controlled `valuation_sequence`.
- A canonical valuation scope consistent with the exact physical cost scope.
- Eligibility under ADR-044 and any later approved transaction-type matrix.

A transaction without a controlled valuation sequence or canonical scope is not eligible for reversal. It must fail closed.

### C. Later movement definition

For the purposes of this blocker, a later movement means any `InventoryTransaction` that:
- Belongs to the same exact property, location, and item scope.
- Has a non-null controlled `valuation_sequence`.
- Has a `valuation_sequence` strictly greater than the original transaction's sequence.
- Is not the original transaction itself.

This blocker does not require proof that the later movement consumed the original transaction's valuation contribution.

### D. Deliberate conservatism

This policy intentionally permits false-positive blocking: a reversal request will be blocked even where a later movement is ultimately economically independent.

This conservative behavior is accepted for Reversal v1 because the repository does not yet provide approved exact dependency lineage or a historical AVCO recalculation engine.

### E. Fail-closed result

When any required original evidence is missing, when a later controlled movement exists, or when the downstream state cannot be evaluated deterministically, the future reversal request must fail closed.

### F. No historical rewrite

This blocker policy does not authorize:
- Recalculation of previous WAUC.
- Mutation of the original `InventoryTransaction`.
- Retroactive Cost Ledger rewrites.
- `CostAvcoState` history rebuilding.
- Bypassing of open Business Date or Financial Period controls.

## Non-Goals

This ADR does not authorize:

- Implementing a downstream-query repository method.
- Database migrations, index creation, or schema modifications.
- Introducing new transaction type enum cases.
- Implementing an Inventory Reversal planner or apply coordinator.
- Runtime reversal posting.
- Automatic approval of Receipt, Issue, Adjustment, or Return reversal.
- Building an exact dependency lineage engine.
- Partial reversal.
- Transfer reversal.
- Opening-balance reversal.
- Closed-period or back-posting exceptions.
- Correction implementation.

## Future Implementation Gates

The following gates must be resolved and approved prior to any runtime reversal implementation:

1. Approved exact eligible original transaction-type matrix.
2. Source-proven repository/query contract for same-scope later controlled movement detection.
3. Race-safe execution point: blocker evaluation inside the future outer transaction and coordinated with the relevant state locks.
4. Exact definition of "controlled valuation movement" at the persistence boundary, including null/legacy sequence handling.
5. Database anti-double-reversal enforcement required by ADR-044.
6. Approval and audit actor source.
7. Open Business Date and Financial Period validation in the future outer transaction.
8. Cost Ledger and CostAvcoState reversal semantics.
9. Idempotency, replay, locking, and full rollback proof.

## Consequences

### Positive

- Avoids pretending chronology is exact valuation lineage.
- Prevents future reversal when later controlled movements make historical AVCO consequences uncertain.
- Avoids retrospective cost recalculation for Reversal v1.
- Remains consistent with the immutable Inventory Ledger and ADR-044 constraints.

### Limitations

- Blocks some reversals that might theoretically be safe.
- Does not prove actual cost dependency.
- Does not enable runtime reversal.
- Requires future query, lock, anti-double-reversal, and valuation slices.

---

**Implementation status:** Policy accepted; query and runtime implementation not authorized.
