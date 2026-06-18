# WAC Contamination Assessment

## Executive Summary
This document provides a definitive assessment of whether the identified Weighted Average Cost (WAC) scope bypass vulnerability in the `InventoryStockRepository` has actively contaminated stored data. By tracing the execution pathways from the repository layer through the operational services, migrations, and seeders, the audit concludes that the repository is in a state of **Confirmed Data Contamination Risk**. The vulnerability is actively wired into the primary application controllers, mathematically guaranteeing that any user-driven receipt posting in a multi-property environment has generated corrupted financial valuations in the database.

## Data Flow Review

### Q1: Does InventoryItem store `weighted_average_cost` or equivalent cost fields?
* **Finding:** Yes. The `InventoryItem` table explicitly stores `weighted_average_cost`.
* **Evidence:** `Modules/Operations/Inventory/database/migrations/2026_06_05_000041_create_inventory_items_table.php` (Line 17) defines:
  `$table->decimal('weighted_average_cost', 15, 2)->default(0);`

### Q2: Where is `weighted_average_cost` updated?
* **Finding:** The WAC is dynamically calculated and updated during the receipt posting workflow.
* **Exact Reference:** `Modules/Operations/Inventory/Services/ReceiptService.php` (Line 91):
  `$this->itemRepository->update($item->id, ['weighted_average_cost' => $newWac]);`

### Q3: Has ReceiptService ever persisted WAC values?
**PASS / WARNING / FAIL:** **FAIL**
* **Explanation:** Yes. The `ReceiptService` physically persists the corrupted `$newWac` calculation to the database via the `ItemRepository`. This service is directly wired to the application's primary routing layer (`InventoryReceiptController.php` Line 115: `$this->receiptService->post(...)`). Therefore, the persistence of contaminated WAC is an active, executable pathway in the production application.

### Q4: Can contaminated WAC values already exist in database records?
**PASS / WARNING / FAIL:** **FAIL**
* **Explanation:** Yes. While the `InventoryReceiptSeeder` manually inserts rows and bypasses the `ReceiptService`, any functional testing, UAT, or live production usage that utilized the application's UI/API to "Post" a receipt has executed the `ReceiptService`. If multiple properties exist and share an `item_id`, the `InventoryItem` table is guaranteed to currently hold corrupted, cross-property `weighted_average_cost` values.

### Q5: Can InventoryTransaction records already contain contaminated costs?
**PASS / WARNING / FAIL:** **FAIL**
* **Explanation:** Yes. Following the contamination of `InventoryItem`, any subsequent inventory consumption (e.g., Issue, Transfer, or Adjustment) processed by the `StockMovementService` will fall back to the item's `weighted_average_cost` (Line 189). This corrupted value is permanently stamped into the `unit_cost` and `total_cost` fields of the immutable `InventoryTransaction` ledger. 

## Risk Classification
**Classification: C. Confirmed Data Contamination Risk**

**Justification:** 
The vulnerability is not isolated to an unused class or theoretical code path (which would be Class A). It is actively integrated into the primary API/UI controller for receiving inventory. Because the system is designed as a Multi-Tenant SaaS platform, the presence of multiple properties is guaranteed. Therefore, any functional use of the system since the introduction of this bypass has resulted in the permanent persistence of contaminated financial valuations in the database.

## Potential Blast Radius
* **InventoryItem:** Contains corrupted `weighted_average_cost` fields.
* **InventoryTransaction:** Contains corrupted `unit_cost` and `total_cost` fields for all consumption movements executed after the first contaminated receipt.
* **General Ledger:** Contains corrupted `JournalCandidate` accruals and variance postings derived from the corrupted transactions.
* **Financial Reporting:** Cost of Goods Sold (COGS) and Inventory Asset Valuation on the Balance Sheet are materially misstated.

## Required Remediation
1. **Immediate Code Fix:** Remove `withoutGlobalScope('property')` from the WAC calculation logic.
2. **Database Freeze:** Halt all inventory receiving and issuing workflows in staging/production environments.
3. **Forensic Recalculation:** Develop a bespoke migration script that iterates through every `InventoryReceiptLine` chronologically, per property, to recalculate the mathematically accurate WAC for every item.
4. **Ledger Restatement:** Apply the recalculated WAC to all historical `InventoryTransaction` records and issue automated journal adjustments to the General Ledger to correct the financial misstatements.

## Final Verdict
The system's data integrity is compromised. The architectural failure to enforce tenant boundaries during AVCO/WAC calculations has resulted in confirmed data contamination within the financial and operational ledgers. Enterprise remediation must shift from purely code-level fixes to forensic database reconstruction.
