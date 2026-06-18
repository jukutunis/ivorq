# IVORQ Security Hardening Plan

## Executive Summary
This Security Hardening Plan has been developed to elevate the IVORQ Hospitality Operations Platform to Enterprise Multi-Tenant SaaS standards. Built upon the findings of the CTO Validation Report and V2 Master Audit, this document transitions the engineering focus from feature delivery to strict security governance. While IVORQ demonstrates strong foundational multi-tenant data isolation, critical gaps exist in Tier 1 (Audit Logging, Session Revocation) and Tier 3 (MFA) requirements that pose unacceptable risks for enterprise hospitality customers. This plan serves as the definitive roadmap to close these gaps.

### Security Scores
* **Overall Security Score:** 65/100
* **Tier 1 Security Score (Audit & Sessions):** 40/100
* **Tier 2 Security Score (Offboarding):** 60/100
* **Tier 3 Security Score (MFA):** 0/100

---

## Security Findings

### CRITICAL
* **SEC-FND-01:** Near-zero audit trail coverage outside of Foundation models. Core financial, procurement, and sales events can be mutated without trace.
* **SEC-FND-02:** Lack of Multi-Factor Authentication (MFA) for highly privileged roles (Owner, GM, Finance), presenting a critical vulnerability to account takeover.

### HIGH
* **SEC-FND-03:** Web session revocation is partially implemented. While Sanctum API tokens are deleted on global logout, Laravel's `AuthenticateSession` middleware is missing from `bootstrap/app.php`, preventing forced invalidation of active stateful web sessions.
* **SEC-FND-04:** Security testing suite lacks explicit coverage for audit log generation and session revocation flows.

### MEDIUM
* **SEC-FND-05:** Potential role/permission drift in newly created modules (Finance, Sales) that may not fully adhere to the Principle of Least Privilege.

### LOW
* **SEC-FND-06:** Minor tenant isolation ambiguity identified in the Purchasing module (`Vendor` migration), presenting a low but present risk of cross-property data leakage.

---

## Audit Log Coverage Matrix

**Coverage Summary:**
* **Total Models:** 232
* **Models using `LogsActivity`:** 19 (Foundation only)
* **Models missing `LogsActivity`:** 213
* **Coverage Percentage:** 8.1%
* **High-Risk Gaps:** Financial transactions, Vendor management, Forecasting, and BEO operations are entirely un-audited.

| Model | Domain | Audit Status | Classification | Risk |
|---|---|---|---|---|
| `User` | Foundation | PASS | Mandatory | Low |
| `Vendor` | Purchasing | FAIL | Mandatory | High |
| `PurchaseRequest` | Purchasing | FAIL | Mandatory | High |
| `PurchaseOrder` | Purchasing | FAIL | Mandatory | High |
| `PaymentVoucher` | Payables | FAIL | Mandatory | High |
| `Forecast` | Forecasting | FAIL | Mandatory | High |
| `BEOIssueLog` | Sales | FAIL | Mandatory | High |
| `ApprovalRequest` | Approval | PASS | Mandatory | Low |

---

## Session Revocation Assessment

| Feature | Status | Notes |
|---|---|---|
| API Token Deletion | Implemented | `AuthService::logoutAllDevices` successfully deletes Sanctum tokens. |
| Web Session Forced Logout | Missing | `AuthenticateSession` middleware is not applied; active web sessions persist even if password changes or account is disabled. |
| Emergency/Tenant Revocation | Missing | No mechanism to invalidate all sessions for a specific property or tenant immediately. |
| **Overall Assessment** | **Partially Implemented** | Immediate remediation required for web session validation. |

---

## MFA Readiness Assessment

| Role | Status | Notes |
|---|---|---|
| Owner | Not Ready | No two-factor authentication capability currently exists in the repository. |
| GM | Not Ready | |
| Finance | Not Ready | |
| Accounting | Not Ready | |
| System Admin | Not Ready | |
| **Overall Assessment** | **Not Ready** | MFA is a strict requirement for financial & administrative roles. |

---

## Authorization Assessment

* **Overall Status:** **WARNING**
* **Role/Permission Consistency:** Spatie `HasRoles` is correctly utilized, but new domains require a strict audit to ensure permissions are not overly broad.
* **Principle of Least Privilege:** Generally followed, but requires test validation for edge cases.

---

## Tenant Isolation Assessment

* **Overall Status:** **WARNING**
* **Isolation Mechanics:** Global scopes for `company_id` and `property_id` are largely excellent.
* **Weaknesses:** Ambiguous foreign key relationships in the `vendors` table risk leaking vendor data across properties within the same company.

---

## Security Testing Assessment

* **Overall Status:** **FAIL**
* **Missing Tests:** No automated tests exist to verify that `LogsActivity` correctly fires on business events, nor are there tests verifying that revoked sessions block web requests.

---

## Security Hardening Backlog

### SEC-001: Enforce System-Wide Audit Logging
* **Description:** Apply `Spatie\Activitylog\Traits\LogsActivity` to all remaining 213 mutative Eloquent models in business modules.
* **Risk:** High
* **Priority:** P1
* **Complexity:** Medium
* **Dependencies:** None

### SEC-002: Implement Web Session Revocation
* **Description:** Register `Illuminate\Session\Middleware\AuthenticateSession` in `bootstrap/app.php` and enforce password hash validation on every web request.
* **Risk:** High
* **Priority:** P1
* **Complexity:** Low
* **Dependencies:** None

### SEC-003: Standardize Vendor Tenancy Boundaries
* **Description:** Resolve the tenancy ambiguity in the `Vendor` migration. Enforce strict `property_id` isolation or clear `company_id` sharing rules per ADR-004.
* **Risk:** Low
* **Priority:** P2
* **Complexity:** Low
* **Dependencies:** None

### SEC-004: Security Test Suite Expansion
* **Description:** Implement comprehensive test coverage for Audit Log generation, cross-tenant isolation, and session revocation.
* **Risk:** Medium
* **Priority:** P2
* **Complexity:** Medium
* **Dependencies:** SEC-001, SEC-002

### SEC-005: Enterprise MFA Implementation
* **Description:** Integrate Two-Factor Authentication (e.g., via Laravel Fortify) and mandate it for Owner, GM, Finance, Accounting, and SysAdmin roles.
* **Risk:** Critical
* **Priority:** P3
* **Complexity:** High
* **Dependencies:** None

---

## Sprint Assignment

### Sprint 14.8.7 (Focus: Tier 1 Security Foundation)
* **SEC-001:** Enforce System-Wide Audit Logging across all domains.
* **SEC-002:** Implement Web Session Revocation via middleware.
* **SEC-003:** Standardize Vendor Tenancy Boundaries.

### Sprint 14.8.8 (Focus: Security Verification)
* **SEC-004:** Security Test Suite Expansion (Audit and Revocation tests).
* Audit Spatie Role/Permission definitions across newly merged business modules.

### Sprint 14.8.9 (Focus: Tier 3 Security)
* **SEC-005:** Enterprise MFA Implementation and Role-based enforcement.

---

## Enterprise Security Readiness

| Area | Score | Justification |
|---|---|---|
| **Audit Log Readiness** | 1/10 | Unacceptable coverage for a financial/hospitality system. Requires immediate rollout. |
| **Session Revocation Readiness** | 4/10 | API tokens handle revocation properly, but stateful web sessions are a major vulnerability. |
| **MFA Readiness** | 0/10 | Capability does not exist. |
| **Authorization Readiness** | 8/10 | Strong Spatie foundation, needing only minor validation against drift. |
| **Tenant Isolation Readiness** | 8/10 | Excellent multi-tenant architecture with only minor schema ambiguities. |
| **Overall Security Readiness** | **4/10** | The platform is **NOT READY** for enterprise production launch until Tier 1 gaps (Audit, Sessions) are fully closed. |
