# ADR-079: Controlled Inventory Ledger and Goods Receipt Posting Architecture

**Status:** Accepted
**Date:** 2026-07-07
**Related ADRs:** ADR-001, ADR-002, ADR-003, ADR-004, ADR-006, ADR-008, ADR-009, ADR-040, ADR-046

## 1. Inventory Ledger Ownership

The Inventory domain owns controlled stock movement evidence through the `InventoryStockMovement` aggregate. This controlled ledger is authoritative only for movements created through this ledger; it does not represent historical inventory completeness, does not backfill legacy records, and does not convert legacy inventory state.

## 2. Controlled Forward Ledger Boundary

The controlled ledger boundary:
- Is authoritative only for movements created through the `InventoryLedgerPostingService`.
- Does not convert or imply completeness of legacy inventory history.
- Does not reconcile against, backfill into, or restate any pre-ledger inventory records.

## 3. Canonical Item and Location Ownership

- `InventoryItem` (canonical, property-scoped) as defined in the Inventory module.
- `InventoryLocation` (canonical, property-scoped) as defined in the Inventory module.
- `InventoryUnit` (canonical, property-scoped) as defined in the Inventory module for UOM.

## 4. Property Scope and Active-Property Enforcement

All ledger writes use server-resolved property context via `BelongsToProperty` trait and `CurrentPropertyService`. Browser input must never control property assignment.

## 5. Immutable Stock Movement Contract

`InventoryStockMovement` is an append-only, immutable aggregate:
- No update route, service, or controller mutation action exists.
- No delete route, service, or controller mutation action exists.
- No ORM `update()` or `delete()` through supported application boundaries.
- The model has `public $timestamps = false; protected $guarded = ['*'];`.

## 6. Quantity-Only Movement Semantics

The controlled ledger stores:
- Identity (ULID primary key)
- Property reference (server-resolved)
- Inventory item reference
- Inventory location reference
- Movement type (server-fixed)
- Direction (server-fixed)
- Positive decimal quantity
- Canonical UOM (server-resolved from item)
- Source domain, source model, source ULID
- Correlation ID
- Idempotency key
- Occurred at timestamp
- Audit evidence (created_by, created_at)

The ledger does not store: unit_cost, total_cost, currency, exchange_rate, valuation data, inventory balance fields, opening/closing quantities, AVCO state, GL account, journal reference, or supplier invoice data.

## 7. Initial Permitted Movement Type

`GOODS_RECEIPT` is the only permitted movement type in Sprint 36. The `InventoryMovementTypeEnum` defines this with room for future expansion.

## 8. Server-Owned Direction

`IN` is the only permitted direction. Direction is server-derived from movement type and must never be browser-supplied.

## 9. Source Correlation

Each movement must carry source correlation:
- `source_domain` — fixed as `purchasing` for future Goods Receipt integration.
- `source_type` — the source model class (e.g., `GoodsReceiptLine`).
- `source_id` — the source record ULID.

## 10. Idempotency Policy

- One `property_id` + `idempotency_key` → at most one successful posting.
- Enforced via a PostgreSQL unique constraint.
- Exact replay returns the original outcome without mutating state.

## 11. Duplicate Movement Prevention

- One `property_id` + `source_type` + `source_id` → at most one successful movement.
- Enforced via a PostgreSQL unique constraint.

## 12. Stock-on-Hand Projection Rules

A read-only, server-owned `Controlled Ledger Quantity` projection derives:
```
SUM(successful controlled movement quantities)
GROUP BY property_id, inventory_item_id, inventory_location_id
```

This projection:
- Is labeled "Controlled Ledger Quantity" — it is NOT complete enterprise stock-on-hand.
- Does not read or mutate legacy balance fields (`InventoryStock`, `InventoryStockBalance`, etc.).
- Is not materialized into a separate table; it is a query over `InventoryStockMovement`.

Forbidden labels: "Final Stock Balance", "Available to Sell", "Valuated Inventory", "Financial Inventory Balance".

## 13. No Direct Mutable Balance Update

No service, controller, or migration writes a mutable `quantity_on_hand`, `physical_quantity`, or equivalent field. Stock-on-hand is derived only from the immutable ledger.

## 14. No Negative Stock / No Issue Scope

Sprint 36 does not create issue, transfer, count, or adjustment movements. Only inbound `GOODS_RECEIPT` / `IN` is supported.

## 15. No Cost / No Accounting / No AP / No Supplier Invoice

No cost, valuation, AP, GL, or supplier invoice data enters the controlled ledger.

## 16. Inventory Reversal Coexistence

Sprint 36 does not mutate, rebind, or reinterpret existing `InventoryReversal`, `InventoryTransaction`, `InventoryStockCard`, `InventoryStock`, `InventoryStockBalance`, or any reversal v1 behavior.

## 17. Future Movement Expansion

Transfer, Issue, Return, Count, and Adjustment remain separate approved packages that will extend the `InventoryMovementTypeEnum`.

## 18. Future Valuation

AVCO first, FIFO later — both deferred. A future Cost Ledger may consume these movements for costing.

## 19. Goods Receipt Posting Contract for Sprint 37

Sprint 37 Goods Receipt posting must:
1. Use `InventoryLedgerPostingService` as the only posting boundary.
2. Provide server-resolved: property, item, location, quantity, source identity, idempotency key.
3. Create exactly one `InventoryStockMovement` per receipt line.
4. Not write a mutable stock balance directly.
5. Use sensitive confirmation before posting.

## 20. Sensitive Confirmation Requirement

A future `inventory-goods-receipt-posting` intent must be registered with `SensitiveActionConfirmationService`. This is deferred to Sprint 37.

## 21. Immutable Correction Policy

- No update or delete of ledger movements.
- Future reversal/correction must use a separately authorized Inventory movement pattern with a new movement entry, not an in-place edit.

## 22. UI Interaction Standard

Per ADR-040:
- Read-only workspace with queue-first interaction.
- Server-projected status evidence.
- No generic CRUD shell, no AdminLTE sidebar pattern, no raw model dump pages.
- Controlled empty state when no ledger movements exist.
- Costing and valuation deferred markers.

## 23. Wave 1 and Sprint 37 Integration Manifest

Sprint 36 delivers:
- `InventoryStockMovement` aggregate and schema.
- `InventoryMovementTypeEnum` and `InventoryMovementDirectionEnum`.
- `InventoryLedgerPostingService` posting contract (skeleton ready for Sprint 37).
- Read-only workspace showing "Controlled Ledger Quantity".
- Permission `inventory.ledger.view`.

Sprint 37 will consume:
- The posting contract from `InventoryLedgerPostingService`.
- The canonical `InventoryItem`, `InventoryLocation`, and `InventoryUnit`.
- The canonical `Vendor`, `PurchaseRequest`, and `PurchaseOrder`.

## 24. Explicit Exclusions

This ADR does not authorize:
- Purchase Requisition, Purchase Order, or Goods Receipt creation routes.
- Goods Receipt mutation action.
- Inventory issue, transfer, count, adjustment, or return.
- Costing, AVCO, FIFO, valuation, or GL posting.
- Supplier invoice processing, AP settlement, or payment.
- Legacy inventory data migration or backfill.
- Any modification to existing `InventoryTransaction`, `InventoryStockCard`, `InventoryStock`, `InventoryStockBalance`, `InventoryReversal`, Finance, Banking, General Cashier, Payables, or Approval Engine internals.
