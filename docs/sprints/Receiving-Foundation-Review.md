# IVORQ Sprint 09B.5 Review — Receiving Foundation & GRN

## Sprint Objective
Membangun Receiving Foundation dan Goods Receipt Note (GRN) untuk menerima barang dari Issued Purchase Order.

## Scope & Constraints (Approved by CTO)
1. **Inventory Only**: Support inventory items only. Non-inventory/direct expense/service lines are out of scope.
2. **Location**: `location_id` is per `GoodsReceiptLine`.
3. **API Endpoint**: `POST /api/v1/purchasing/goods-receipts`.
4. **PO Status Constraint**: Receiving is allowed only for POs with status `Issued` or `PartiallyReceived`. Cancelled POs cannot be received.
5. **Immutability**: Posted `GoodsReceipt` is immutable.
6. **Out of Scope**: AP, Invoice Matching, 3-Way Matching, GL, Cost Control, Direct Expense Receiving are not implemented in this sprint.

## Changes Implemented

### 1. Database Migrations
- `create_goods_receipts_table`
- `create_goods_receipt_lines_table`

### 2. Models & Enums
- `GoodsReceiptStatusEnum` (Draft, Posted, Cancelled)
- `GoodsReceipt` Model (with `HasUlid`, `BelongsToProperty`, `HasAuditColumns`)
- `GoodsReceiptLine` Model (with `HasUlid`, `HasAuditColumns`)

### 3. Service Layer
- **`ReceivingService`**: Implements core business logic for receiving PO items.
    - Validates PO status (`Issued` or `PartiallyReceived`).
    - Validates quantities to prevent over-receiving.
    - Creates `GoodsReceipt` and `GoodsReceiptLine` records.
    - Integrates with `InventoryReceiptService` to post inventory transactions and update stock.
    - Updates PO and PO Line quantities and status (transitions to `PartiallyReceived` or `FullyReceived`).

### 4. API & Controllers
- `StoreGoodsReceiptRequest`: Request validation for GRN creation.
- `GoodsReceiptController`: Handles the `POST /api/v1/purchasing/goods-receipts` endpoint.
- `GoodsReceiptResource` & `GoodsReceiptLineResource`: API Response transformations.
- `GoodsReceiptRepository`: Query abstractions.

### 5. Testing
- Added `ReceivingModuleTest` verifying:
    - Successful receiving generates GRN, updates PO status, and creates `inventory_receipts`.
    - Cannot receive a draft PO.
    - Cannot receive more than the ordered quantity.
    - Full receiving completes the PO (`FullyReceived` status).
- Ensured integration test compatibility with existing constraint logic for properties, vendor status, and inventory tracking.

## Test Results
- All tests passing: **1497 tests**, **4032 assertions**.

## Next Steps
- Implement Accounts Payable and Invoice Matching (if requested in the next sprint).
- Further integration with GL and Cost Control when instructed.
