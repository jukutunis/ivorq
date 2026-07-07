# ADR-081: Controlled Inventory Movement Lifecycle and Quantity Protection

**Status:** Accepted
**Date:** 2026-07-07
**Related ADRs:** ADR-001, ADR-002, ADR-003, ADR-004, ADR-040, ADR-079, ADR-080

## 1. Movement Ownership

Inventory movement ownership remains in Operations/Inventory. `InventoryStockMovement` is the authoritative controlled movement evidence. `InventoryLedgerPostingService` is the only posting boundary.

## 2. Movement Types (Sprint 38)

| Movement Type | Direction | Source Leg |
|---|---|---|
| `GOODS_RECEIPT` (existing) | `IN` | `PRIMARY` |
| `TRANSFER_OUT` | `OUT` | `OUTBOUND` |
| `TRANSFER_IN` | `IN` | `INBOUND` |
| `ISSUE_CONSUMPTION` | `OUT` | `PRIMARY` |
| `COUNT_VARIANCE_IN` | `IN` | `PRIMARY` |
| `COUNT_VARIANCE_OUT` | `OUT` | `PRIMARY` |
| `MANUAL_ADJUSTMENT_IN` | `IN` | `PRIMARY` |
| `MANUAL_ADJUSTMENT_OUT` | `OUT` | `PRIMARY` |

Direction and source_leg are server-derived. Browser must never control movement type, direction, or source_leg.

## 3. Source Leg Extension

The existing uniqueness constraint `(property_id, source_type, source_id)` is extended to `(property_id, source_type, source_id, source_leg)`.

- Goods Receipt: `PRIMARY`
- Transfer: `OUTBOUND` + `INBOUND` paired movements
- Issue, Count, Adjustment: `PRIMARY`

Existing rows retain validity through a default `PRIMARY`.

## 4. Directional Controlled Ledger Quantity

```
SUM(IN quantities) - SUM(OUT quantities)
GROUPED BY property_id, inventory_item_id, inventory_location_id
```

Label: "Controlled Ledger Quantity" — not "Final Stock Balance", "Enterprise Stock-On-Hand".

## 5. Transfer

- Same-property source and destination locations (must differ).
- Creates paired `TRANSFER_OUT` + `TRANSFER_IN` in one transaction.
- No property-wide quantity/value change.
- No cross-property transfers.
- No reversal or cancellation after posting.

## 6. Issue / Consumption

- Outbound operational movement from a source location.
- No POS, recipe, BOM, Engineering integration.
- Server-validated reason code.
- Fails closed before Controlled Ledger Quantity becomes negative.

## 7. Stock Count

- Counts Controlled Ledger Quantity only.
- Server-snapshotted expected quantity at submission.
- Count post fails closed when snapshot is stale.
- Zero variance creates no movement.
- Requester ≠ approver, post actor ≠ approver.

## 8. Manual Adjustment

- Mandatory reason code.
- Independent approval + sensitive confirmation.
- Fails closed before negative controlled quantity.
- Positive adjustment does not fabricate cost (Sprint 39 marks it cost-blocking).

## 9. Lock Order

For each posting: `InventoryItem → InventoryLocation(s) sorted → source identity → ledger posting`.

## 10. Explicit Non-Goals

- No reversal, cancellation, or correction.
- No cost, valuation, GL, AP, Banking mutation.
- No legacy stock migration or backfill.
- No automatic retry.
- No mutable stock balance.
