# Sprint 14.8.7 Compliance Remediation Backlog

## Executive Summary
This sprint backlog addresses the critical security and architectural deviations identified in the *ADR Compliance Audit v1*. The purpose of Sprint 14.8.7 is to halt feature development and dedicate enterprise engineering capacity exclusively to securing the platform's foundation. It directly maps the audit's findings—specifically the unauthorized property scope bypasses, the lack of session revocation hooks, critical gaps in the audit trail, and Approval Engine bypasses—into executable, prioritized engineering work packages. Closing these gaps is a mandatory prerequisite for enterprise deployment and SOC 2 readiness.

## Sprint Goal
**Primary Goal:** Secure the enterprise foundation by closing all Tier 1 architectural and security compliance gaps (Audit, Tenancy, and Session Revocation).
**Secondary Goal:** Re-align operational modules with the centralized Approval Engine and prepare the groundwork for the `company_id` to `tenant_id` database refactoring.
**Success Definition:** 100% of P0 work packages deployed to staging and verified passing via automated security penetration tests without degrading existing operational functionality.

## Remediation Priorities
* **P0 (Blockers):** Must be resolved immediately. Any failure here compromises the entire platform's security and tenant isolation. (WP001, WP002, WP003)
* **P1 (Critical):** Must be resolved in this sprint to ensure governance and workflow integrity. (WP004)
* **P2 (High/Tech Debt):** Discovery and assessment work to prepare for massive refactoring in the subsequent sprint. (WP005)

---

## WORK PACKAGE 001

**Title:** Remove Property Scope Bypass
**Source:** ADR-001 Compliance Finding
**Priority:** P0
**Risk:** Critical

**Business Impact:**
Prevents catastrophic cross-property data leaks. Ensures that a General Manager or Cost Controller at Property A cannot view, modify, or deduct inventory at Property B, safeguarding physical asset valuation and tenant trust.

**Technical Impact:**
Forces the `InventoryStockRepository` to respect global property boundaries. Prevents `withoutGlobalScope('property')` from executing during routine operational workflows. 

**Affected Files:**
* `Modules/Operations/Inventory/Repositories/InventoryStockRepository.php`

**Dependencies:**
* None. Can be executed immediately.

**Acceptance Criteria:**
* All instances of `withoutGlobalScope('property')` are removed from the repository.
* Queries default to the authenticated user's active property scope.
* If a cross-property lookup is legitimately required by business rules, it must be executed via a dedicated, strictly authorized Enterprise service, not by bypassing the model's global scope.

**Definition of Done:**
* Code is refactored.
* Unit tests assert that cross-property queries return 404/Empty.
* Feature tests pass.
* Code review approved by Enterprise Architect.

**Estimated Complexity:**
Low (3 Story Points)

---

## WORK PACKAGE 002

**Title:** Session Revocation on User Disable
**Source:** Session Revocation Strategy, ADR Compliance Audit
**Priority:** P0
**Risk:** Critical

**Business Impact:**
Instantly neutralizes insider threats. Ensures that terminated employees or compromised accounts lose all platform access within milliseconds, preventing data theft or unauthorized financial transactions.

**Technical Impact:**
Hooks into the core user lifecycle to trigger Sanctum token deletion and stateful session flush events when the `is_active` flag transitions to `false` or during a password reset.

**Affected Components:**
* `Modules/Foundation/User/Services/UserService.php`
* `Modules/Foundation/Authentication/Services/AuthService.php`

**Dependencies:**
* Requires `LogsActivity` (WP003) to ensure the revocation event is audited.

**Acceptance Criteria:**
* Disabling a user via the UI/API immediately revokes all active stateful sessions.
* Disabling a user immediately deletes all associated `personal_access_tokens`.
* The revocation action generates an immutable audit log.
* Subsequent network requests using the revoked token/session return a 401 Unauthorized response.

**Definition of Done:**
* Revocation logic implemented.
* Integration tests simulate a concurrent active session and verify immediate lockout upon disable.
* Code review approved by Security Architect.

**Estimated Complexity:**
Medium (5 Story Points)

---

## WORK PACKAGE 003

**Title:** Critical Audit Coverage Rollout
**Source:** ADR-002, Mandatory Audit Entity Matrix
**Priority:** P0
**Risk:** Critical

**Business Impact:**
Establishes the foundational non-repudiation and forensic trail required for enterprise compliance (SOC 2, PCI). Ensures every financial mutation and security access change is irrefutably tracked.

**Technical Impact:**
Implements the `spatie/laravel-activitylog` package across the highest-risk Eloquent models. Ensures the generated logs automatically capture the `tenant_id` and `property_id` boundaries.

**Mandatory Entities:**
* `Permission`, `Tenant` (Company), `APIToken`
* `Budget`, `BudgetRevision`, `JournalEntry`, `JournalLine`, `PaymentVoucher`, `FinancialPeriod`, `AccountsPayable`, `AccountsReceivable`, `BankRecon`
* `PurchaseOrder`, `Vendor`, `Folio`, `Posting`

**Implementation Order:**
1. Security & Foundation Entities
2. General Ledger & Finance Entities
3. Purchasing & PMS Entities

**Acceptance Criteria:**
* All listed entities implement `LogsActivity`.
* CRUD operations on these entities generate an audit log record.
* Audit records must explicitly contain the `properties` array (before/after state) for `update` actions.
* Database operations against the activity log table are strictly append-only (no deletes).

**Definition of Done:**
* Trait applied.
* Automated tests verify that an Eloquent `update` on `JournalEntry` creates an Activity Log entry.
* Approved by Security Architect.

**Estimated Complexity:**
Medium (8 Story Points)

---

## WORK PACKAGE 004

**Title:** Approval Engine Compliance
**Source:** ADR-003 Compliance Findings
**Priority:** P1
**Risk:** High

**Business Impact:**
Prevents operational staff from bypassing authorization chains. Ensures that a Purchase Order or Work Order cannot be forcefully "Approved" without navigating the enterprise-defined governance workflow.

**Technical Impact:**
Removes hardcoded status transitions (e.g., `update(['status' => 'Approved'])`) from domain services and replaces them with event-driven or delegated calls to the central `ApprovalEngineService`.

**Affected Modules:**
* Purchasing (`PurchaseOrder.php`, `PurchasingApprovalIntegrationService.php`)
* Work Order (`WorkOrderApprovalService.php`, `WorkOrderClosureService.php`)
* Receiving (`ReceivingDocument.php`, `ReceivingService.php`)
* Finance (`PaymentVoucherService.php`, `ThreeWayMatchingEngine.php`)

**Acceptance Criteria:**
* Direct status updates transitioning an entity to `Approved`, `Rejected`, or `Pending Approval` are removed.
* State transitions are delegated to the `ApprovalEngineService`.
* Workflows properly instantiate `ApprovalRequest` polymorphs instead of bypassing the engine.

**Definition of Done:**
* Hardcoded updates removed.
* Feature tests updated to mock or interact with the actual Approval Engine.
* Code review approved by Governance Architect.

**Estimated Complexity:**
High (13 Story Points)

---

## WORK PACKAGE 005

**Title:** Company to Tenant Refactor Assessment
**Source:** ADR-001 Compliance Findings
**Priority:** P2
**Risk:** Medium

**Scope:**
Conduct a comprehensive blast-radius assessment for renaming `company_id` to `tenant_id` and the `Company` model to `Tenant` across the entire codebase, database schema, and frontend clients.

**Impact Analysis:**
Identify all migrations, controllers, models, policies, global scopes, API payloads, and frontend routing that utilize the legacy "Company" nomenclature. 

**Required Deliverables:**
* A structured migration script strategy (Zero-Downtime Migration Plan).
* A list of all affected API endpoints.
* A risk mitigation plan for the frontend application.

**Acceptance Criteria:**
* Delivery of a technical design document detailing the exact sequence of PRs required to execute the refactoring in Sprint 14.8.8.
* No code is merged in this sprint for the refactor; only the assessment is delivered.

**Definition of Done:**
* Assessment document physically created, peer-reviewed, and signed off by the CTO.

**Estimated Complexity:**
Low (3 Story Points - Discovery Only)

---

## Sprint Metrics

* **Audit Coverage %:** Target 100% for Top 25 Mandatory Entities.
* **Approval Compliance %:** Target 100% removal of direct status updates for specified modules.
* **Session Revocation Coverage %:** Target 100% on `is_active` toggle.
* **Critical Findings Closed %:** Target 100% of P0 findings from the Audit.

---

## Sprint Risks

* **Risk 1:** Database performance degradation due to massive audit log generation (WP003).
  * **Impact:** High. Slow transaction times during End of Day/Night Audit.
  * **Mitigation:** Implement batched/async queueing for `LogsActivity` if synchronous logging exceeds 50ms latency.
  * **Owner:** Technical Program Manager

* **Risk 2:** Broken operational workflows due to Approval Engine integration (WP004).
  * **Impact:** High. Departments unable to issue POs.
  * **Mitigation:** Strict feature testing; ensure `ApprovalEngineService` gracefully handles legacy states during the deployment window.
  * **Owner:** Delivery Manager

* **Risk 3:** Incomplete Session Revocation (WP002).
  * **Impact:** Critical. False sense of security.
  * **Mitigation:** Write specific automated penetration tests verifying sub-second token invalidation.
  * **Owner:** Security Architect

---

## Sprint Exit Criteria

Sprint 14.8.7 cannot be formally closed until:
1. WP001, WP002, and WP003 are merged to the mainline branch.
2. Automated security pipelines report 0 bypasses on the Property scope.
3. The Audit Log confirms generation of records for `JournalEntry` and `PurchaseOrder` mutations.
4. The WP005 assessment document is finalized and ready for Sprint 14.8.8 planning.

---

## Sprint 14.8.8 Preparation

* **Remaining Work:** Execution of the `company_id` to `tenant_id` refactoring (from WP005).
* **Dependencies:** WP005 must be thoroughly reviewed; any missed `company_id` references will break multi-tenant routing in 14.8.8.
* **Carry-over risks:** Disruption to frontend teams due to the upcoming terminology change in API payloads.

---

## Final Recommendation

To ensure the highest risk vectors are neutralized first without causing developer gridlock, the recommended execution sequence is:

1. **Execute WP001 (Remove Property Bypass) & WP002 (Session Revocation) concurrently.** These are localized changes with massive security returns and low cross-team dependencies.
2. **Execute WP003 (Critical Audit Coverage).** Roll this out iteratively. Start with Foundation (Users, Roles), then deploy to Finance, then Operations. 
3. **Execute WP004 (Approval Engine Compliance).** This is highly complex and requires coordination with domain teams. Start this mid-sprint once the foundation is stable.
4. **Execute WP005 (Refactor Assessment).** Assign a senior architect to work on this discovery parallel to the development of WP003 and WP004.

*Proceed with assigning work packages to engineering teams.*
