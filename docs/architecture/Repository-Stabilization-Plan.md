# IVORQ Repository Stabilization Plan & General Ledger RCA

## 1. Executive Summary
During the execution of the IVORQ Sprint 12.6.4 Final Validation, the `php artisan test --stop-on-failure` command successfully validated the newly restored PostgreSQL constraints (proving 1-to-1 matching enforcement) but subsequently halted on an unrelated source code defect in the General Ledger module. Per IVORQ Governance, a forensic Root Cause Analysis (RCA) was conducted on the `JournalCandidateService` failure. This document presents the complete RCA, business rule justification, and the roadmap to return the IVORQ Repository to a Green State.

## 2. General Ledger Failure Analysis
**Target:** `Modules\Finance\GeneralLedger\Services\JournalCandidateService::reject()`
**Error:** `ArgumentCountError: Too few arguments to function ...reject(), 1 passed ... and at least 2 expected`

**Current Method Signature:**
```php
public function reject(string $id, string $reason, ?string $userId = null): JournalCandidate
```
*   **Required parameters:** `string $id`, `string $reason`
*   **Optional parameters:** `?string $userId = null`
*   **Return Type:** `JournalCandidate`

## 3. Git History Audit
**Target:** `JournalCandidateService.php`

**Commit:** `b536f279e8f9d09cbdb3e8a23e810142fa1a2a28`
**Author:** I Gede Edie Saputra <edigd11@yahoo.co.id>
**Date:** Mon Jun 15 06:10:57 2026 +0800
**Message:** `feat(finance): implement financial governance`

**Changes Introduced:**
*   Added `string $reason` parameter.
*   Added validation: `if (empty(trim($reason))) { throw ValidationException; }`
*   Modified state update to explicitly log `rejected_by`, `rejected_at`, and `rejection_reason`.

**Business Reason Extracted from Git:** To enforce financial governance by immutably recording *why* a journal candidate was rejected and *who* rejected it.

## 4. Call Site Audit
Repository search for `->reject(` identified 9 active occurrences:

| File | Line | Context | Argument Count | Classification |
| :--- | :--- | :--- | :--- | :--- |
| `JournalCandidateGovernanceTest.php` | 116 | `$this->service->reject($candidate->id, $reason)` | 2 | **Correct** |
| `JournalCandidateGovernanceTest.php` | 129 | `$this->service->reject($candidate->id, "   ")` | 2 | **Correct** |
| `JournalCandidateTest.php` | 112 | `$this->service->reject($posted->id)` | 1 | **Broken** |

*(Note: Other occurrences belong to `AssetRequestService`, `PurchaseRequestService`, `ApprovalEngineService`, and `AcknowledgementEngine`, which correctly pass 2-3 arguments including reasons/notes).*

## 5. Business Rule Audit
**Target Search:** "rejection" across `/docs/`
**Evidence Found:**
*   `ADR-003 (Approval Engine Architecture)`: "Requires all state changes via approvals, rejections, and overrides to be immutably recorded."
*   `docs/decisions/ADR-002-Audit-Trail-Strategy.md`: "Approval Workflows: Request submissions, approvals, rejections, escalations."
*   `docs/inventory/inventory-database.md`: Validates `rejection_reason` schema presence globally.

**Conclusion:** Financial Governance explicitly requires the rejection reason to satisfy the Audit Trail and Approval Architecture requirements. The parameter is mandated by the business rules.

## 6. Root Cause Classification
**Classification: A. Outdated Test**
**Evidence:**
1. The `JournalCandidateService::reject()` method was intentionally updated in Commit `b536f279` to enforce `ADR-002` and `ADR-003` governance requirements by requiring a `$reason` parameter.
2. The developer correctly updated the newer `JournalCandidateGovernanceTest` to supply the parameter.
3. The developer **failed** to update the legacy `JournalCandidateTest.php` on line 112, leaving it passing only 1 argument. The business logic is correct; the test is obsolete and broken.

## 7. Repository Health Report
*   **Total Tests:** 1324
*   **Passed:** 1306
*   **Failed:** 4
*   **Errors:** 14
*   **Skipped:** 1
*   **Migration Status:** 100% Up to Date. All 109 migrations successfully `Ran`.
*   **Pending Migrations:** 0
*   **Modified Files:** 4 (BEO Hotfix migrations)
*   **Untracked Files:** 4 (RCA & Implementation Reports + 12.6.4 Migration)

## 8. Stabilization Roadmap
**Phase 1: General Ledger Recovery**
*   **Task:** Update `tests/Feature/Finance/GeneralLedger/JournalCandidateTest.php` line 112.
*   **Action:** Provide the required `$reason` argument: `$this->service->reject($posted->id, 'Test rejection reason');`.

**Phase 2: Test Suite Recovery**
*   **Task:** Resolve the expected constraint violations for Sprint 12.6.4.
*   **Action:** Update `test_split_matching` and `test_merge_matching` in `ReconciliationCommitServiceTest` to explicitly assert that a `QueryException` (Constraint Violation) is thrown, rather than allowing the test to crash.

**Phase 3: Repository Green State**
*   **Task:** Run full `php artisan test` and guarantee 100% PASS rate.
*   **Action:** Commit the BEO hotfixes, the 12.6.4 constraints, and the test suite repairs under a unified stabilization commit.

**Phase 4: Release Tag Preparation**
*   **Task:** Issue `v0.4-sprint12-stable`.
*   **Action:** Merge `ivorq-enterprise-core` to `main`.

**Phase 5: Cost Control Readiness**
*   **Task:** Unblock the upcoming V0.5 Cost Control / WAC Contamination recovery.
*   **Action:** Transition focus to the corrupted `InventoryTransaction` records as outlined in `wac-contamination-assessment.md`.

## 9. Risk Assessment
*   **Governance Failure:** Developers are bypassing test updates when altering Service signatures.
*   **CI Pipeline Risk:** The SQLite in-memory database used by PHPUnit is not capturing PostgreSQL constraint errors, meaning local tests pass while production/deployments fail.

## 10. Final Recommendation
1.  **Approval to Execute:** Request authorization to execute Phase 1 & 2 of the Stabilization Roadmap.
2.  **Test Suite Switch:** Transition the local CI/testing environment to use a dedicated PostgreSQL testing database instead of SQLite memory.
