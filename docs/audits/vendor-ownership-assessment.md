# IVORQ Vendor Ownership Assessment

**Date:** 2026-06-19  
**Reviewer:** Chief Technology Officer / Hospitality ERP Architect

## 1. Executive Summary
Operating in ARCHITECTURE REVIEW MODE, this assessment addresses the "Vendor Ownership Ambiguity" identified during WP005. The current database schema for Vendors includes both `property_id` and `company_id` (Tenant), leading to uncertain ownership and authorization boundaries. As IVORQ scales to support multi-property operations, a definitive Vendor Ownership model is crucial for Enterprise Procurement, Accounts Payable (AP), and accurate Cost Control. After evaluating three potential models, the **Tenant-Owned Vendor (Option B)** is the strongly recommended approach to align with hospitality ERP best practices.

## 2. Current State Assessment
- **Schema Context:** The `vendors` table defines nullable `property_id` and `company_id`.
- **Constraint Context:** A unique composite key exists for `['property_id', 'vendor_code']`.
- **Current Ambiguity:** The system technically permits vendors to be created at the Property level OR the Tenant (Company) level. However, creating a Tenant-level vendor breaks the unique code constraint per property, risking massive data duplication and cross-contamination when multi-property ledgers are aggregated.
- **Affected Domains:** Purchasing, Accounts Payable, Cost Control, Inventory Procurement.

## 3. Option A Analysis: Property-Owned Vendor
**Model:** Property ↓ Vendor
Each Property maintains its own entirely isolated list of vendors.
- **Advantages:** 
  - Absolute data isolation.
  - Simple authorization guard rails (strict `property_id` scoping).
  - No cross-property data leakage.
- **Disadvantages:**
  - Massive data duplication (the same vendor must be created 10 times for a 10-property tenant).
  - Breaks centralized Accounts Payable (AP cannot issue a single bulk payment to a vendor supplying multiple properties).
  - Precludes Enterprise Procurement reporting (cannot easily measure total volume/spend across the Tenant).
- **Hospitality/ERP Suitability:** Poor for multi-property SaaS. Good only for single, independent hotels.

## 4. Option B Analysis: Tenant-Owned Vendor
**Model:** Tenant ↓ Vendor | Property ↓ Transactions (PRs, POs, Receipts)
The Tenant (Company) owns a single master vendor directory. Individual properties link their operational transactions (Purchase Orders, Invoices) to these master vendor records.
- **Advantages:**
  - "Single Source of Truth" for vendor master data (Tax IDs, banking info).
  - Empowers Enterprise Procurement (Centralized contracting, volume discount negotiations).
  - Enables Centralized Accounts Payable (Consolidated aging reports, bulk payment runs).
  - Prevents data duplication.
- **Disadvantages:**
  - Requires more sophisticated scoping; operational users at Property A will see the same vendor list as Property B.
  - Requires updating the unique `vendor_code` constraint from `property_id` to `company_id`.
- **Hospitality/ERP Suitability:** Excellent. This is the industry standard for multi-tenant enterprise hotel groups.

## 5. Option C Analysis: Hybrid Vendor Model
**Model:** Tenant Vendor + Property Vendor
Allows properties to create localized vendors while the corporate office creates master shared vendors.
- **Advantages:**
  - Maximum flexibility.
- **Disadvantages:**
  - Extreme complexity in querying, reporting, and scoping.
  - High risk of data duplication (a property user creates a local vendor because they didn't see the corporate one).
  - AP reconciliation nightmare.
- **Hospitality/ERP Suitability:** Avoid. Unnecessarily complex and error-prone.

## 6. Comparative Matrix

| Feature | Option A (Property) | Option B (Tenant) | Option C (Hybrid) |
| :--- | :--- | :--- | :--- |
| **Data Duplication Risk** | High | Low | Extreme |
| **Centralized AP Readiness** | Poor | **Excellent** | Poor |
| **Enterprise Procurement** | Poor | **Excellent** | Moderate |
| **Scoping Complexity** | Low | Moderate | Extreme |
| **Industry Alignment** | Poor | **Excellent** | Poor |

## 7. Final Recommendation
### **RECOMMENDED: OPTION B (Tenant-Owned Vendor)**
IVORQ must adopt the **Tenant-Owned Vendor** architecture. A centralized vendor master list managed at the `company_id` level is a fundamental requirement for any serious hospitality ERP. Attempting to manage Accounts Payable across a multi-property hotel group without a unified vendor directory is mathematically and operationally untenable.

## 8. Migration Impact & Implementation Complexity
- **Schema Update:** Drop `property_id` from the `vendors` table. Make `company_id` non-nullable.
- **Constraint Update:** Drop the `['property_id', 'vendor_code']` unique index. Add a `['company_id', 'vendor_code']` unique index.
- **Codebase Update:** Refactor `VendorRepository`, Purchase Order creation workflows, and Vendor API endpoints to scope exclusively by `company_id`.
- **Complexity:** Moderate. Requires careful migration scripts to map any existing property-level vendors up to the company level and resolve duplicate vendor codes.

## 9. Risk Assessment
- **Status Quo Risk:** **P0 Critical**. Leaving the ambiguity in place guarantees broken AP ledgers when multi-property is activated.
- **Migration Execution Risk:** **P2 Medium**. Existing data must be carefully merged to avoid orphaned Purchase Orders during the schema transition.

## 10. Classification
- **Vendor Ownership Refactoring:** **P0 Critical** (Must be resolved before the Finance Core / AP module is built).
