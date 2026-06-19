# ADR-001: Multi-Tenant Hierarchy Architecture

## ADR Metadata
* **ADR Number:** ADR-001
* **ADR Title:** Multi-Tenant Hierarchy Architecture
* **Status:** Active
* **Date:** 2026-06-18
* **Authors:** CTO, Enterprise SaaS Architect, Multi-Tenant Architecture Specialist, Security Architect, Hospitality Platform Architect
* **Related ADRs:** ADR-002 (Audit Trail Strategy), ADR-003 (Approval Engine Architecture)

## Context
IVORQ is designed as an Enterprise Hospitality Operations Platform targeting parity with industry benchmarks such as Oracle Opera Cloud and VHP Cloud. The roadmap extends beyond basic operations to encompass Project Management, Property Management Systems (PMS), and Human Resources Information Systems (HRIS).

To support this enterprise scale, IVORQ requires a robust, secure, and scalable multi-tenant architecture. Hospitality groups (the customers) operate multiple distinct assets (hotels, resorts, villas), requiring strict data and operational isolation between these assets, while simultaneously demanding consolidated reporting, global governance, and centralized billing at the corporate level. 

A flat, single-tier tenancy model cannot accommodate this complexity. If the foundational tenancy architecture is flawed, the subsequent integration of complex, highly-regulated modules like PMS (handling guest PII and PCI data) and HRIS (handling employee data and payroll) will inevitably fail due to security breaches, data bleeding, or architectural collapse.

## Decision
We define and mandate a strict three-tier **Multi-Tenant Hierarchy** for the IVORQ platform: **Enterprise → Tenant → Property**. This hierarchical model is the authoritative source of truth governing all database design, authorization boundaries, module architecture, and security policies.

## Organizational Model
* **Enterprise (IVORQ Platform):** The root level. Represents the IVORQ SaaS infrastructure and application layer. Owned and operated by the IVORQ vendor. Responsible for platform administration, tenant provisioning, system configurations, and global monitoring.
* **Tenant (Customer Organization):** The client or management company (e.g., "Marriott International" or "Bali Villa Management"). A Tenant is the primary billing and legal boundary. A Tenant owns multiple Properties. 
* **Property (Hospitality Asset):** An individual operational unit (e.g., "The Ritz-Carlton Bali" or "Villa Sunset"). A Property is strictly owned by exactly one Tenant. It represents the physical bounds of operations, inventory, local staff, and guest interactions.

## Cloud Name Strategy
* **Cloud Name:** The unique identifier for a Tenant within the IVORQ ecosystem (e.g., `marriott`).
* **Tenant Identity:** The Cloud Name physically maps to the `Tenant` entity and acts as the foundational key for multi-tenant routing.
* **Uniqueness Requirements:** Cloud Names must be globally unique across the entire IVORQ Enterprise platform.
* **Tenant Discovery:** The Cloud Name is utilized during the initial connection phase to resolve the tenant context (e.g., via subdomains `marriott.ivorq.com` or header-based routing).
* **Authentication Entry Point:** Users authenticate against a specific Cloud Name. A user account is scoped to the Tenant, preventing cross-tenant login bleeding.

## Tenant Isolation Principles
* **Logical Isolation:** All Tenant data resides in a shared database but is logically partitioned using a non-nullable `tenant_id` foreign key on all tenant-owned models.
* **Data Isolation:** Global query scopes MUST automatically append `where tenant_id = X` to all database interactions.
* **Security Isolation:** A user from Tenant A cannot physically authenticate against, authorize into, or query data belonging to Tenant B.
* **Operational Isolation:** Workflows, configuration rules, and business logic customizations applied to Tenant A have zero impact on Tenant B.
* **Reporting Isolation:** Tenant-level reports aggregate data strictly from properties owned by that specific Tenant.

## Property Isolation Principles
* **Property Ownership:** All operational data (inventory, orders, BEOs) MUST belong to a specific `property_id`, which in turn belongs to a `tenant_id`.
* **Property Scope:** Employees primarily operate within the scope of a single Property.
* **Property Visibility:** A user assigned to Property X cannot view, edit, or interact with data in Property Y, unless explicitly granted cross-property roles by a Tenant Administrator.
* **Property Boundaries:** Financial ledgers, inventory counts, and operational workflows (e.g., Engineering work orders) are strictly bounded to the Property.
* **Cross-Property Restrictions:** Data cannot leak between properties by default.

## Authorization Model Integration
* **Roles:** Spatie Roles are defined at the Tenant level.
* **Permissions:** Standardized at the Enterprise level, but assigned to Roles at the Tenant level.
* **Policies:** Laravel Policies must always verify `tenant_id` alignment before evaluating `property_id` alignment.
* **Spatie Permissions:** Roles must be assigned to users with a `property_id` context (or `tenant_id` context for corporate users), ensuring granular access control.
* **Approval Engine (ADR-003):** Approval chains route dynamically based on the request's `property_id` and the approver's property-scoped role.
* **Audit Trail (ADR-002):** Every action logged must explicitly capture the `tenant_id` and `property_id`.

## Audit Visibility Rules
* **Enterprise Visibility:** IVORQ Super Admins cannot view Tenant operational audit logs unless granted temporary support access.
* **Tenant Visibility:** Corporate Auditors can view all audit logs across all Properties within their Tenant.
* **Property Visibility:** A Property Manager can view audit logs specifically bounded to their assigned Property.
* **Cross-Tenant Restrictions:** Audit logs are strictly isolated; querying logs without a `tenant_id` scope is prohibited.

## Approval Visibility Rules
* **Who can approve:** Users with the required Role, explicitly assigned to the target `property_id`.
* **Who can see approvals:** Approvers see pending requests for their assigned properties. Requester sees the status of their own requests.
* **Cross-property approvals:** A Regional Manager role can be assigned across multiple properties, allowing them to approve CAPEX requests for Property X and Property Y.
* **Cross-tenant restrictions:** Approvals can never cross Tenant lines.

## Data Ownership Model
* **Tenant Owned:** Users, Roles, Global Settings, Master Vendor Lists, Chart of Accounts, Global Item Catalogs.
* **Property Owned:** Departments, Inventory, Warehouses, Purchase Orders, BEOs, Guest Requests, Engineering Work Orders, Local Forecasts, Budgets.
* **Future PMS/HRIS:** Guest Profiles (Tenant Owned), Reservations (Property Owned), Employee Files (Tenant Owned), Payroll (Property Owned).

## Security Requirements
* **Tenant Boundaries:** Enforced by Laravel Global Scopes and middleware on every HTTP request and queued job.
* **Property Boundaries:** Enforced by Authorization Policies evaluating the user's `property_id` context.
* **Session Boundaries:** A user session is explicitly locked to a specific Tenant upon authentication.
* **Authentication Boundaries:** User credentials must be verified against the Tenant context resolved via the Cloud Name.
* **Authorization Boundaries:** Role checks must include the scope of the current Property context.

## Reporting Boundaries
* **Property Reports:** Scoped to `property_id`. E.g., Daily Revenue for Villa Sunset.
* **Tenant Reports:** Scoped to `tenant_id`, aggregating all properties. E.g., Total Enterprise Revenue for Marriott.
* **Enterprise Reports:** Anonymized, aggregated telemetry data for IVORQ platform health.
* **Cross-property reporting:** Authorized for users with Tenant-level or multi-property roles.
* **Consolidated reporting:** Must handle currency and timezone normalizations across properties.

## Shared vs Isolated Data
* **Enterprise Data (Shared):** Global currencies, countries, languages, application subscription plans.
* **Tenant Data (Isolated):** Customer configuration, global roles, user accounts.
* **Property Data (Isolated):** Operational transactions, local inventory, local ledgers.
* **Reference Data (Shared):** Immutable lookup tables (e.g., Standard Unit of Measures) maintained by Enterprise.
* **System Data (Shared):** Platform audit logs, background job metrics.

## Anti-Patterns
The following practices are explicitly prohibited:
* **Cross-tenant queries:** Writing queries that span multiple `tenant_id`s.
* **Tenant bypassing:** Using `withoutGlobalScope('tenant')` in application code (except for dedicated platform administrative commands).
* **Property bypassing:** Saving operational records without a `property_id` or evaluating permissions without property context.
* **Shared tenant data:** Attempting to create "global" user accounts that exist outside a Tenant (excluding IVORQ System Admins).
* **Improper visibility escalation:** Allowing a Property-level user to view Tenant-wide aggregate reports.
* **Global data access without authorization:** Assuming authentication equates to platform-wide authorization.

## Consequences
* **Positive Consequences:** Guarantees data security, aligns with enterprise corporate structures, simplifies compliance (SOC 2, GDPR), and creates a solid foundation for complex PMS and HRIS expansions.
* **Negative Consequences:** Increased development complexity; developers must always consider the tenant and property context. Accidental omissions of `tenant_id` in queries can lead to catastrophic data leaks if global scopes are improperly configured.
* **Tradeoffs:** Choosing logical isolation (shared database) over physical isolation (database-per-tenant) to reduce infrastructure costs and simplify migrations, trading off absolute physical data separation for manageable operational overhead.

## Future Expansion
This hierarchy architecture directly supports future integrations:
* **PMS:** Allows seamless cross-property guest profile recognition at the Tenant level while keeping reservations isolated at the Property level.
* **HRIS:** Allows corporate-level employee management while distributing payroll and scheduling to the Property level.
* **Accounting:** Enables consolidated multi-property financial roll-ups at the Tenant level.
* **Revenue Management:** Facilitates cluster-based yield management strategies.
* **CRM:** Centralizes loyalty programs across the entire Tenant portfolio.
* **Business Intelligence:** Provides structured data hierarchies for advanced data warehousing.
