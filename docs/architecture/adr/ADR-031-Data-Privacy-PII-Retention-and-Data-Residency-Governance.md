# ADR-031: Data Privacy, PII, Retention and Data Residency Governance

## Status
Proposed

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform operating under the hierarchy: Enterprise → Tenant → Property. As IVORQ progressively processes data related to employees, users, vendors, purchasing, finance, guest profiles, reservations, city ledger, and payment references, a robust architectural foundation for data privacy is required. Financial and audit records form a core enterprise foundation and cannot be casually deleted merely to satisfy a data removal request. Future legal and privacy requirements will differ by country, jurisdiction, contractual obligation, tenant policy, and record type. Identity, authentication, and session revocation are already governed by ADR-030.

## Decision Drivers
- **Multi-Tenant Isolation:** The architectural boundary between Tenants (Cloud Names) must be impenetrable.
- **Regulatory Flexibility:** IVORQ must provide boundaries capable of supporting diverse, jurisdiction-specific privacy obligations without being hardcoded to a single law.
- **Financial & Audit Integrity:** Privacy deletion requests must never break the immutability of financial ledgers or audit logs.
- **Data Residency & Sovereignty:** Tenants require guarantees regarding the physical location and cross-border transfer of their data.

## Scope
This ADR defines the enterprise-wide governance for privacy, Personal Identifiable Information (PII), retention, archival, deletion, legal hold, data residency, cross-border data handling, payment-data boundaries, and privacy-aware auditing.

## Non-Goals
- This ADR does not claim that IVORQ is automatically compliant with any specific law, country regulation, certification, or framework (e.g., GDPR, PCI DSS, SOC 2, ISO 27001, PDPA).
- This ADR does not define specific database columns, tables, APIs, migrations, encryption algorithms, or vendor products.
- This ADR does not prescribe a particular consent-management product.

## Decision

### 1. Data Classification Model
To govern data handling uniformly, IVORQ adopts the following enterprise data classification model:

**Default Classification Rule:** Any data not formally classified must be handled as at least `Confidential` until a documented classification decision is made. Data may be classified as `Public` only after documented business-owner approval for intentional external publication. Public classification must not be inferred merely because data is visible to an authenticated user, included in an export, or present in a support tool.

| Classification | Typical Examples | Minimum Access Expectation | Logging / Masking Expectation | Sharing Expectation |
| :--- | :--- | :--- | :--- | :--- |
| **Public** | Marketing materials, public hotel descriptions. | Unrestricted. | Standard access logs; no masking. | Unrestricted sharing. |
| **Internal** | Standard operating procedures, non-sensitive internal memos. | Authenticated users with basic scope. | Audited access; minimal masking. | Internal to Tenant only. |
| **Confidential** | Vendor contracts, aggregate financial reports, wholesale pricing. | Authorized roles via ADR-029. | Audited access; contextual masking. | Governed sharing via explicit approval. |
| **Restricted** | Sensitive PII, financial-sensitive data, government ID, payment tokens, security artifacts. | Need-to-know + Authorized roles. | Immutable audit log; aggressive masking. | Strictly governed; limited external sharing. |

### 2. PII and Sensitive Data Categories
The architecture recognizes the following categories of sensitive data:
- User and employee identity data.
- Guest identity and contact data.
- Guest-stay and reservation-related data.
- Vendor and business-contact data.
- Financial and tax-related personal data.
- Security, authentication, and access metadata.
- Government identity documents where lawfully collected.
- Payment-related data.

**Payment Data Boundary:** IVORQ general application domains must not store raw payment card PAN, CVV/CVC or equivalent card-verification data, raw bank credentials, processor secrets, or equivalent highly sensitive payment secrets. CVV/CVC or equivalent card-verification data is permanently prohibited from persistence in IVORQ, including in any future dedicated payment-scope architecture. IVORQ may store only payment tokens, masked identifiers, processor-generated references, payment status and reconciliation references, and other minimum metadata required for approved payment, finance, audit, or reconciliation purposes. A future dedicated Payment Architecture ADR may define tokenization boundaries, payment processor integration, isolated handling of any payment-sensitive scope, and payment-data access and audit controls. A future Payment Architecture ADR must not weaken the permanent prohibition on CVV/CVC storage.

### 3. Data Minimization and Purpose Boundaries
- Data collection must be limited to a documented operational, contractual, financial, security, or legal-retention purpose.
- Optional fields must not become mandatory without documented justification.
- Data must not be repurposed across Tenant, Property, or unrelated business purposes without explicit governance.
- Analytics, reporting, training, AI, product improvement, and external sharing require separately governed purposes.
- A Tenant’s data must **never** become another Tenant’s training, reporting, or operational data by default.

### 4. Tenant Isolation and Data Residency
- The **Tenant** (Cloud Name) is the primary data residency and data-governance boundary.
- **Property** is an operational scope but does not override Tenant-level residency policy.
- Tenant data residency preferences, contractual commitments, or jurisdictional obligations must be configurable and documented.
- Cross-border data transfer must be explicitly governed, logged where appropriate, and constrained by tenant policy and applicable obligations.
- Cross-tenant data access, reporting, analytics, exports, backups, or search indexing is prohibited unless explicitly authorized by Enterprise-level governance and tenant agreements.
- Data residency is not satisfied merely by UI locale or user location.

### 5. Access, Masking, and Need-to-Know
- Access requires authorization and scope under ADR-029.
- Sensitive data access requires a **need-to-know** principle in addition to role permission.
- Masking and redaction rules must apply to UI, exports, logs, notifications, support tooling, and analytics outputs.
- Elevated access to Restricted data must generate immutable audit evidence.
- Support, implementation, and platform-administration roles must not receive blanket access to Tenant PII. Access to Tenant Confidential or Restricted data by these roles must be:
  - purpose-bound;
  - time-limited;
  - explicitly approved through tenant/platform governance where applicable;
  - protected by MFA;
  - immutable-audit logged;
  - revoked when the approved support purpose ends.
- Break-glass privacy access must follow ADR-030 and must never bypass auditability. (Note: Controlled support access is distinct from, and does not weaken, the emergency break-glass rules in ADR-030).

**Masking Examples**
| Data Field | Example Masking Pattern |
| :--- | :--- |
| Email Address | `j***@example.com` |
| Phone Number | `+1 (***) ***-1234` |
| Government ID / Passport | `*****6789` |
| Bank Account Reference | `********1234` |
| Payment Token / Reference | `tok_***abc` or `**** **** **** 1234` |
| Guest Folio / Financial-Sensitive | Context-dependent redaction of itemized details in external views. |

### 6. Retention, Archival, Deletion, and Anonymization
Data lifecycle stages are distinct: Operational retention, Financial retention, Audit retention, Security retention, Archival, Deletion, Anonymization, Pseudonymization, and Legal hold.

**Retention Decision Hierarchy**
| Precedence | Rule |
| :--- | :--- |
| 1. Legal Hold | Overrides all ordinary deletion, archival, or retention-expiry processes. |
| 2. Financial/Audit Obligation | Records with financial, legal, audit, fraud, security, or contractual obligations cannot be hard-deleted. |
| 3. Tenant/Jurisdiction Policy | Drives configurable retention schedules. |
| 4. Operational Deletion | Authorized manual or automated removal when obligations allow. |

- Retention schedules are policy-driven, tenant-aware, jurisdiction-aware, and record-type-aware.
- IVORQ must not hard-delete records where financial, legal, audit, fraud, security, or contractual obligations require preservation.
- Legal hold overrides ordinary deletion, archival, or retention-expiry processes.
- Deletion requests must be evaluated against retention obligations rather than automatically executed.
- When removal is permitted, anonymization or pseudonymization may be preferred over destructive deletion where referential integrity, aggregate reporting, or audit attribution must remain intact.
- Archived data must not remain generally searchable or operationally editable.
- Backups and replicas require governed deletion/expiry handling, without falsely claiming immediate erasure from immutable backup media.

### 7. Data Subject and Tenant Requests
Future workflows for Access/export, Correction, Restriction, Deletion/anonymization, Consent/preference, Tenant data export, and Tenant offboarding requests are bounded by:
- Identity verification and authorization are mandatory before fulfilling requests.
- They require workflow, audit, and legal/tenant-policy review.
- They must not bypass finance, audit, fraud, security, or legal-hold retention obligations.
- They are not an automatic promise of statutory compliance.

### 8. Audit, Monitoring, and Secret-Handling
Privacy-aware auditing aligns with ADR-002 and ADR-030.

**Privacy-Sensitive Audit Events**
| Event Type | Action Required |
| :--- | :--- |
| Elevated Access | Log access to Restricted data without recording the payload. |
| Data Export | Log bulk exports, destination, and user context. |
| Policy Change | Log data residency, retention, or legal hold changes. |
| Incident / Breach | Log high-risk disclosures. |

- Audit logs must record meaningful privacy-sensitive actions without storing the sensitive payload itself.
- **CRITICAL:** Logs must **not** contain passwords, raw tokens, API secrets, recovery codes, raw PAN, CVV, full government ID values, or unmasked high-risk payloads.
- Access to Restricted data, bulk export, data residency change, retention-policy change, legal hold creation/release, and high-risk disclosure must produce immutable audit events.
- Audit records are themselves governed data and must be protected from unrestricted access.
- Observability, error monitoring, and support diagnostics must apply redaction and data minimization.

### 9. Integrations, Service Providers, and Data Sharing
- Integration identities are governed by ADR-030.
- External integrations may receive only the minimum data required for the documented purpose.
- Sharing of Restricted data requires explicit contractual, tenant, security, and governance approval boundaries.
- Integration termination must revoke access and prevent further data exchange.
- Data processor/subprocessor assessment, contractual terms, and jurisdiction constraints are future operating controls, not automatically solved by technology.
- Webhooks, exports, and API responses must obey data classification and masking rules.

### 10. Privacy Incident and Breach Governance Boundary
- The architecture must support detection, containment, evidence preservation, access revocation, and audit review.
- Incident classification, notification timelines, regulator communication, and affected-person communication are jurisdiction- and legal-policy-dependent.
- The system must provide accurate evidence without exposing additional PII.
- No automatic legal notification claim may be made by this ADR.

### 11. AI, Analytics, Reporting, and Secondary Use
- Restricted and Confidential data may not be used for AI training, product analytics, cross-tenant benchmarking, or external reporting by default.
- Any future AI, analytics, BI, or data-warehouse usage must define data minimization, aggregation, anonymization/pseudonymization, tenant isolation, retention, and approval boundaries.
- Tenant-opt-in, contract, policy, and legal review may be required before secondary use.
- This ADR does not define the future AI architecture; it establishes privacy conditions that future ADRs must honor.

### 12. Failure Modes and Safe Degradation
- **Data Residency Unknown:** When residency policy cannot be resolved, IVORQ must fail closed for residency-bound Restricted data processing, new cross-border transfer, export, replication, and external sharing. The system may preserve the minimum necessary security, audit, and incident evidence required to investigate the unresolved condition. The system must not use the failure state as permission to route data to a default foreign region or bypass tenant policy. Resolution requires policy restoration or authorized governance review before blocked processing resumes.
- **Masking Service Fails:** Fail-closed (deny display or export of unmasked data).
- **Legal Hold Status Unknown:** Fail-safe (prevent deletion).
- **Export Job Fails Mid-Process:** Terminate securely, clean up partial data, and audit the failure.
- **Unexpected Restricted Data from Integration:** The payload must be quarantined. Downstream processing, disclosure, search indexing, analytics use, and ordinary retries must be blocked until classification and approval are resolved. The system must record only minimum safe metadata and audit evidence; it must not expose the restricted payload in logs. The system must not silently drop a potentially material financial, operational, or contractual event. Resolution must be through a governed exception workflow, approved replay, safe rejection to the sender, or documented retention/disposal decision according to policy. Any reprocessing must preserve idempotency and auditability.
- **Backup Deletion Unavailability:** Log the retention expiration and queue for deletion when media rotates.
- **Identity Verification Fails:** Deny privacy requests.

## Alternatives Considered
- **Applying immediate physical deletion to all requests:** Rejected because it violates immutable financial and audit obligations, which supersede generic "right to be forgotten" requests in enterprise systems.

## Consequences

### Positive Consequences
- Establishes a framework where legal and tax reviews can configure policies rather than rewriting source code.
- Protects financial ledgers from catastrophic data corruption due to poorly scoped privacy requests.

### Trade-Offs and Risks
- Implementing dynamic masking and strict retention hierarchies increases system complexity and storage overhead.

### Operational Requirements
- Legal obligations, retention periods, residency obligations, notification timelines, and request-handling requirements must be configurable and subject to competent legal/tax/privacy review.

## Dependencies and Related ADRs
- ADR-001 — Multi-Tenant Hierarchy
- ADR-002 — Audit Trail Strategy
- ADR-004 — Finance Module Boundary
- ADR-015 — Landed Cost and Tax Apportionment Strategy
- ADR-025 — Revenue Recognition and Tax Engine
- ADR-028 — Accounts Receivable and City Ledger Strategy
- ADR-029 — Security, Roles and Permissions Governance
- ADR-030 — Identity, Authentication and Session Governance

## Deferred Decisions
- The specific mapping of these policies to GDPR, CCPA, PDPA, or other regional regulations is deferred to future legal/policy review.
- Implementation of cross-border and multi-jurisdiction rollout controls is deferred until enterprise expansion requires it.
- Specific data mapping is required when PMS/guest-data modules begin but is currently deferred.

## Open Questions Requiring CTO Approval
- None at this time.

## Validation Criteria
- Verification of identity before processing data subject requests.
- Audit trails never expose raw sensitive payloads or secrets.
- Deletions respect legal hold and financial retention hierarchies.

## References
- Internal: IVORQ ADR Master Structure Review
