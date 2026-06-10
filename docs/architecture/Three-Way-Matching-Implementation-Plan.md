# IVORQ Sprint 10.1 — Three-Way Matching Foundation

## Architecture Review
The **Three-Way Matching Foundation** will sit inside the existing `Modules/Finance/Payables` module. It bridges the data from:
1. **Purchase Order (PO)** — Establishes the contracted **Price** and **Quantity Expected**.
2. **Goods Receipt Note (GRN)** — Establishes the **Actual Quantity Received**.
3. **Vendor Invoice** — Establishes the **Billed Price** and **Billed Quantity**.

The goal of the matching engine is to ensure that what we are billed matches what we received and what we ordered, calculating variances dynamically before locking the match result. This prepares the system for future AP liability recognition and GL posting.

## 1. Files To Create

**Database & Models**
- `Modules/Finance/Payables/database/migrations/xxxx_xx_xx_xxxxxx_create_three_way_matches_table.php`
- `Modules/Finance/Payables/database/migrations/xxxx_xx_xx_xxxxxx_create_three_way_match_lines_table.php`
- `Modules/Finance/Payables/Models/ThreeWayMatch.php`
- `Modules/Finance/Payables/Models/ThreeWayMatchLine.php`
- `Modules/Finance/Payables/Enums/MatchStatusEnum.php`
- `Modules/Finance/Payables/Enums/MatchExceptionEnum.php`

**Services & Repositories**
- `Modules/Finance/Payables/Services/ThreeWayMatchingEngine.php`
- `Modules/Finance/Payables/Repositories/ThreeWayMatchRepository.php`

**API & Policies**
- `Modules/Finance/Payables/Http/Controllers/ThreeWayMatchController.php`
- `Modules/Finance/Payables/Http/Requests/StoreThreeWayMatchRequest.php`
- `Modules/Finance/Payables/Http/Resources/ThreeWayMatchResource.php`
- `Modules/Finance/Payables/Policies/ThreeWayMatchPolicy.php`

**Tests**
- `tests/Feature/Finance/Payables/ThreeWayMatchingEngineTest.php`

## 2. Files To Modify
- `Modules/Finance/Payables/Models/VendorInvoice.php`: Add `threeWayMatch()` relationship and update statuses if matched.
- `Modules/Finance/Payables/routes/api.php`: Register new endpoint `POST /vendor-invoices/{id}/match` or a standalone resource.
- `Modules/Foundation/Audit/AuditServiceProvider.php`: Add `ThreeWayMatch` and `ThreeWayMatchLine` to `auditableModels`.
- `Modules/Finance/Payables/database/seeders/PayablesPermissionSeeder.php`: Add `payables.match.create`, `payables.match.view` permissions.

## 3. Migration Plan

**`three_way_matches` table:**
- `id` (ULID, Primary Key)
- `property_id` (ULID, Indexed)
- `vendor_invoice_id` (ULID, Unique)
- `purchase_order_id` (ULID)
- `goods_receipt_id` (ULID)
- `status` (String/Enum: Matched, MatchedWithVariance, Exception)
- `exception_code` (Nullable String/Enum)
- `total_quantity_variance` (Decimal)
- `total_price_variance` (Decimal)
- `total_amount_variance` (Decimal)
- `remarks` (Text, Nullable)
- `created_by` (ULID)
- `timestamps()`, `softDeletes()`
- *Audit Columns* applied via trait.

**`three_way_match_lines` table:**
- `id` (ULID)
- `three_way_match_id` (ULID)
- `vendor_invoice_line_id` (ULID)
- `purchase_order_line_id` (ULID)
- `goods_receipt_line_id` (ULID)
- `inventory_item_id` (ULID, Nullable)
- `po_quantity`, `po_price`
- `grn_quantity`
- `invoice_quantity`, `invoice_price`
- `quantity_variance` (Decimal)
- `price_variance` (Decimal)
- `amount_variance` (Decimal)
- `timestamps()`

## 4. Matching Engine Design
The `ThreeWayMatchingEngine.php` service will accept a `VendorInvoice`.
1. It validates **BR-001**: Checks if `purchase_order_id` and `goods_receipt_id` are linked to the invoice and not null.
2. It loops through invoice lines, resolving the linked PO line and GRN line.
3. Computes line-level variances (Quantity, Price, Amount).
4. Aggregates variances to the header level.
5. Determines final status based on aggregated variances.
6. Saves the immutable `ThreeWayMatch` record.
7. Updates the `VendorInvoice` status to `Matched` (or leaves it if Exception).

## 5. Variance Calculation Strategy
- **Quantity Variance** = `Invoice Quantity` - `GRN Quantity`
  - *Positive:* Vendor billed for more than received.
  - *Negative:* Vendor billed for less than received.
- **Price Variance** = `Invoice Unit Price` - `PO Unit Price`
  - *Positive:* Vendor billed higher rate than PO.
- **Amount Variance** = `Invoice Line Total` - (`GRN Quantity` * `PO Unit Price`)
  - Calculates the total monetary discrepancy for the line.

## 6. Matching Status Design (`MatchStatusEnum`)
- **Matched**: All variances are exactly `0.00`.
- **MatchedWithVariance**: Variances exist, but are considered acceptable or were manually forced/accepted (if threshold rules are added later). For now, any variance naturally falls here.
- **Exception**: Fatal errors during matching (e.g., missing linked GRN/PO data, invoice line points to a non-existent PO line).

## 7. Audit Trail Design (BR-008)
- `ThreeWayMatch` and `ThreeWayMatchLine` will use the `HasAuditColumns` trait.
- Both models will be registered in `AuditObserver` inside `AuditServiceProvider` to track all creation events globally.

## 8. Property Isolation Design (BR-009)
- All new models use the `BelongsToProperty` trait and the global `PropertyScope`.
- The Matching Engine will strictly validate that the PO, GRN, and Invoice all share the exact same `property_id`.
- Policies will ensure only users belonging to the specific `property_id` can initiate the match.

## 9. API Design
- `POST /api/v1/payables/vendor-invoices/{id}/match`
  - Initiates the 3-way match.
  - Returns the `ThreeWayMatchResource` showing variances and status.
- `GET /api/v1/payables/matches/{id}`
  - Retrieves a specific match result immutably.
- No `PUT` or `PATCH` endpoints (BR-006, BR-007). The record is strictly immutable.

## 10. Policy Design
- `ThreeWayMatchPolicy`:
  - `view`: User has `payables.match.view` and `property_id` matches.
  - `create`: User has `payables.match.create` and `property_id` matches.
  - No `update` or `delete` abilities.

## 11. Test Plan
- `test_successful_perfect_match()`: Invoice matches PO and GRN exactly.
- `test_match_with_quantity_variance()`: Invoice bills 100, but GRN is 95.
- `test_match_with_price_variance()`: Invoice price is higher than PO price.
- `test_matching_fails_without_po_or_grn()`: (BR-001 constraint).
- `test_matching_aborts_on_cross_property_data()`: (BR-009 constraint).
- `test_match_is_immutable_and_cannot_be_updated()`: (BR-006, BR-007).

## 12. Risks
- **Rounding Discrepancies**: Decimal calculations across PO/GRN/Invoice might yield minor 0.01 fractional differences. *Mitigation: Use strict precision/bcmath if necessary, or rely on standard decimal casting.*
- **Partial Invoicing**: A single PO might receive multiple GRNs and multiple Invoices. *Mitigation: Matching Engine must scope quantity validation strictly to the specific lines linked.*

## 13. Dependencies
- Relies on `VendorInvoice` from Sprint 10.0.
- Relies on `PurchaseOrder` from Sprint 09B.4.
- Relies on `GoodsReceipt` from Sprint 09B.5.
- Relies on `AuditObserver` globally.
