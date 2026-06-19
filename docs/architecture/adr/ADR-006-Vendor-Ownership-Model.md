# ADR-006: Vendor Ownership Model

## 1. Title
ADR-006: Vendor Ownership Model

## 2. Status
**Accepted**

## 3. Context
The IVORQ multi-tenant SaaS platform follows an established architectural hierarchy: **Enterprise → Tenant (Cloud Name / Company) → Property (Operational Unit)**. A recent Vendor Ownership Assessment (WP005 follow-up) revealed that the `vendors` table in the Purchasing module possessed ambiguous ownership schemas. Specifically, the schema permitted both `company_id` and `property_id` fields with unique constraints scoped only to the Property level. This ambiguity threatened to introduce massive data duplication and break multi-property centralization workflows required by modern hospitality ERP standards.

## 4. Problem Statement
Vendor ownership boundaries were not formally defined. Permitting vendors to be scoped at the Property level severely restricts Enterprise Procurement and Centralized Accounts Payable capabilities. A multi-property Tenant would be forced to create the same vendor multiple times for each property, fracturing spend visibility and complicating bulk invoice processing.

## 5. Decision Drivers
- **Centralized Accounts Payable (AP):** Must support paying a single vendor for invoices generated across multiple properties in a single payment run.
- **Enterprise Procurement:** Must support negotiating master vendor contracts and tracking aggregated spend volumes per vendor across the entire Tenant.
- **Data Integrity:** Must eliminate redundant data entry and maintain a "Single Source of Truth" for critical vendor data (Tax IDs, banking details).
- **Security & Authorization:** Must maintain clear operational boundaries while supporting centralized master data governance.

## 6. Considered Options
- **Option A: Property-Owned Vendor** 
  - *Description:* Vendors belong strictly to `property_id`.
  - *Drawback:* Massive data duplication; breaks enterprise AP and spend aggregation.
- **Option B: Tenant-Owned Vendor** 
  - *Description:* Vendors belong strictly to `company_id` (Tenant).
  - *Advantage:* Enables a unified vendor directory utilized by all underlying properties.
- **Option C: Hybrid Vendor Model** 
  - *Description:* Allows both Tenant-level and Property-level vendors simultaneously.
  - *Drawback:* Extreme authorization complexity; creates high risk of data duplication and reporting errors.

## 7. Decision
We have decided to adopt **Option B: Tenant-Owned Vendor** as the foundational Vendor Ownership Model.

### Ownership Boundary Rules:
- **Vendor Master Data:** **Tenant Owned** (Bound exclusively to `company_id`).
- **Operational Transactions:** **Property Owned** (Bound to `property_id`).

### Enforcement Hierarchy Examples:
- **Vendor** ↓ Company (Tenant)
- **Purchase Request** ↓ Property
- **Purchase Order** ↓ Property
- **Goods Receipt** ↓ Property
- **Vendor Invoice** ↓ Property
- **Accounts Payable** ↓ Company-level visibility / Property-level accountability

## 8. Consequences
### Benefits
- **Single Source of Truth:** A unified vendor directory prevents duplicate Tax IDs, duplicate banking information, and fragmented vendor profiles.
- **Enterprise Readiness:** Fully enables Enterprise Procurement and Centralized AP.
- **Scalability:** As a Tenant acquires new properties, the existing approved vendor list is immediately accessible to the new operational units.

### Trade-offs
- **Operational Scoping:** Cross-property queries require more sophisticated scoping. Operational users at Property A will inherently have visibility into vendors used by Property B.
- **Migration Effort:** Schema refactoring is required to eliminate `property_id` from the `vendors` table and migrate unique index constraints to the `company_id` level.

## 9. Implementation Principles
- The `vendors` table must drop the `property_id` column.
- Unique constraints (e.g., `vendor_code`) must be scoped strictly to `company_id`.
- Repositories fetching vendors for a specific property must query based on the active `property->company_id`.
- All downstream transactional documents (PR, PO, Receipts) must maintain their strict `property_id` ownership while referencing the Tenant-owned `vendor_id`.

## 10. Anti-Patterns
The following practices are explicitly **prohibited**:
- **Duplicate vendor masters per property:** Creating "Vendor X - Property A" and "Vendor X - Property B".
- **Cross-property vendor cloning:** Copying records between properties to circumvent tenant-level governance.
- **Hybrid ownership ambiguity:** Permitting the `property_id` column to remain on the `vendors` master table.
- **Property-level vendor master governance:** Allowing individual properties to dictate master contract terms independently of tenant oversight.

## 11. Future Considerations
- **Centralized Accounts Payable:** This architectural decision enables the upcoming Finance Core to process cross-property consolidated payment vouchers natively.
- **Enterprise Procurement:** Multi-property spend analytics and vendor contract management can now accurately aggregate volume discounts based on the unified `company_id` vendor record.
- **Future Finance Core Integration:** Accounts Payable and Vendor Invoice matching processes will rely on this structural clarity to perform 3-way matching across corporate and operational boundaries.

## 12. References
- ADR-001 Multi-Tenant Hierarchy
- ADR-004 Finance Module Boundary
- WP005 Company → Tenant Alignment Assessment
- IVORQ Vendor Ownership Assessment
