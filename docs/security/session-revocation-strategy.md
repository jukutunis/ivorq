# Session Revocation Strategy

## Executive Summary
This document defines the enterprise governance for Session Revocation within the IVORQ Hospitality Operations Platform. Identified as a Tier 1 Security Priority alongside the Audit Log, an immediate, irrefutable mechanism to terminate user and API access is critical to mitigating internal and external threats.

This strategy acts as the authoritative source of truth for all token, session, and access lifecycle events, deeply integrating with the platform's architectural pillars:
* **ADR-001 (Multi-Tenant Hierarchy):** Enforces tenant and property isolation during mass revocation events (e.g., suspending an entire tenant).
* **ADR-002 (Audit Trail Strategy) & Mandatory Audit Entity Matrix:** Ensures every revocation event generates a mandatory, immutable audit log.
* **ADR-003 (Approval Engine Architecture):** Governs large-scale or high-risk revocation actions (e.g., Tenant Suspension) requiring executive sign-off.
* **ADR-004 (Finance Module Boundary):** Protects financial integrity by immediately halting access for users involved in financial fraud or offboarding.

## Session Governance Principles
* **Zero Trust:** Every request must re-validate the underlying session and token validity. A valid session ID does not guarantee access if the user's backend state has changed.
* **Least Privilege:** Sessions and tokens are granted only the minimum required access, scoped strictly to the authorized `tenant_id` and `property_id`.
* **Immediate Revocation:** Changes in user state (e.g., disable, delete, role removal) must instantly invalidate all associated active sessions and tokens.
* **Auditability:** A session revocation is not considered successful until the corresponding audit log is written.
* **Tenant Isolation:** A revocation action at the Enterprise level targeted at Tenant A must have zero impact on Tenant B.
* **Session Integrity:** Sessions are cryptographically bound to the user's initial authentication context.

## Session Types
The platform recognizes and governs the following session mechanisms:
* **Web Sessions:** Stateful HTTP sessions utilized by the core web application (e.g., Laravel Sanctum stateful guards).
* **Mobile Sessions:** Long-lived access tokens utilized by the mobile application.
* **API Tokens:** Stateless Bearer tokens (e.g., Sanctum personal access tokens) used for programmatic integration.
* **Service Tokens:** Internal machine-to-machine tokens used by microservices.
* **Integration Tokens:** Tokens provisioned to third-party vendors (e.g., Channel Managers, POS systems).
* **Future PMS Sessions:** Highly sensitive sessions managing guest PII and payment gateways.
* **Future HRIS Sessions:** Sessions accessing payroll and employee data.

## Revocation Events
The following events MUST act as mandatory triggers for immediate session and token revocation:
* **User Disabled:** Administrative action suspending a user account.
* **User Deleted:** Permanent removal of a user entity.
* **Password Changed:** User or admin-initiated password rotation.
* **Role Removed:** Demotion or change in access level.
* **Permission Removed:** Granular access revocation.
* **MFA Reset:** Administrative reset of Multi-Factor Authentication factors.
* **Account Lockout:** Triggered by anomalous behavior or repeated failed logins.
* **Tenant Suspension:** Enterprise action halting all operations for a specific client.
* **Property Access Removal:** Transferring a user out of a specific property.
* **Security Incident:** Automated trigger from the Web Application Firewall (WAF) or SIEM.
* **Emergency Revocation:** "Break-glass" manual revocation by a Security Administrator.
* **Forced Logout:** Administrative request to terminate a specific session.
* **Credential Rotation:** Scheduled token expiration.

## User Disable Workflow
* **Trigger:** Administrator clicks "Disable User" or automated HRIS sync marks the employee as terminated.
* **Validation:** System verifies the administrator's authority.
* **Session Revocation:** All active Web and Mobile sessions associated with the `user_id` are destroyed.
* **Token Revocation:** All active `personal_access_tokens` associated with the `user_id` are immediately revoked or deleted.
* **Audit Logging:** Critical severity event written to `LogsActivity` specifying the administrator, target user, and reason.
* **Notification:** Optional email sent to the target user (if policy dictates).
* **Expected Result:** The user is instantly challenged with a 401 Unauthorized response on their next network request.

## User Offboarding Workflow
* **Disable User:** Execute the standard Disable Workflow.
* **Revoke Sessions & Tokens:** Complete destruction of all authentication artifacts.
* **Remove Role Assignments:** Strip all Spatie Roles and Property assignments to prevent accidental reactivation.
* **Transfer Responsibilities:** Reassign pending approval requests (ADR-003) and assigned Work Orders to an active manager.
* **Archive Access History:** Preserve the user's Audit Trail per the Mandatory Audit Entity Matrix (7 Years).
* **Audit Requirements:** Log the comprehensive offboarding event.

## Password Change Workflow
* **Current Session:** Retained (the session that initiated the change).
* **Other Sessions:** All *other* active sessions and mobile tokens for the user are immediately revoked.
* **Token Handling:** API/Integration tokens are evaluated based on policy (typically retained unless it's an emergency reset).
* **Audit Events:** Log `Password Changed` and `Other Sessions Revoked`.
* **Notification Requirements:** Security alert sent to the user's primary email.

## Emergency Revocation Workflow
* **Security Breach / Credential Leak:** Triggered by anomalous activity (e.g., impossible travel).
* **Compromised Device:** The user reports a lost or stolen device.
* **Insider Threat:** Immediate termination request from HR or Legal.
* **Immediate Actions:** Global revocation of all sessions and tokens, forced password reset flag applied, MFA re-enrollment forced.
* **Expected Outcomes:** Complete lockout until the identity is re-verified by an Enterprise Security Administrator.

## Tenant Suspension Workflow
* **Authentication Blocking:** Reject all new authentication requests for the specific `tenant_id`.
* **Session Revocation:** Mass destruction of all active sessions and tokens belonging to any user associated with the `tenant_id`.
* **Approval Freeze:** Halt all active workflows in the Approval Engine (ADR-003).
* **Transaction Freeze:** Lock all financial postings (ADR-004).
* **Audit Requirements:** Requires ADR-003 approval from IVORQ Corporate Management. Event logged at Critical severity.
* **Recovery Process:** Strictly audited manual un-suspension workflow.

## Property Access Revocation
* **Property Removal:** When a user is transferred out of Property A, their session must be refreshed or revoked to immediately purge their `property_id` context for Property A.
* **Department Transfer:** Evaluated similarly to Property Removal if Department-level segregation is enforced.
* **Role Changes & Visibility Changes:** Triggers session invalidation to force the client-side application to request a new permissions payload.
* **Required Actions:** Token refresh or forced re-login.

## API Token Governance
* **Creation:** Must be bound to a specific `user_id`, `tenant_id`, and scope.
* **Expiration:** All tokens must have a strict, non-negotiable expiration timestamp (e.g., 90 days max).
* **Revocation:** Tokens must be individually revokable without impacting the user's web session.
* **Rotation:** Automated mechanisms to replace expiring tokens seamlessly.
* **Audit Requirements:** Token generation, usage (first/last), and revocation are Critical audit events.
* **Visibility Rules:** Tokens are visible only to the owning user and Tenant Administrators.

## Session Visibility Rules
* **Enterprise:** IVORQ Super Admins can view active sessions across the platform for diagnostic purposes but cannot hijack them.
* **Tenant:** Corporate Administrators can view and forcefully revoke sessions for any user within their Tenant.
* **Property:** Property Managers can view and revoke sessions for users strictly assigned to their Property.
* **Auditor:** Read-only access to historical session metadata and revocation logs.
* **Security Administrator:** Full authority to execute Emergency Revocations.
* **Cross-Tenant Restrictions:** Hard physical boundaries; Tenant A cannot see or affect Tenant B's sessions.

## Audit Integration
Tight integration with ADR-002 and the Mandatory Audit Entity Matrix:
* Every revocation event (automated or manual) must generate an immutable audit log.
* **Mandatory Audit Events:** `Session Created`, `Session Revoked`, `Token Issued`, `Token Revoked`, `Mass Revocation Executed`, `User Disabled`.
* The payload must include the actor, target, timestamp, IP address, and revocation reason.

## Approval Integration
Integration with ADR-003:
* **Tenant Suspension:** Requires multi-level Enterprise executive approval.
* **Mass Revocation:** Mass password resets or tenant-wide session invalidations require Tenant Administrator approval.
* **Emergency Access Changes:** "Break-glass" actions bypass pre-approval but trigger immediate retrospective audit escalations.

## Security Requirements
* **Immediate Revocation:** The system must evaluate the revocation state synchronously. Caching layers (e.g., Redis) must be purged instantly upon a revocation event.
* **Maximum Revocation Delay:** The time between a trigger (e.g., User Disabled) and the rejection of the next request must not exceed 1000 milliseconds.
* **Session Integrity:** Defend against session fixation and hijacking.
* **Token Integrity:** Use secure, unguessable cryptographic tokens (e.g., Sanctum).
* **Device Trust:** Revocations should optionally target specific device fingerprints.
* **Fraud Prevention:** Anomalous velocities (e.g., generating 100 tokens a minute) trigger automatic lockouts.
* **Audit Integrity:** Revocation logs are append-only and tamper-evident.

## Service Level Objectives
* **Maximum Revocation Delay:** < 1 second.
* **Maximum Detection Delay (Automated Triggers):** < 5 seconds.
* **Maximum Recovery Time (False Positive Lockout):** < 15 minutes.
* **Emergency Response Targets:** Security Administrator mass revocation execution < 60 seconds.

## Anti-Patterns
The following practices are explicitly prohibited:
* **Disabled users with active sessions:** Failing to destroy the session file/record when flipping the `is_active` flag.
* **Active tokens after offboarding:** Leaving long-lived API tokens active for terminated employees.
* **Cross-tenant session visibility:** Administrative screens lacking a `tenant_id` global scope.
* **Manual database session deletion:** Deleting records from the `sessions` table directly via SQL, bypassing the audit trail.
* **Silent revocation:** Terminating access without generating a corresponding audit log.
* **Unlogged security actions:** Password resets executed directly in the database.
* **Long-lived unrestricted tokens:** Creating API tokens that never expire and have wildcard `*` permissions.

## Future Expansion
* **SSO / SAML / OIDC:** Integration with external Identity Providers (e.g., Entra ID, Okta). The revocation strategy must support federated logout.
* **Corporate Directory:** Active Directory sync for automated provisioning and offboarding.
* **HRIS Integration:** When IVORQ HRIS marks an employee as terminated, global session revocation executes automatically.
* **Automated Offboarding:** Orchestrated removal of access across all IVORQ modules and third-party integrations.
* **Device Management:** MDM integration to wipe local data on compromised devices upon session revocation.

## Implementation Priorities
* **Phase 1 (Immediate):** User Disable Workflow, Password Change Workflow, Token Revocation, Audit Integration. (Highest security risk).
* **Phase 2 (Near-Term):** Property Access Revocation, API Token Expiration, Emergency Revocation.
* **Phase 3 (Mid-Term):** Tenant Suspension Workflow, Advanced Session Visibility Dashboards.
* **Phase 4 (Future):** SSO Federated Logout, Automated HRIS Offboarding.

## Final Recommendation

**Highest-Risk Revocation Scenarios:**
1. Terminated employees accessing financial data.
2. Compromised Administrator accounts.
3. Stolen long-lived API tokens.

**Immediate Rollout Candidates (Phase 1):**
The core `User Disable` event must be rigidly bound to the session and token destruction services. Any code path that disables a user MUST forcefully execute the revocation logic and log the action, establishing the foundational Tier 1 security baseline.
