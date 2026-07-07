# ADR-080: Controlled Purchasing Requisition, Purchase Order, and Goods Receipt Integration

**Status:** Accepted
**Date:** 2026-07-07
**Related ADRs:** ADR-001, ADR-002, ADR-003, ADR-004, ADR-006, ADR-008, ADR-029, ADR-032, ADR-040, ADR-079

## Validation Status

Controlled Goods Receipt posting:
Delivered and PostgreSQL-validated (58 tests, 114 assertions, 0 failures, 0 errors)

Immutability:
- GoodsReceipt: Eloquent updating/deleting blocked when status=POSTED
- GoodsReceiptLine: Eloquent updating/deleting blocked when parent receipt is POSTED
- InventoryStockMovement: Eloquent updating/deleting always blocked (append-only)

Concurrency:
POSTGRESQL TWO-CONTEXT PROOF PASSED.

Two independent Laravel/PHP worker processes with independent PostgreSQL
connections were validated against the same Purchase Order line using a
disposable isolated PostgreSQL database. Both over-receipt and duplicate
receipt posting were prevented through the actual controlled posting
boundary, row-lock/revalidation behavior, idempotency, and source-correlation
enforcement.

Isolated concurrency test: 2 tests, 13 assertions, 0 failures, 0 errors.
Workers boot separate Laravel containers with independent pg_backend_pid().
Coordinator holds lockForUpdate() on PO line until both workers enter
lock-acquisition stage; lock release deterministically proves real contention.

DB lifecycle: ivorq_concurrency_<runid> created, migrated, seeded, tested, and
successfully dropped with connection termination before DROP DATABASE.
ivorq_testing never altered.

Costing, valuation, AVCO, FIFO, GL, AP invoice, payment,
supplier return, receipt reversal, transfer, issue, count,
adjustment, POS consumption, and stock migration:
NOT AUTHORIZED

**PostgreSQL Validation Evidence (pgsql/ivorq_testing):**
- `InventoryStockMovementLedgerTest`: 21 tests, 49 assertions, 0 failures, 0 errors
- `ControlledGoodsReceiptPostingTest`: 58 tests, 114 assertions, 0 failures, 0 errors
- `SensitiveActionConfirmationTest`: 45 tests, 386 assertions, 0 failures, 0 errors
- Full Banking/Finance Master Regression: 194 tests, 0 failures, 0 errors
- `InventoryReversalWorkspaceTest`: 8 tests, 2 errors (INHERITED_REVERSAL_TEST_DEBT_CONFIRMED)

**Commit chain:**
```
72bb73f Sprint 37: Enforce posted goods receipt immutability
2a8c4f1 Sprint 37: Prove receipt concurrency safety
```

## 1. Narrow Purchasing Scope

This ADR defines an inventory-item purchase workflow for a single property. The pilot scope is:

```
Approved Purchase Requisition
→ Approved Purchase Order
→ Sensitive-confirmed Goods Receipt
→ Immutable Inventory Stock Movement (via ADR-079)
```

## 2. Single-Property Purchase Chain

All purchase documents (Requisition, PO, Goods Receipt) are property-scoped. Cross-property purchasing is deferred.

## 3. Single Supplier per Purchase Order

A PO references exactly one same-tenant `Vendor` (tenant-owned per ADR-006). Multi-vendor POs are deferred.

## 4. Requisition-to-PO Relationship

One approved Requisition → one Purchase Order for this pilot. Multi-requisition consolidation is deferred.

## 5. Purchase Order Line Scope

- Inventory tracked items only (canonical `InventoryItem`).
- Canonical `InventoryUnit` (UOM) from the item.
- Ordered quantity must be positive.

## 6. Commercial Terms Boundary

PO may carry commercial terms (unit price, line total, currency) as Purchasing commercial evidence only. These fields must never enter the Inventory Ledger, valuation, cost, AP, or GL.

## 7. Requisition Maker-Checker Rule

Requisition requester must NOT be the requisition approver.

## 8. Purchase Order Maker-Checker Rule

PO creator must NOT be the PO approver.

## 9. Receiving Segregation Rule

Goods receiver must NOT be the PO approver.

## 10. Approved PO Requirement

Receipt requires an approved, same-property Purchase Order.

## 11. Partial Receipt Rule

A PO line may be partially received through multiple controlled receipts. Cumulative received quantity must never exceed ordered quantity.

## 12. Over-Receipt Prevention

Receipt quantity + already-posted receipt quantity ≤ ordered quantity. Enforced at both application and PostgreSQL levels.

## 13. Goods Receipt Immutable Posting Rule

A posted receipt is append-only. No edit, delete, reverse, or cancel of a posted receipt.

## 14. One Ledger Movement per Receipt Line

Each posted receipt line creates exactly one immutable `InventoryStockMovement` through `InventoryLedgerPostingService`. No double-write.

## 15. Sensitive Confirmation Policy

Posting a Goods Receipt requires a valid session-based sensitive confirmation via `SensitiveActionConfirmationService` with intent `inventory-goods-receipt-posting`. Confirmation binds server-side to: property, PO, PO line identity, item, location, quantity.

## 16. No Receipt Reversal

No receipt reversal state or action exists in this package.

## 17. No Supplier Return

No return-to-supplier workflow.

## 18. No Invoice Matching or AP Posting

Three-way matching, AP invoice posting, supplier invoice processing, and payment remain deferred.

## 19. No Direct Stock Mutation

Purchasing and Goods Receipt services never write a mutable stock balance field.

## 20. No Inventory Reversal Modification

Sprint 37 does not modify existing `InventoryReversal`, `InventoryTransaction`, or reversal v1 behavior.

## 21. Goods Receipt State Machine

```
DRAFT → CONFIRMATION_PENDING → POSTED
```

No reversal, edit, cancellation, or deletion of posted receipts.

## 22. Permission Matrix

| Permission | Role Assignment |
|---|---|
| `inventory.ledger.view` | Supervisor, Department Head, General Manager, Property Admin, Super Admin |
| `inventory.purchasing.requisition.create` | Staff, Supervisor, Department Head |
| `inventory.purchasing.requisition.approve` | Department Head, General Manager |
| `inventory.purchasing.purchase-order.create` | Supervisor, Department Head |
| `inventory.purchasing.purchase-order.approve` | General Manager, Property Admin |
| `inventory.purchasing.goods-receipt.receive` | Staff, Supervisor, Department Head |

Finance roles (Finance Controller, General Ledger Accountant, Accounts Payable Officer, General Cashier) must NOT receive purchasing/receiving permissions.

## 23. Sensitive Confirmation Intent

New registered intent: `inventory-goods-receipt-posting`

The confirmation context is bound server-side to:
- `property_id`
- `purchase_order_id`
- `purchase_order_line_id`
- `inventory_item_id`
- `inventory_location_id`
- `received_quantity`
- `goods_receipt_id`
- receipt idempotency context

The browser must not supply property, supplier, item, location, PO status, approval status, receipt outcome, ledger fields, or confirmation context.

## 24. Implementation Manifest

- `GoodsReceipt` and `GoodsReceiptLine` models (Inventory module)
- `GoodsReceiptStatusEnum` (DRAFT, CONFIRMATION_PENDING, POSTED)
- `ControlledGoodsReceiptPostingService` (Inventory-owned posting orchestrator)
- Extension of `InventoryLedgerPostingService` for receipt integration
- Confirmation intent `inventory-goods-receipt-posting`
- `GoodsReceiptController` with confirmation gate
- Workspace UI for purchasing and receiving queues
- PostgreSQL tests covering all boundaries

## 25. Explicit Non-Goals

- Supplier Invoice creation or approval
- Three-way match (PO → Receipt → Invoice)
- Accounts Payable invoice posting
- Payment Proposal generation
- Goods return to supplier
- Purchase Order amendment after approval
- Purchase Order cancellation after receipt
- Receipt reversal or edit
- Inventory transfer, issue, consumption, count, adjustment
- Stock valuation, AVCO, FIFO, costing
- GL posting, journal entries
- Supplier ranking, auto-reorder, forecast integration
- Any modification to Banking, Cashbook, General Cashier, Payment Execution, or reconciliation
