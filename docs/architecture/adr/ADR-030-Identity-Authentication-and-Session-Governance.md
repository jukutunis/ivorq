# ADR-030: Identity, Authentication and Session Governance

## Status
Proposed

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform organized around a core hierarchy: Enterprise → Tenant → Property. Each Tenant operates within an isolated "Cloud Name" domain. While authorization, segregation of duties, and role-based access control (RBAC) are already conceptually governed by ADR-029, the actual mechanisms of establishing *who* is attempting access (Authentication) and the continuous assurance of that identity (Session Lifecycle) remain undefined. Audit logging (ADR-002) and session revocation are Tier 1 mandatory security priorities for this platform. A strict architectural boundary is required to separate the proof of identity from the permissions granted to that identity.

## Decision Drivers
- **Multi-Tenant Isolation:** Cross-tenant authentication leakage or session crossover presents an existential threat to the platform.
- **Insider Threat Mitigation:** High staff turnover in hospitality necessitates absolute assurance of offboarding efficacy and immediate session termination.
- **Enterprise Readiness:** The platform must support future enterprise features like SSO and identity federation, requiring tenant-scoped authentication flows.
- **Immutable Auditability:** Any authentication event, emergency access, or integration credential usage must be unequivocally attributed and logged without exposing secrets.

## Scope
This ADR governs the enterprise-wide identity model, authentication mechanisms, Multi-Factor Authentication (MFA) policies, Single Sign-On (SSO) boundaries, session lifecycle (including strict revocation), service account governance, and break-glass emergency access protocols for IVORQ.

## Non-Goals
- This ADR does not redefine or supersede the Segregation of Duties (SoD) or Role-Based Access Control (RBAC) rules established in ADR-029.
- This ADR does not prescribe specific frontend or backend software packages, libraries, or vendors unless conceptually required by architecture.
- This ADR does not assume immediate implementation of PMS or HRIS modules.
- This ADR does not claim or guarantee automatic legal or regulatory compliance (e.g., ISO 27001, SOC 2, GDPR, PCI DSS). All policies represent design intent and architectural boundaries that support configurable legal compliance.

## Decision

### 1. Identity Model
Identity within IVORQ is strictly categorized. Identities are not interchangeable and must not share credentials or roles by default:
- **User Identity:** The base human entity interacting with the system.
- **Employee Identity:** A specialized human identity tied to tenant HR/staffing records (future HRIS).
- **Guest Identity:** A transient or persistent human identity tied to reservations or profiles (future PMS).
- **Service Account Identity:** A non-human identity explicitly provisioned for internal system processes.
- **Integration Identity:** A non-human identity explicitly provisioned for external third-party API access.
- **Emergency / Break-Glass Identity:** A highly monitored, ephemeral identity activated under strict protocols.

### 2. Tenant-Aware Authentication
Authentication must enforce strict tenant isolation.
- **Cloud Name / Tenant-Aware Flow:** The system must resolve the Tenant context (e.g., via sub-domain, header, or explicit UI payload) *before* attempting credential authentication.
- **Baseline Authentication:** Email and password remain the default authentication baseline.
- **Password Hashing:** Passwords must be hashed using industry-standard, computationally expensive algorithms (e.g., Argon2id or bcrypt) at the architecture layer. 
- **Account Recovery & Verification:** Email verification is mandatory for new provisioning. Password resets require secure, time-limited, single-use tokens sent via verified communication channels.
- **Lockout & Rate Limiting:** Account lockout and rate-limiting principles must be enforced against brute-force attacks at the authentication boundary.
- **Suspicious Login Handling:** Suspicious logins (e.g., impossible travel, unknown devices) must trigger configurable challenges or step-up authentication.

### 3. MFA Governance
Multi-Factor Authentication (MFA) is the primary defense against credential compromise.

**Mandatory MFA Roles**
The following highly privileged roles demand mandatory, non-bypassable MFA:
| Role | Justification |
| :--- | :--- |
| **Owner** | Ultimate administrative control over the Tenant. |
| **General Manager** | Highest operational authority across a Property. |
| **Finance** | Oversight of ledgers, subledgers, period closes, and reconciliations. |
| **Accounting** | Execution of high-risk financial processes (e.g., AP matching, AR adjustments). |
| **System Admin** | Global configuration and emergency access provisioning. |

- **Other Roles:** Configurable or risk-based MFA must be available for all other system roles based on Tenant policy.
- **Recovery Codes:** Secure, one-time recovery codes must be generated upon MFA enrollment.
- **MFA Reset & Segregation of Duties:** Administrative MFA resets require strict approval workflows and generate immutable audit events. Specifically:
  - No administrator may reset MFA for their own identity.
  - A high-privilege identity must not be able to self-approve its own MFA reset.
  - MFA reset for Owner, General Manager, Finance, Accounting, System Admin, or any equivalent privileged role requires verified identity recovery plus an auditable approval process. The approver must be independent from the target identity.
  - The MFA reset must trigger immediate revocation of all active sessions and require full re-authentication.
- **Prohibitions:** Silent MFA bypass is architecturally prohibited. Break-glass emergency access protocols must not be used as a shortcut or ordinary MFA bypass mechanism.

### 4. Enterprise SSO and Federation
To support enterprise hospitality clients, IVORQ must be architecturally ready for external identity federation. Immediate implementation is deferred until enterprise tenant onboarding requires it.
- **Supported Standards:** Future support for SAML 2.0 and OpenID Connect (OIDC).
- **Flows:** Support for both Identity Provider (IdP) initiated and Service Provider (SP) initiated flows.
- **Tenant-Scoped Configuration:** SSO settings must be strictly scoped and validated per Tenant.
- **Domain Verification:** Tenants must prove ownership of email domains before routing authentication to a federated IdP.
- **Provisioning:** The architecture must support both Just-In-Time (JIT) provisioning and controlled, pre-approved provisioning.
- **Fallback Policy:** Local fallback authentication is not a general bypass for SSO. Fallback is allowed only for a pre-provisioned, tenant-local emergency administrator identity. This fallback identity must have mandatory MFA. Fallback access must be time-limited, fully audited, and generate notification to designated Owner/security roles. The system must not automatically create a new local account during an IdP outage. The fallback policy must be explicitly enabled by tenant policy; otherwise, SSO outage must fail closed. Fallback access cannot bypass authorization, scope, approval, or audit requirements.

### 5. Session Lifecycle and Revocation
Session validity is an ongoing assertion of identity and must be strictly controlled.

- **Lifecycle:** Sessions must have clear creation boundaries, tenant-configurable idle timeouts, and absolute maximum duration limits requiring re-authentication.
- **Concurrency:** Concurrent session policies (e.g., single active session vs. multiple devices) must be configurable per Tenant.
- **Visibility:** Users must be able to view and manually revoke active sessions across their devices.
- **Client-Side Limits:** There is strictly no reliance on client-side logout alone. Revocation must occur at the backend token/session store.

**Mandatory Session Revocation Triggers**
| Trigger Event | Action |
| :--- | :--- |
| HR Offboarding / Termination | Immediate administrative revocation of all active sessions. |
| Password Change / Reset | Immediate invalidation of all prior sessions. |
| Privilege Reduction | Revocation or forced token refresh to prevent unauthorized action cache. |
| MFA Reset | Immediate session invalidation requiring full re-authentication. |
| Suspected Compromise | Immediate administrative revocation by System Admin. |

### 6. Authentication, Authorization, Scope, Approval, and Audit Boundaries
To prevent security spaghetti, IVORQ defines clear boundaries:
- **Authentication:** Validates *who* the identity is (ADR-030).
- **Authorization:** Determines *what* the authenticated identity may do (ADR-029).
- **Scope:** Constrains *where* the identity may act (Tenant vs. Property boundaries).
- **Approval:** Governs *whether* a specific authorized action may proceed based on business workflow (ADR-003).
- **Audit:** Provides immutable evidence of *what occurred* (ADR-002).

*Note: ADR-030 does not replace ADR-029 (Security/SoD), ADR-003 (Approval Engine), or ADR-002 (Audit Trail).*

### 7. Service Accounts and Integration Identities
External systems and internal background jobs must not masquerade as humans.
- **Identity Types:** Service accounts are strictly non-human identities. Shared human credentials for integrations are prohibited.
- **Scoping:** Integration identities must be strictly bound to a Tenant or Property scope.
- **Lifecycle:** API credentials must enforce least privilege, undergo periodic rotation, and have defined expiry or review periods.
- **Separation:** API tokens must be architecturally separated from end-user session handling.
- **Termination:** Integration termination must immediately disable the service account and revoke all active tokens.

### 8. Break-Glass and Emergency Access
Emergency access bypasses normal workflows but never bypasses auditability.
- **Time-Limited:** Break-glass sessions are strictly ephemeral and auto-revoke upon expiry.
- **Justification:** Activation requires mandatory, logged justification.
- **Notification:** Activation immediately notifies designated security and Owner roles.
- **Auditability:** Cannot bypass the immutable audit requirements established in ADR-002.
- **Review:** Post-incident review is mandatory.
- **Prohibition:** Break-glass cannot become a substitute for standard access provisioning or poor operational planning. (Cross-reference ADR-029 for SoD implications).

### 9. Identity Lifecycle and Offboarding
Identity states reflect the continuous relationship with the platform.
- **States:** 

| State | Required Meaning |
| :--- | :--- |
| **Invited** | Identity is provisioned but cannot access IVORQ until activation, verification, and required MFA enrollment are completed. |
| **Active** | Identity may authenticate and act only within its current authorization and scope. |
| **Suspended** | Access is temporarily blocked. All active sessions are revoked, but identity relationships, assignments, and audit history remain intact for review or reinstatement. |
| **Disabled** | Access is permanently or indefinitely revoked. All sessions, API access, delegated authority, and active role assignments are neutralized according to offboarding policy. |
| **Archived** | Identity can never authenticate. Historical identity, financial attribution, operational attribution, and audit evidence are retained without hard deletion. |

- **Offboarding:** Moving an identity to Suspended or Disabled triggers immediate session revocation globally across all backends.
- **Access Removal:** Scoped access rights are immediately neutralized. Pending approvals, delegated authority, and assigned tasks must be safely reassigned, cancelled, or escalated by workflow policy after Disablement.
- **Preservation:** Identity records with finance, security, or operational audit history must never be hard-deleted. Audit attribution must remain permanently preserved. Archived is strictly an audit-retention state, not a normal operational access state.

### 10. Audit, Privacy, and Secret-Handling Rules
Authentication acts are high-value audit targets.

**Mandatory Auditable Events**
| Event Category | Required Audit Action |
| :--- | :--- |
| Login Activity | Success and failure attempts, including contextual metadata. |
| MFA Lifecycle | Enrollment, successful validation, failure, and administrative resets. |
| Password Lifecycle | Resets, changes, and recovery requests. |
| Session Lifecycle | Creation, user revocation, administrative revocation, and expiry events. |
| SSO Configuration | Any modification to tenant IdP settings. |
| Integrations | Service account creation, credential rotation, and revocation. |
| Break-Glass | Activation, justification logging, and revocation. |
| Identity State | Status changes (e.g., Active to Disabled) and administrative privilege changes. |

**CRITICAL SECRET-HANDLING RULE:** Passwords, raw session tokens, API secrets, recovery codes, and sensitive cryptographic values must **never** be written to audit logs, application logs, or external monitoring systems under any circumstance.

### 11. Failure Modes and Safe Degradation
Security architecture must fail predictably and safely.
- **IdP Unavailable:** SSO tenants fallback to local authentication only if explicitly enabled by tenant policy, strictly utilizing a pre-provisioned, MFA-enforced emergency administrator identity. General access fails closed.
- **MFA Service Unavailable:** Fail-closed. Access is denied unless a break-glass policy explicitly applies and is invoked.
- **Session Store Unavailable:** Fail-closed. Active sessions cannot be validated, denying access.
- **Suspicious Concurrent Login:** Degrades to step-up authentication or temporary account suspension pending review.
- **Time Skew:** The architecture must handle clock/time skew resiliently to prevent improper session expiry or token rejection across distributed nodes.

## Alternatives Considered
- **Monolithic Auth & RBAC:** Combining ADR-030 and ADR-029 into one giant security document. Rejected because identity lifecycle and session governance operate at an infrastructure boundary, whereas SoD and approval workflows operate at the business logic boundary.
- **Relying solely on Client-Side JWT Expiry:** Rejected due to the inability to immediately revoke compromised sessions or handle instant HR offboarding. Backend session validation is mandatory.

## Consequences

### Positive Consequences
- **Clear Security Boundaries:** Decouples "Who are you?" from "What can you do?", clarifying ITGC audits.
- **Enterprise Ready:** Lays the foundational requirements necessary to onboard large, multi-property management groups demanding SSO and strict offboarding SLAs.
- **Reduced Liability:** Mandatory MFA for financial roles heavily reduces the risk of insider embezzlement via credential stuffing.

### Trade-Offs and Risks
- **Implementation Complexity:** Backend session revocation and integration with a global audit log require a high-performance session store (e.g., Redis) to avoid database bottlenecks on every request.
- **Operational Friction:** Mandatory MFA and strict session timeouts will introduce user friction, especially in fast-paced operational environments like Front Desk or Kitchen prep areas.

### Operational Requirements
- A robust, low-latency session store is required to evaluate session validity and revocation status on every secure request.

## Dependencies and Related ADRs
- **ADR-001:** Multi-Tenant Hierarchy (Defines the Tenant boundary).
- **ADR-002:** Audit Trail Strategy (Defines the immutable audit log requirements).
- **ADR-003:** Approval Engine (Governs workflow approvals).
- **ADR-004:** Finance Module Boundary (Defines core financial execution limits).
- **ADR-029:** Security, Roles & Permissions Governance (Defines SoD and Role-Based Access Control).

## Deferred Decisions
The architectural requirement for future SAML 2.0 and OpenID Connect support is established. The following implementation details are deferred until enterprise tenant implementation or enterprise onboarding requires them:
- Selection of a specific identity provider, identity broker, or federation vendor.
- Tenant-specific SSO configuration model and administrative workflow.
- Tenant-by-tenant decision between Just-In-Time provisioning and controlled pre-approved provisioning.
- Supported SSO metadata lifecycle, certificate rotation process, and tenant onboarding procedure.
- Enterprise directory synchronization approach, if required in the future.

## Open Questions Requiring CTO Approval
- None at this time.

## Validation Criteria
- Identity resolution strictly enforces Tenant boundaries.
- MFA is un-bypassable for Owner, GM, Finance, Accounting, and System Admin roles.
- Backend session revocation mechanism is demonstrably effective upon user disablement.
- Zero secrets exist in audit logs.

## References
- *Internal:* IVORQ ADR Master Structure Review
