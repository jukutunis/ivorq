# WP005 Company → Tenant Alignment Assessment

## 1. Executive Summary
Operating in ARCHITECTURE REVIEW MODE, an exhaustive codebase assessment was conducted to verify the alignment of the IVORQ repository with the approved Enterprise → Tenant → Property hierarchy. The audit confirms that the system maintains a robust, structurally sound multi-tenant boundary. However, there is a pronounced Terminology Drift: the repository universally implements the concept of a "Tenant" (Cloud Name) using the identifier `company_id` and the `Company` entity. Furthermore, the Vendor ownership model exhibits domain ambiguity, attempting to support both Property-specific and Tenant-wide scenarios simultaneously, creating potential data leakage risks.

## 2. Current Tenancy Model
- **Architectural Construct:** Tenant (Cloud Name)
- **Physical Implementation:** `Company` model (`companies` table).
- **Identifier:** `company_id`
- **Status:** The system uniformly relies on `company_id` to establish the uppermost tenant boundary. A search across the entire repository confirmed absolute zero usage of `tenant_id`, `organization_id`, `customer_id`, or `group_id`. All data segregation logically branches from the `Company`.

## 3. Current Property Model
- **Architectural Construct:** Property (Operational Unit)
- **Physical Implementation:** `Property` model (`properties` table).
- **Identifier:** `property_id`
- **Status:** Firmly established. The `Property` inherently belongs to a `Company`. Almost all operational domain models (e.g., Inventory, Purchasing, Sales & Events) successfully map down to `property_id`.

## 4. Terminology Matrix
| Approved Architecture | Repository Implementation | Alignment Status |
| :--- | :--- | :--- |
| Enterprise (IVORQ Platform) | Foundation / Platform | Aligned |
| Tenant (Cloud Name) | `Company` / `company_id` | **Drift** (Conceptual only) |
| Property (Operational Unit) | `Property` / `property_id` | Aligned |

## 5. Architecture Drift Findings
### Finding 1: Terminology Misalignment (Tenant vs Company)
- **Domain:** Foundation
- **Description:** The approved architecture mandates the term "Tenant". The codebase uses "Company". While this does not compromise security, it creates cognitive load and architectural friction during documentation and scaling. 

### Finding 2: Ambiguous Vendor Ownership (FND-004)
- **Domain:** Purchasing (`vendors` table)
- **Description:** The migration `2026_06_10_000002_create_vendors_table` contains both `property_id` (nullable) and `company_id` (nullable) with explicit comments stating: *"Might be bound to Company instead of Property"*. 
- **Conflict:** A unique constraint exists for `['property_id', 'vendor_code']`. If a Vendor is elevated to the Company level (`property_id` = null), this unique constraint logic breaks, leading to duplicate vendor codes at the Tenant level. This represents a critical ambiguity in the ownership boundary.

### Finding 3: Authorization Guard Complexity
- **Domain:** Sales & Event Management (`OpportunityGovernanceGuard`, `TemplateGovernanceGuard`)
- **Description:** Guards manually check both `company_id` and `property_id` in sequence. Because the global contexts do not automatically enforce Tenant-level resolution via a cohesive Tenant Scope, services manually patch the boundary: e.g., `if ($opportunity->company_id !== $userCompanyId || $opportunity->property_id !== $userPropertyId)`.

## 6. Risk Assessment
- **FND-004 Vendor Leakage Risk:** HIGH. If a user attempts to create a Company-wide vendor, the system lacks centralized rules to prevent property-level vendor codes from colliding. This compromises Accounts Payable and General Ledger accuracy.
- **Migration Risk for Renaming `company_id` to `tenant_id`:** HIGH. `company_id` is deeply entrenched across 50+ models, migrations, and services. A physical database rename would require massive, disruptive migrations and downtime.

## 7. Recommended Alignment Strategy
1. **Do NOT physically rename `company_id` to `tenant_id` in the database.** The implementation complexity and risk of regression vastly outweigh the semantic benefits.
2. **Formalize `Company` as the technical binding for `Tenant`.** Update ADR-001 to explicitly state: "The architectural concept of a Tenant is physically implemented as Company (`company_id`) within the repository."
3. **Resolve Vendor Ambiguity (Purchasing Domain):** 
   - Decide definitively if Vendors are Tenant-owned or Property-owned.
   - If Tenant-owned: Deprecate `vendors.property_id` and enforce unique `['company_id', 'vendor_code']`.
   - If Property-owned: Deprecate `vendors.company_id` and maintain strict operational siloing.

## 8. Implementation Complexity
- **Renaming `company_id` -> `tenant_id`:** Extreme (Requires rewriting 80% of Foundation, Sales, and Purchasing schemas).
- **Vendor Boundary Fix:** Moderate (Requires schema update, code refactoring in Purchasing Repository, and data migration for existing vendors).

## 9. Priority Classification
- **Vendor Boundary Ambiguity:** **P1 High** (Requires immediate resolution before multi-property financial ledgers are fully activated).
- **Tenant/Company Terminology Sync:** **P3 Low** (Governance documentation update only).
