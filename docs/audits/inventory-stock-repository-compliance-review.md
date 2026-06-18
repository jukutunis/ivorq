# Inventory Stock Repository Compliance Review

## Executive Summary
This document provides a targeted architectural and financial compliance audit of the `Modules/Operations/Inventory/Repositories/InventoryStockRepository.php` file. The review specifically assesses compliance with **ADR-001 (Multi-Tenant Hierarchy)** and **ADR-004 (Finance Module Boundary)**, focusing heavily on Property Isolation, Weighted Average Cost (WAC) calculations, and Inventory Valuation integrity.

## Method Audits

### 1. `forItem(string $itemId)`
**Status:** PASS
* **Analysis:** Relies on the standard Eloquent query builder. Assumes the global property scope is active. Properly isolates reads to the current tenant's active property.

### 2. `forLocation(string $locationId)`
**Status:** PASS
* **Analysis:** Standard querying mechanism with relationships. Global property scope remains intact, ensuring users can only fetch stock for locations within their authorized property.

### 3. `findOrCreateLocked(string $itemId, string $locationId, string $propertyId)`
**Status:** FAIL

* **Exact Line References:** 
  * Lines 29-38: `InventoryStock::withoutGlobalScope('property')->firstOrCreate(...)`
  * Lines 41-45: `return InventoryStock::withoutGlobalScope('property')->where(...)->lockForUpdate()->firstOrFail();`
* **Business Impact:** Operational jobs (like receiving or issuing) can inadvertently or maliciously create inventory records for locations that do not belong to the active property. It breaks the fundamental guarantee that a user or job only mutates their own property's data.
* **Financial Impact:** Enables the creation of phantom stock records across property boundaries, potentially misattributing inventory assets to the wrong property's balance sheet.
* **Multi-tenant Impact:** Severe violation of ADR-001. Explicitly bypasses the non-negotiable global property scope intended to segregate multi-tenant data.
* **Recommended Remediation:** Remove all chained calls to `withoutGlobalScope('property')`. The global scope must be enforced. If this method is called by background queues, the queue job must formally initialize the tenant context (e.g., `TenantManager::setContext($propertyId)`) before executing the repository method.

### 4. `findOrCreate(string $itemId, string $locationId, string $propertyId)`
**Status:** WARNING
* **Analysis:** The method is marked deprecated. While it does not bypass the global scope, it explicitly passes `$propertyId` into `firstOrCreate` while under a global scope. If the passed `$propertyId` conflicts with the active global scope, the operation may fail silently or unexpectedly. Recommended for deletion in favor of proper transaction-wrapped locking.

### 5. `updateBalance(...)`
**Status:** PASS
* **Analysis:** Executes an update based on a specific Primary Key (`$id`). Because the global scope is inherently active, this ensures the system cannot update an ID belonging to another property.

### 6. `totalQuantityForItem(string $itemId)`
**Status:** PASS
* **Analysis:** Executes an aggregate `sum()` of physical quantity for reporting. Because the global property scope is active, the sum is strictly confined to the active property. This complies with multi-tenant boundaries.

### 7. `totalQuantityForItemLocked(string $itemId)`
**Status:** FAIL

* **Exact Line References:**
  * Lines 92-95: `return (string) InventoryStock::withoutGlobalScope('property')->where('item_id', $itemId)->lockForUpdate()->sum('physical_quantity');`
* **Business Impact:** The repository calculates the total physical quantity of a given item across the *entire enterprise database*, combining stock from every property and tenant that shares the same `item_id`.
* **Financial Impact:** **Catastrophic.** The docblock states this is used for "WAC calculation in ReceiptService." By summing stock across all properties, the resulting Weighted Average Cost averages the procurement costs of separate legal entities (e.g., Property A in the US and Property B in Indonesia). This corrupts the Cost of Goods Sold (COGS) and drastically falsifies the Inventory Valuation on the balance sheet. Severe violation of ADR-004.
* **Multi-tenant Impact:** Severe violation of ADR-001. A tenant's WAC calculation directly reads and aggregates the proprietary stock data of every other tenant/property in the system.
* **Recommended Remediation:** Immediately remove `withoutGlobalScope('property')`. WAC and inventory valuation must be calculated on a strict per-property basis. If a multi-property enterprise view is required for HQ reporting, it must be executed by a segregated, read-only Enterprise Service, completely decoupled from the transactional Receipt/WAC engine.

### 8. `lockForUpdate(string $itemId, string $locationId)`
**Status:** PASS
* **Analysis:** Safely acquires a database lock while respecting the global property scope. Prevents cross-property deadlocks.

---

## Final Verdict
The `InventoryStockRepository` currently **FAILS** enterprise compliance due to critical vulnerabilities in methods `findOrCreateLocked` and `totalQuantityForItemLocked`. These bypasses completely disable the property isolation boundaries, exposing the platform to massive data bleeding and catastrophic financial valuation errors. Remediation of these methods must be completed prior to any production deployment.
