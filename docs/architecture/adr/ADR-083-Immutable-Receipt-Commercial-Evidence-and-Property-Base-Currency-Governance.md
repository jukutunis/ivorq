# ADR-083: Immutable Receipt Commercial Evidence and Property Base Currency Governance

## ADR Metadata
* **ADR Number:** ADR-083
* **ADR Title:** Immutable Receipt Commercial Evidence and Property Base Currency Governance
* **Date:** 2026-07-08
* **Status:** Active
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-004 (Finance Module Boundary), ADR-079 (Controlled Inventory Ledger), ADR-080 (Controlled Purchasing and Goods Receipt), ADR-081 (Controlled Inventory Movement Lifecycle), ADR-082 (Controlled AVCO Cost Evidence)

## Context

Sprint 39 deferred AVCO runtime activation because the commercial inputs (Property.currency, PurchaseOrderLine.unit_cost, PurchaseOrder.currency_code, PurchaseOrder.exchange_rate) were mutable after Goods Receipt posting. A read-only AVCO projection that relies on mutable source data cannot produce stable, auditable cost evidence.

To activate Controlled AVCO Cost Evidence, the source commercial evidence must be:
1. Immutable through supported application boundaries.
2. Protected from direct database mutation.
3. Captured at the exact moment of Goods Receipt posting.
4. Independent of current Purchase Order or Property state.

## Decision

### Wave 1: Immutable Evidence Foundation

#### Property Base Currency Governance

1. Property base currency is a foundational canonical configuration.
2. A property may establish currency at creation only.
3. Property currency cannot be changed after creation through supported application boundaries.
4. PostgreSQL must prevent direct database mutation of Property currency.
5. A future property-currency correction workflow requires a separate architecture decision and is not authorized here.

**Enforcement:**
- Application-boundary guard: Property model booted updating hook blocks currency changes.
- PostgreSQL trigger: A server-side trigger prevents `UPDATE` on `properties` when `currency` changes using `IS DISTINCT FROM` semantics.
- The trigger permits non-currency field updates.
- The trigger permits initial INSERT with any currency value.

#### Immutable Receipt Commercial Evidence

6. Purchase Order commercial terms may remain mutable before receipt posting according to existing Purchasing lifecycle.
7. At Goods Receipt posting, server-resolved commercial terms are captured as an immutable receipt-line snapshot.
8. Snapshot data remains outside `InventoryStockMovement`.
9. The snapshot is source evidence only, not:
   - inventory valuation;
   - cost ledger;
   - financial cost;
   - AP invoice;
   - GL posting;
   - supplier settlement;
   - inventory value record.

10. The receipt posting sensitive confirmation binds to a server-generated commercial evidence hash.
11. A stale confirmation fails closed if commercial evidence changes after confirmation issuance but before posting.
12. Snapshot capture, Goods Receipt posting, and ledger movement creation occur atomically in the existing controlled receipt posting transaction.
13. One posted Goods Receipt line has at most one immutable commercial evidence snapshot.
14. Existing posted Goods Receipt lines are not backfilled.
15. Existing receipt lines without snapshots remain AVCO-cost-ineligible.

#### Commercial Evidence Hash

16. The hash binds: property_id, goods_receipt_id, goods_receipt_line_id, purchase_order_id, purchase_order_line_id, inventory_item_id, inventory_unit_id, received_quantity, property_base_currency_code, purchase_order_currency_code, purchase_order_unit_cost, purchase_order_exchange_rate (nullable).
17. Canonical decimal string representation is used for decimal inputs.
18. Stable key ordering is used.
19. No browser fields or client-generated timestamps.
20. No secret as part of the business payload hash.
21. The hash is evidence binding, not credential storage.

#### Sensitive Confirmation Extension

22. The existing `inventory-goods-receipt-posting` intent is extended with a `commercial_evidence_hash` binding.
23. At confirmation issuance: server resolves commercial evidence, computes canonical hash, stores hash in confirmation context.
24. At posting: server recomputes commercial evidence and hash; posting fails closed if hash no longer matches the confirmation context.

### Wave 2: AVCO Activation

25. Base-currency AVCO may use only immutable receipt snapshot evidence.
26. Non-base-currency snapshots remain `COSTING_BLOCKED_FX_UNSUPPORTED`.
27. Existing `exchange_rate` can be retained as raw commercial snapshot evidence only; it is not used for AVCO calculation or FX activation.
28. No source snapshot may be updated or deleted through supported application boundaries or direct PostgreSQL mutation.

### Runtime Activation Status

```
Wave 1: Immutable evidence and governance delivered only after PostgreSQL proof.
Wave 2: AVCO runtime activation allowed only when Wave 1 gate passes.
Foreign-currency AVCO: Deferred. Non-base-currency evidence remains blocked.
```

## Consequences

* **Positive:** Establishes an auditable, immutable source-evidence chain for AVCO. Separates commercial evidence from inventory movements. Enables deterministic AVCO without relying on mutable current state.
* **Negative:** Existing posted receipts have no snapshot and remain cost-ineligible. New receipts only benefit from snapshot evidence.
* **Tradeoffs:** Snapshot storage increases row count but eliminates temporal coupling to Purchase Order state. No backfill means historical cost data requires separate authorization.

## Future Expansion

Future sprints may introduce:
- Property currency correction workflow (requires separate ADR).
- Foreign-currency AVCO activation from immutable FX evidence.
- Snapshot backfill for historical receipts (requires separate authorization).
- Cost Ledger integration using immutable snapshot evidence.
