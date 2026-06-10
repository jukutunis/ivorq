# IVORQ Sprint 10.0 Implementation Plan — Vendor Invoice Foundation

## 1. Architecture Review & Location Decision

Vendor Invoices sit at the boundary between **Purchasing** (Operations) and **Accounts Payable** (Finance). Because the objective states that this foundation will be the basis for *3-Way Matching*, *Accounts Payable*, and *General Ledger*, we have two architectural options for the placement of `VendorInvoice`:

*   **Option A**: Create a new module `Modules/Finance/Payables` (Recommended). This creates a clean boundary for future AP and GL integration.
*   **Option B**: Keep it within `Modules/Operations/Purchasing` temporarily, as it closely relates to POs and GRNs.

*For this plan, I propose placing it in a new module: `Modules/Finance/Payables` (or `Modules/Operations/Payables` depending on the preferred namespace hierarchy).*

## 2. Files To Create

**Database Migrations:**
- `YYYY_MM_DD_XXXXXX_create_vendor_invoices_table.php`
- `YYYY_MM_DD_XXXXXX_create_vendor_invoice_lines_table.php`

**Models & Enums:**
- `Modules/Finance/Payables/Models/VendorInvoice.php`
- `Modules/Finance/Payables/Models/VendorInvoiceLine.php`
- `Modules/Finance/Payables/Enums/VendorInvoiceStatusEnum.php` (Draft, Submitted, Matched, Cancelled)

**Services:**
- `Modules/Finance/Payables/Services/VendorInvoiceService.php`

**API Layer:**
- `Modules/Finance/Payables/Http/Controllers/VendorInvoiceController.php`
- `Modules/Finance/Payables/Http/Requests/StoreVendorInvoiceRequest.php`
- `Modules/Finance/Payables/Http/Resources/VendorInvoiceResource.php`
- `Modules/Finance/Payables/Http/Resources/VendorInvoiceLineResource.php`

**Repositories & Policies:**
- `Modules/Finance/Payables/Repositories/VendorInvoiceRepository.php`
- `Modules/Finance/Payables/Policies/VendorInvoicePolicy.php`

**Testing:**
- `tests/Feature/Finance/Payables/VendorInvoiceModuleTest.php`

## 3. Files To Modify

- `Modules/Operations/Purchasing/Models/Vendor.php` (Add `invoices()` relationship)
- `Modules/Operations/Purchasing/Models/PurchaseOrder.php` (Add `invoices()` relationship)
- `Modules/Operations/Purchasing/Models/GoodsReceipt.php` (Add `invoices()` relationship)
- `bootstrap/providers.php` or `config/app.php` (To register the new `PayablesServiceProvider`)
- `routes/api.php` (Add Vendor Invoice routes)
- `database/seeders/PermissionsSeeder.php` (Add `vendor-invoice.view`, `vendor-invoice.create`, `vendor-invoice.update`, `vendor-invoice.cancel`)

## 4. Migration Plan

**Table: `vendor_invoices`**
- `ulid('id')->primary()`
- `ulid('property_id')->index()` (BR-007 Property Isolation)
- `ulid('vendor_id')->index()` (BR-001)
- `ulid('purchase_order_id')->nullable()->index()` (BR-002)
- `ulid('goods_receipt_id')->nullable()->index()` (BR-003)
- `string('invoice_number')`
- `date('invoice_date')`
- `date('due_date')->nullable()`
- `string('status')` (Draft, Submitted, Matched, Cancelled) (BR-007)
- `decimal('subtotal', 15, 2)`
- `decimal('tax_amount', 15, 2)`
- `decimal('discount_amount', 15, 2)`
- `decimal('grand_total', 15, 2)`
- `text('remarks')->nullable()`
- `audit_columns()`
- `softDeletes()`
- `unique(['vendor_id', 'invoice_number', 'property_id'])` (BR-004)

**Table: `vendor_invoice_lines`**
- `ulid('id')->primary()`
- `ulid('vendor_invoice_id')->index()`
- `ulid('purchase_order_line_id')->nullable()->index()`
- `ulid('goods_receipt_line_id')->nullable()->index()`
- `ulid('inventory_item_id')->nullable()->index()`
- `string('description')`
- `decimal('quantity', 10, 3)`
- `decimal('unit_price', 15, 2)`
- `decimal('line_total', 15, 2)`
- `audit_columns()`

## 5. PO Integration Plan (BR-002)
- `VendorInvoice` belongs to `PurchaseOrder`.
- `VendorInvoiceLine` optionally maps back to `PurchaseOrderLine`.
- Allows creating an invoice strictly from PO lines (future 2-way matching base).

## 6. GRN Integration Plan (BR-003)
- `VendorInvoice` belongs to `GoodsReceipt`.
- `VendorInvoiceLine` optionally maps back to `GoodsReceiptLine`.
- Allows creating an invoice from a GRN (future 3-way matching base).

## 7. Audit Trail Plan
- Implement `HasAuditColumns` on `VendorInvoice` and `VendorInvoiceLine`.
- Controller and Service layer will ensure `created_by` and `updated_by` are accurately recorded via Sanctum auth.

## 8. Property Isolation Plan
- Apply the `BelongsToProperty` trait to `VendorInvoice`.
- Scope all API endpoints, Policies, and Repositories by the authenticated user's active `property_id`.
- Ensure `invoice_number` uniqueness is scoped by `property_id` + `vendor_id`.

## 9. API Design
- `POST /api/v1/payables/vendor-invoices` (Create Draft/Submitted)
- `GET /api/v1/payables/vendor-invoices` (List)
- `GET /api/v1/payables/vendor-invoices/{id}` (Detail)
- `PATCH /api/v1/payables/vendor-invoices/{id}` (Update)
- `POST /api/v1/payables/vendor-invoices/{id}/cancel` (Cancel)
- *Note: No POST to AP/GL yet (BR-005, BR-006)*

## 10. Dependencies
- **Purchasing Module**: Requires `Vendor`, `PurchaseOrder`, and `GoodsReceipt` to be present and active.
- **Spatie Permissions**: Needs new permissions registered for Vendor Invoice access.

## 11. Risks & Mitigations
- **Risk**: Invoice amount discrepancies compared to PO or GRN.
  **Mitigation**: For now, we only store the raw Vendor Invoice data. Verification logic will be handled in the future 3-Way Matching sprint.
- **Risk**: Premature AP/GL entries.
  **Mitigation**: Explicitly avoid writing to any AP tables/ledgers in `VendorInvoiceService`. We merely save the Document.

---
**CTO Review Required:** 
1. Do you approve placing this in `Modules/Finance/Payables`, or would you prefer it in `Modules/Operations/Payables` or `Modules/Operations/Purchasing` for now?
2. Does the schema adequately cover the requirements for this foundation phase?
