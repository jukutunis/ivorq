# ADR-002: Audit Trail Strategy

## ADR Metadata
* **ADR Number:** ADR-002
* **ADR Title:** Audit Trail Strategy
* **Status:** Active
* **Date:** 2026-06-18
* **Authors:** Enterprise Security Architect, CTO, Audit & Compliance Architect
* **Related ADRs:** ADR-001 (Architecture Principles)

## Context
The IVORQ platform is a multi-tenant enterprise hospitality SaaS application managing critical operational, financial, and inventory data across multiple properties and tenants. Currently, the platform contains 232 models, but only 19 models utilize `LogsActivity`, resulting in approximately 8.1% audit coverage. 

A formal Audit Trail Strategy is required to address this critical gap before expanding audit logging across domains. 
* **Regulatory Compliance:** Hospitality operations process sensitive financial, inventory, and guest-related data, requiring strict traceability to meet compliance standards (e.g., SOC 2, PCI-DSS, GDPR).
* **Enterprise Customer Requirements:** Enterprise clients mandate an immutable history of operations to ensure trust, verify data integrity, and support internal and external audits.
* **Multi-Tenant Accountability:** In a multi-tenant environment, identifying the exact user, property, and tenant responsible for any system action is non-negotiable to prevent data bleed and assign strict accountability.
* **Forensics and Troubleshooting:** Granular audit trails are essential for incident response, security forensics, and operational troubleshooting.

## Decision
The official IVORQ Audit Trail Strategy mandates comprehensive, immutable, and tenant-isolated audit logging across all critical business entities and system actions using the standardized `spatie/laravel-activitylog` implementation, augmented with multi-tenant and property-specific context. All system modifications, access control changes, and critical business workflows must generate a structured audit event.

## Audit Objectives
* **Accountability:** Unambiguously map every system action to a specific authenticated user, property, and tenant.
* **Compliance:** Ensure all data modifications meet enterprise compliance standards for auditability.
* **Forensics:** Provide detailed, tamper-evident logs for security incident investigations.
* **Operational Transparency:** Enable managers and administrators to view the history of changes to critical records (e.g., BEOs, Purchase Orders).
* **Change Tracking:** Maintain a complete history of "before" and "after" state changes for critical entities.

## Audit Event Categories
The following categories of events MUST be audited:
* **Authentication:** Logins, logouts, failed login attempts, password resets, MFA events.
* **Authorization:** Role assignments, permission changes, property access grants.
* **Security Events:** Blocked requests, rate limit violations, unauthorized access attempts.
* **User Management:** User creation, updates, suspensions, deletions.
* **Approval Workflows:** Request submissions, approvals, rejections, escalations.
* **Inventory Operations:** Stock counts, adjustments, transfers, par level changes.
* **Purchasing Operations:** Purchase requests, purchase orders, receiving events, vendor modifications.
* **Financial Operations:** Payment vouchers, budget adjustments, invoice approvals.
* **Sales & Event Management:** BEO creations, status changes, distribution events.
* **Configuration Changes:** System settings, property configuration, workflow rules.
* **System Administration:** Feature flag toggles, maintenance mode triggers.

## Audit Entity Classification
Models within the IVORQ platform are classified into three audit tiers:

* **Mandatory:** Core business, financial, security, and access control entities. Must log all CRUD operations, state changes, and workflow actions.
* **Recommended:** Supporting operational entities (e.g., tags, categories). Should log Create, Update, Delete actions.
* **Optional:** Ephemeral data, high-volume telemetry, or non-critical lookup tables. Auditing is disabled to conserve database resources unless specifically required for debugging.

*Rationale:* Logging every action on every model degrades performance and bloats storage. Classification ensures we capture what matters without overwhelming the database.

## Mandatory Audit Entity Matrix
The following entities are classified as **Mandatory** and must implement the audit trait:

* **Security & Access:** `User`, `Role`, `Permission`
* **Organization:** `Tenant`, `Property`, `Department`
* **Purchasing:** `Vendor`, `PurchaseRequest`, `PurchaseOrder`, `Receiving`, `PaymentVoucher`
* **Inventory:** `StockCount`, `InventoryAdjustment`, `Item`, `Warehouse`
* **Sales & Events:** `BEO`, `Event`, `Forecast`, `Budget`
* **Workflows:** `ApprovalRequest`, `DistributionEscalation`
* **Operations:** `GuestRequest`, `EngineeringWorkOrder`, `ShiftHandover` (current implementation name: `ShiftLog`), `Operational Log Entry` (current implementation name: `LogbookEntry`), `Operational Log Entry Follow-up Resolution` (current implementation name: `LogbookEntryFollowUpResolution`)

*Note: Future correction/clarification and roster acknowledgement records require Mandatory Audit alignment when they are introduced. Runtime audit instrumentation remains a separately approved implementation concern.*


## Mandatory Audit Actions
For each Mandatory entity, the following actions MUST be explicitly audited when they occur:
* `Create`
* `Update` (Must include state differentials - "before" and "after")
* `Delete` (Soft and Hard deletes)
* `Approve`
* `Reject`
* `Cancel`
* `Override`
* `Status Change`
* `Assignment Change`
* `Ownership Change`

## Multi-Tenant Requirements
* **Tenant Boundaries:** Every audit record MUST be hard-linked to a `tenant_id`.
* **Property Boundaries:** Every audit record MUST be hard-linked to a `property_id` (where applicable).
* **Cross-Tenant Protection:** Audit queries must always apply global tenant and property scopes. It is physically impossible for a user in Tenant A to view an audit log from Tenant B.
* **Audit Visibility Rules:** Property-level users can only view logs for their assigned property. Enterprise/Tenant-level users can view logs across all properties within their tenant.

## Retention Policy
* **Audit Retention Period:** Active audit logs must remain in the primary database for 12 months.
* **Archiving Policy:** Logs older than 12 months are to be archived to cold storage (e.g., AWS S3 / Glacier) in a queryable format (e.g., Parquet) for an additional 6 years.
* **Export Policy:** Tenants can export their audit logs in CSV/JSON format for internal compliance reporting.
* **Investigation Support:** Archived logs must be retrievable within 24 hours in the event of a forensic investigation.

## Security Requirements
* **Tamper Resistance:** Audit log tables must be append-only. Application-level safeguards must prevent the modification of existing audit records.
* **Access Control:** Read access to audit logs is restricted to authorized roles (e.g., System Admin, Property Manager, Auditor) via Spatie Permissions.
* **Audit Integrity:** Critical financial and security audit records may require cryptographic hashing in the future to prove non-repudiation.
* **Separation of Duties:** Administrators who can perform critical actions should not have the ability to purge or modify the audit logs detailing those actions.
* **Read-Only Audit Access:** No API endpoint or UI interface shall exist that allows the editing of an audit log entry.

## Performance Requirements
* **Expected Growth:** The system anticipates generating millions of audit records per month across the multi-tenant architecture.
* **Archiving Strategies:** Implementation of a scheduled job to partition and offload historical audit data to prevent primary database degradation.
* **Future Scalability:** As volume scales, the audit storage backend may be migrated from the primary PostgreSQL database to a dedicated datastore (e.g., Elasticsearch, ClickHouse, or a separate PostgreSQL instance optimized for append-only time-series data).

## Integration Requirements
* **Spatie Activitylog:** Leverage `spatie/laravel-activitylog` as the core implementation engine.
* **Approval Engine:** Integration to capture the full chain of custody for any approval request.
* **Notification Engine:** Trigger alerts for specific high-risk audit events (e.g., assignment of a Super Admin role).
* **Workflow Engine:** Audit logs must track the progression of entities through complex state machines.
* **Future SIEM Integration:** Architecture must support eventual log forwarding via webhooks or log shippers (e.g., Fluentd, Logstash) to enterprise SIEM platforms.

## Anti-Patterns
The following practices are explicitly prohibited:
* **Deleting Audit Records:** No application code may execute `delete()` or `truncate()` on the activity log tables.
* **Disabling Audit Logging:** Bypassing the logging mechanism (e.g., `Activity::disableLogging()`) for mandatory entities is forbidden.
* **Bypassing Approval Audit Events:** Updating an approval status directly via SQL or without triggering the corresponding business logic and audit event.
* **Cross-Tenant Audit Visibility:** Queries that fail to apply `tenant_id` scopes when retrieving logs.
* **Silent Data Modifications:** Performing bulk updates or database migrations that alter critical business data without generating an associated administrative audit trail.

## Consequences
* **Positive Consequences:** Achieves enterprise-grade compliance, provides irrefutable traceability, enables confident multi-tenant scaling, and significantly reduces the time required for incident response.
* **Negative Consequences:** Increased database storage costs, potential performance overhead during high-volume transactions, and increased developer friction (ensuring all state changes are properly logged).
* **Tradeoffs:** Choosing synchronous database logging for simplicity and consistency over asynchronous queue-based logging, trading a minor performance hit for guaranteed log integrity. This will be re-evaluated as scale demands.

## Future Expansion
The Audit Trail Strategy is designed to support the onboarding of future IVORQ modules:
* **PMS (Property Management System):** Auditing guest folio changes, reservations, and room moves.
* **HRIS (Human Resources Information System):** Auditing salary changes, disciplinary actions, and employee onboarding.
* **Accounting:** Auditing ledger entries, journal adjustments, and reconciliation events.
* **Revenue Management:** Auditing rate parity changes and dynamic pricing adjustments.
* **CRM:** Auditing guest profile merges, privacy (GDPR) deletion requests, and preference updates.
