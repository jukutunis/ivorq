# Sprint 09B.5: Receiving Foundation & GRN - Implementation Plan

## Architecture Review
The Receiving process bridges Purchasing (Purchase Orders) and Inventory Management. When goods arrive from a vendor against an Issued Purchase Order, a **Goods Receipt Note (GRN)** is generated.
To comply with BR-005 (creates Inventory Receipt Transaction), the `ReceivingService` in the Purchasing module will create the GRN locally and then immediately trigger the `ReceiptService` in the Inventory module to create and post an `InventoryReceipt`.

## User Review Required
> **Risk - Non-Inventory Items**: The `ReceiptService` in the Inventory module strictly requires an `item_id` (InventoryItem) to compute WAC and Stock Movements. If a Purchase Order contains a direct expense or service line (which has no `inventory_item_id`), creating an `InventoryReceipt` will fail. For this sprint, I will assume all received items are physical inventory items that map to an `InventoryLocation` and `InventoryItem`. Please confirm if this is acceptable for the Foundation.

## Open Questions
> 1. Should the `location_id` (Store/Warehouse) be defined per line or globally for the entire GRN? The `InventoryReceiptLine` supports it per line, so I will implement it per line in `GoodsReceiptLine`. Is that correct?
> 2. API Design: Do you prefer a dedicated endpoint `POST /api/v1/purchasing/goods-receipts` or a nested endpoint `POST /api/v1/purchasing/purchase-orders/{id}/receive`? (I will propose the dedicated endpoint in the plan below).

---

## 1. Files To Create
- `database/migrations/*_create_goods_receipts_table.php`
- `database/migrations/*_create_goods_receipt_lines_table.php`
- `Modules/Operations/Purchasing/Enums/GoodsReceiptStatusEnum.php`
- `Modules/Operations/Purchasing/Models/GoodsReceipt.php`
- `Modules/Operations/Purchasing/Models/GoodsReceiptLine.php`
- `Modules/Operations/Purchasing/Repositories/GoodsReceiptRepository.php`
- `Modules/Operations/Purchasing/Services/ReceivingService.php`
- `Modules/Operations/Purchasing/Http/Controllers/GoodsReceiptController.php`
- `Modules/Operations/Purchasing/Http/Requests/StoreGoodsReceiptRequest.php`
- `Modules/Operations/Purchasing/Http/Resources/GoodsReceiptResource.php`
- `Modules/Operations/Purchasing/Policies/GoodsReceiptPolicy.php`
- `tests/Feature/Operations/Purchasing/ReceivingModuleTest.php`

## 2. Files To Modify
- `Modules/Operations/Purchasing/routes/api.php` (Register `goods-receipts` routes)
- `Modules/Foundation/Audit/AuditServiceProvider.php` (Register `GoodsReceipt` to `AuditObserver`)
- `Modules/Operations/Purchasing/Database/Seeders/PurchasingPermissionSeeder.php` (Add `goods-receipt.*` and `receiving.*` permissions)

## 3. Migration Plan
**Table: `goods_receipts`**
- `id` (ULID)
- `property_id`
- `grn_no` (e.g. GRN-2026-000001)
- `purchase_order_id`
- `vendor_id`
- `received_date`
- `status` (Draft, Posted, Cancelled)
- `remarks`
- Audit columns (created_by, updated_by) & Soft Deletes

**Table: `goods_receipt_lines`**
- `id` (ULID)
- `goods_receipt_id`
- `purchase_order_line_id`
- `inventory_item_id`
- `location_id`
- `quantity_received`
- `unit_cost`
- `line_total`

## 4. Inventory Integration Plan
The `ReceivingService` will perform a dual-write transaction:
1. Create `GoodsReceipt` and `GoodsReceiptLine` records.
2. Update the `quantity_received` on `PurchaseOrderLine` and `received_total` on `PurchaseOrder`.
3. Check the total quantities to update `PurchaseOrder` status to `PartiallyReceived` or `FullyReceived` (BR-004).
4. Use the `Modules\Operations\Inventory\Services\ReceiptService` to build an array of `InventoryReceipt` and post it immediately. The `grn_no` will be saved as the `external_reference` in the `InventoryReceipt` (BR-005).

## 5. Audit Trail Plan
- `GoodsReceipt` and `GoodsReceiptLine` will use `HasAuditColumns` and be registered in `AuditServiceProvider` so every receive action is immutably logged to `audit_logs` (BR-006).

## 6. Property Isolation Plan
- `GoodsReceipt` and `GoodsReceiptLine` will implement the `Shared\Traits\BelongsToProperty` trait ensuring global scopes prevent cross-property enumeration or access.

## 7. API Design
```http
POST /api/v1/purchasing/goods-receipts
{
    "purchase_order_id": "01HXXXXX...",
    "received_date": "2026-06-10",
    "remarks": "Delivery by John Doe",
    "lines": [
        {
            "purchase_order_line_id": "01HYYYYY...",
            "location_id": "01HZZZZZ...",
            "quantity_received": 40
        }
    ]
}
```

## 8. Test Plan
- `test_can_receive_issued_po_and_generates_inventory_transaction`
- `test_cannot_receive_draft_po` (BR-001)
- `test_cannot_receive_more_than_quantity_ordered` (BR-002)
- `test_partial_receiving_keeps_po_partially_received` (BR-003)
- `test_full_receiving_completes_po` (BR-004)
- `test_receiving_audit_logs_are_created` (BR-006)

## 9. Risks
- Refer to **User Review Required** regarding Non-Inventory lines. For now, the assumption is that all GRN lines will have an `inventory_item_id` and a target `location_id`.

## 10. Dependencies
- Depends on `Modules\Operations\Inventory\Services\ReceiptService` to function correctly. Ensure the Inventory module is fully intact.
