# Security Hardening Execution Plan

## Executive Summary
The IVORQ Hospitality Operations Platform has successfully established its Core and Security Governance layers through a series of authoritative Architecture Decision Records (ADRs) and security matrices. Current readiness indicates that the architectural design phase is complete, and the platform is primed for execution.

**Completed Governance Work:**
* **Core Governance:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-003 (Approval Engine Architecture), ADR-004 (Finance Module Boundary).
* **Security Governance:** Mandatory Audit Entity Matrix, Session Revocation Strategy.

**Remaining Implementation Work:**
The platform now moves from Governance to Execution. This execution plan acts as the authoritative roadmap for implementing the Tier 1 (Audit Log, Session Revocation), Tier 2 (Offboarding Workflow), and Tier 3 (MFA for critical roles) security requirements.

## Current State Assessment
* **Governance Foundation:** 100% (Complete). The authoritative ADRs provide a clear, unambiguous blueprint for multi-tenancy, approval routing, and financial boundaries.
* **Security Foundation:** 100% (Complete). The Mandatory Audit Entity Matrix and Session Revocation Strategy dictate the exact requirements for securing data and managing token lifecycles.
* **Implementation Readiness:** Ready for Execution. Development teams have clear, prioritized directives to begin closing the identified security gaps.

## Security Gap Closure Strategy
The execution strategy directly maps findings from the Security Hardening Plan to actionable development phases. 
* Gap: 8.1% Audit Coverage → Mapped to **Phase 1 (Audit Foundation Rollout)** utilizing the Mandatory Audit Entity Matrix.
* Gap: Unmanaged Session Lifecycles → Mapped to **Phase 2 (Session Revocation Rollout)** utilizing the Session Revocation Strategy.
* Gap: Orphaned Access upon Termination → Mapped to **Tier 2 Offboarding Program**.
* Gap: Weak Authentication for Critical Roles → Mapped to **Phase 4 (MFA Rollout)**.

## Phase Structure
The execution is structured sequentially to prioritize the highest risk vectors first:
* **Phase 1:** Audit Foundation Rollout (Tier 1 Priority)
* **Phase 2:** Session Revocation Rollout (Tier 1 Priority)
* **Phase 3:** Security Testing & Validation (Enterprise Validation)
* **Phase 4:** Multi-Factor Authentication (Tier 3 Priority) & Offboarding (Tier 2 Priority)

---

## Phase 1: Audit Foundation Rollout

### Objectives
Achieve 100% compliance with the Mandatory Audit Entity Matrix for all Phase 1 and Phase 2 entities.

### Scope
Implementation of `spatie/laravel-activitylog` across all Critical and High severity entities within the Foundation, Finance, Accounting, and Cost Control domains.

### Deliverables
* Complete audit logging for Top 25 Highest-Risk Entities.
* Immutable, append-only database configuration for audit tables.
* Tenant and Property global scopes integrated into all audit queries.

### Dependencies
* ADR-001 (Multi-Tenant Hierarchy) enforcement mechanisms.
* ADR-002 (Audit Trail Strategy) standardized logging wrapper.

### Risks
* Database bloat due to excessive logging on high-volume transactions. (Mitigated by strict adherence to the Matrix).

### Success Criteria
* No 'Mandatory' entity in the target domains can undergo a state change without generating a verified, scoped audit log.

---

## Audit Coverage Rollout
Guided by the Mandatory Audit Entity Matrix:

1. **Critical Entities (Sprint 14.8.7):** `User`, `Role`, `Permission`, `Tenant`, `Budget`, `JournalEntry`, `PaymentVoucher`.
2. **High Entities (Sprint 14.8.8):** `PurchaseOrder`, `Vendor`, `Folio`, `Posting`.
3. **Medium Entities (Sprint 14.8.9):** Operational workflows (e.g., `WorkOrder`, `PurchaseRequest`).
4. **Low Entities (Sprint 15.0):** Reference data and metadata tables.

*Recommended implementation order:* Highest financial and security risk first.

---

## Audit Verification
* **Unit Tests:** Assert that Eloquent events (`created`, `updated`, `deleted`) trigger the `LogsActivity` trait.
* **Feature Tests:** Assert that complex workflows (e.g., Approval Engine overrides) generate the corresponding semantic audit log.
* **Integration Tests:** Assert that the resulting database record physically contains the correct `tenant_id` and `property_id`.
* **Audit Validation:** Automated CI/CD pipeline step failing the build if a 'Mandatory' entity lacks the required logging trait.

---

## Phase 2: Session Revocation Rollout

### Objectives
Implement the authoritative Session Revocation Strategy to guarantee instant termination of unauthorized access.

### Scope
Stateful web sessions, mobile tokens, and stateless API tokens (`personal_access_tokens`).

### Deliverables
* Integrated revocation hooks within user lifecycle management.
* Cache-invalidation mechanisms for active sessions.
* Admin panel UI for forced logouts.

### Dependencies
* Authentication guard configurations (Sanctum).
* Mandatory Audit Entity Matrix (to log the revocations).

### Risks
* Latency spikes if revocation checks are poorly optimized (Mitigated by Redis caching).

### Success Criteria
* Sub-1000ms rejection of network requests following a targeted revocation event.

---

## Revocation Workflows to Implement
1. **User Disable Workflow:** Instantly destroy all sessions and tokens when `is_active` becomes false.
2. **Password Change Workflow:** Retain the current session, destroy all others globally.
3. **Emergency Revocation Workflow:** "Break-glass" global wipe of all authentication artifacts for a user.
4. **Tenant Suspension Workflow:** Reject all authentication and authorization requests globally for a specific `tenant_id`.
5. **API Token Governance:** Implement strict expiration dates and rotation capabilities for all API tokens.

---

## Phase 3: Security Testing & Validation

### Objectives
Empirically validate the effectiveness of Phase 1 and Phase 2 implementations.

### Scope
All tenant boundaries, property boundaries, and audit trail integrity constraints.

### Deliverables
* Automated Penetration Testing Suite.
* Compliance Verification Report.

### Dependencies
* Phase 1 and Phase 2 completion.

### Risks
* Identifying architectural flaws late in the cycle requiring refactoring.

### Success Criteria
* 100% pass rate on all isolated security test suites.

---

## Test Categories
* **Authentication:** Verify token expiration, MFA enforcement (if active), and session fixation defenses.
* **Authorization:** Validate role/permission checks strictly evaluate property context.
* **Tenant Isolation:** Ensure a User in Tenant A receives a 403/404 when querying Tenant B data.
* **Audit Logging:** Verify append-only constraints and data completeness.
* **Session Revocation:** Verify sub-second enforcement of user disablement.
* **Approval Visibility:** Ensure approvals cannot cross property lines unless explicitly authorized.
* **Financial Visibility:** Validate ADR-004 constraints (e.g., Cost Control vs. Accounting visibility).
* **Cross-Tenant Protection:** Attempt unauthorized lateral movement across the multi-tenant architecture.

---

## Phase 4: MFA Rollout

### Objectives
Enforce Multi-Factor Authentication for the highest-risk operational and administrative roles.

### Scope
Roles possessing financial authorization or system-wide administrative capabilities.

### Deliverables
* MFA enrollment UI/UX.
* MFA enforcement middleware.
* Recovery code workflow.

### Dependencies
* Phase 2 (Session Revocation) - to revoke sessions if MFA is disabled or reset.

### Risks
* User friction leading to operational delays or increased support tickets.

### Success Criteria
* 100% MFA adoption for targeted roles without degrading daily operational velocity.

---

## MFA Governance
MFA is **Mandatory** for the following roles:
* **Owner:** Total access to Tenant configuration.
* **GM:** Total access to Property configuration.
* **Finance:** Access to GL, P&L, Budgets, and high-value approvals.
* **Accounting:** Access to Journals, AP, and AR.
* **System Admin:** Enterprise IVORQ platform access.

---

## MFA Enforcement Strategy
* **Soft Enforcement:** 14-day grace period for enrollment upon login.
* **Mandatory Enforcement:** Account lock (requiring session revocation) if enrollment is incomplete post-grace period.
* **Exception Handling:** No exceptions for mandatory roles.
* **Recovery Workflow:** Secure generation and validation of backup recovery codes, tied to ADR-003 approval chains for resets.

---

## Offboarding Program
Integrates the Session Revocation Strategy, Approval Engine, and Audit Trail.
* **Disable User:** Trigger the primary disablement flag.
* **Revoke Access:** Execute the Phase 2 Session/Token revocation.
* **Transfer Responsibilities:** Safely re-route pending approvals to the user's manager.
* **Archive History:** Lock the user record (soft delete) to preserve the historical audit trail.
* **Verification:** Generate an automated "Offboarding Complete" compliance receipt.

---

## Security Metrics
* **Audit Coverage %:** Ratio of audited entities vs. Mandatory entities (Target: 100%).
* **Session Revocation Time:** Milliseconds from trigger to effective lockout (Target: < 1000ms).
* **MFA Adoption %:** Enforced roles vs. enrolled roles (Target: 100%).
* **Security Test Coverage %:** Critical workflows covered by automated security tests (Target: > 90%).
* **Cross-Tenant Incident Count:** Number of identified cross-tenant data leaks (Target: 0).

---

## Enterprise Readiness Gates
* **Gate 1: Audit Foundation Complete.** All Tier 1 audit endpoints secured.
* **Gate 2: Session Revocation Complete.** All disable workflows enforce immediate lockout.
* **Gate 3: Security Validation Complete.** Automated security test suites passing.
* **Gate 4: MFA Complete.** High-risk roles actively using multi-factor authentication.

---

## Sprint Mapping
* **Sprint 14.8.7:** Phase 1 (Audit Foundation Rollout - Critical Entities).
* **Sprint 14.8.8:** Phase 1 (High/Medium Entities) & Phase 2 (Session Revocation Core Workflows).
* **Sprint 14.8.9:** Phase 2 (Tenant Suspension & Offboarding Workflow) & Phase 3 (Security Testing).
* **Sprint 15.0:** Phase 4 (MFA Rollout) & Final Security Sign-off for Enterprise Launch.

---

## Risk Register
| Description | Likelihood | Impact | Mitigation | Owner |
| :--- | :--- | :--- | :--- | :--- |
| Database performance degradation due to massive audit log volume. | Medium | High | Implement log partitioning, async queued logging if necessary, and strict adherence to the Matrix. | Enterprise Architect |
| Session revocation latency causing race conditions allowing unauthorized actions. | Low | Critical | Utilize synchronous, memory-backed cache invalidation (Redis) tied directly to the authentication guard. | Security Architect |
| Cross-tenant data leak due to missing global scopes. | Medium | Critical | Enforce strict security testing (Phase 3) and mandatory code reviews for all model configurations. | QA Lead |
| User friction during MFA rollout causing operational delays. | High | Medium | Implement the 14-day Soft Enforcement grace period and provide clear recovery workflows. | Program Manager |

---

## Final Recommendation
* **Highest-Priority Implementation Work:** `spatie/laravel-activitylog` integration for the Top 25 Highest-Risk Entities, and the `User Disable` session revocation hook.
* **Highest-Risk Security Gaps:** Current lack of immediate API token revocation and incomplete financial audit trails.
* **Recommended Execution Order:** Phase 1 → Phase 2 → Phase 3 → Phase 4. Strict adherence to this sequence ensures auditing is in place before implementing advanced access controls.
* **Go/No-Go Criteria:** Enterprise launch (Sprint 15.0) must be halted (No-Go) if Gate 1 (Audit Foundation) or Gate 2 (Session Revocation) are not 100% complete and verified.
