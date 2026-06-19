# IVORQ Architecture Governance Audit – Sprint 12.6.3

## 1. Executive Summary
A comprehensive architecture governance audit was conducted to assess the impact of restoring the `UNIQUE` database constraints removed during Sprint 12.6.3. The forensic analysis reveals that the removal of these constraints was **not a mistake**. Sprint 12.6.3 successfully implemented advanced enterprise accounting features, including **Partial Reconciliation**, **Split Settlements**, and **Consolidated Merging** via the newly introduced `ReconciliationCommitService`. 

However, this was executed as an "Unauthorized Architecture Deviation." The developer failed to update the underlying business rules (BR-007, BR-008), did not draft an Architecture Decision Record (ADR), and critically, **failed to update the test suite**, leaving stale tests that incorrectly assert the old 1-to-1 matching behavior. Restoring the unique constraints would fatally break the application's current matching logic.

## 2. Evidence Matrix

| Feature | Evidence Source | Evidence Details |
| :--- | :--- | :--- |
| **Partial Reconciliation** | `ReconciliationCommitService.php` (Line 41, 67) | Uses `$existingBankMatched = ReconciliationMatch::where(...)->sum('amount_matched');` to track fractional allocations. |
| **Split Settlement** | `ReconciliationCommitService.php` (Line 120) | `commitSplit()` method explicitly creates multiple match records for a single `bankLineId`. |
| **Consolidated Matching** | `ReconciliationCommitService.php` (Line 147) | `commitMerge()` method explicitly binds multiple bank statement lines to a single `matchable_id`. |
| **Missing ADR/PRD** | `docs/` repository search | No documentation exists authorizing the shift from 1-to-1 to many-to-many reconciliation. |
| **Stale Tests** | `ReconciliationSessionModuleTest.php` (Line 178) | Test `test_bank_statement_line_cannot_be_matched_twice` explicitly expects a `QueryException` (1-to-1). |

## 3. Dependency Analysis

The following files inherently depend on the new partial/many-to-many reconciliation logic and the `reconciliation_matches` schema lacking unique constraints:

**Services & Core Logic:**
*   `Modules\Finance\Banking\Services\ReconciliationCommitService.php` (The core engine for Split/Merge commits).
*   `Modules\Finance\Banking\Services\VarianceJournalingEngine.php`
*   `Modules\Finance\Banking\Services\ReconciliationMatchService.php`

**Controllers & DTOs:**
*   `Modules\Finance\Banking\Http\Controllers\ReconciliationMatchController.php`
*   `Modules\Finance\Banking\DTOs\MatchCandidateDTO.php`

**Tests (Currently Stale/Failing):**
*   `Tests\Feature\Finance\Banking\ReconciliationSessionModuleTest.php`
*   `Tests\Feature\Finance\Banking\AutoMatchingEngineModuleTest.php`
*   `Tests\Feature\Finance\Banking\VarianceJournalingEngineTest.php`
*   `Tests\Unit\Finance\Banking\MatchingFoundationTest.php`

## 4. Business Rule Analysis

*   **Documented Rules:** BR-007 (Bank statement line can only be matched once) and BR-008 (Payment voucher can only be reconciled once).
*   **Implemented Rules (Sprint 12.6.3):** A bank statement line can have multiple matches until `sum(amount_matched) == amount`. A payment voucher can have multiple matches until `sum(amount_matched) == total_amount`.
*   **Verdict:** The application code is more advanced than the documented business rules. The implementation aligns perfectly with enterprise accounting standards, but severely violates IVORQ governance by bypassing the PRD/ADR approval process.

## 5. Architecture Compliance Review

*   **Architecture Violations:** Implementation of a core domain shift without an approved ADR.
*   **Business Rule Violations:** Code actively violates BR-007 and BR-008.
*   **Data Integrity Risks:** Because database-level unique constraints were removed, the system now relies entirely on the application layer (`ReconciliationCommitService::commit1to1` pessimistic locking) to prevent over-allocation. The locking mechanism `lockForUpdate()` is correctly implemented, mitigating race conditions, but making the database schema "weaker."
*   **Future Upgrade Risks:** Auto-matching heuristics (Phase 2) will struggle if they are not explicitly designed to recommend partial/split matches.

## 6. Safety Assessment

**Can the following constraints be restored safely?**
*   `UNIQUE(bank_statement_line_id)`
*   `UNIQUE(matchable_type, matchable_id)`

**SAFE TO RESTORE: NO**

**Supporting Evidence:**
Restoring the unique constraints will immediately break the `commitSplit()` and `commitMerge()` functions in `ReconciliationCommitService.php`. These functions purposefully create duplicate entries for `bank_statement_line_id` and `matchable_id` respectively to represent fractional allocations. A `QueryException` would be thrown in production for any user attempting a split settlement.

## 7. Sprint 12.6.4 Implementation Plan

Because `SAFE TO RESTORE = NO`, the corrective action is not to restore the database constraints, but to **legalize** the architecture deviation and fix the stale tests.

**Sprint 12.6.4: Legalize Partial Reconciliation & Fix Test Suite**
*   **Documentation Strategy:** Draft and approve `ADR-006-Partial-Reconciliation.md`. Update BR-007 and BR-008 to reflect sum-based matching.
*   **Test Validation Strategy:** 
    *   Delete `test_bank_statement_line_cannot_be_matched_twice`.
    *   Delete `test_payment_voucher_cannot_be_reconciled_twice`.
    *   Create `test_reconciliation_commit_prevents_overallocation_on_split()`.
    *   Create `test_reconciliation_commit_prevents_overallocation_on_merge()`.
*   **Deployment Risk Assessment:** Low. Code is already deployed in DB; only tests and docs are being updated.

## 8. Risk Register

| Risk | Likelihood | Impact | Mitigation |
| :--- | :--- | :--- | :--- |
| Over-allocation due to race conditions | Low | High | `lockForUpdate()` is currently active in `ReconciliationCommitService`. Must ensure no other service bypasses this class to insert matches directly. |
| Auto-matching engine recommends over-allocation | Medium | Medium | Auto-matching must query `sum(amount_matched)` instead of assuming a line is fully open if `is_reconciled = false`. |

## 9. Final Recommendation

**Do NOT restore the database constraints.** 
The code written in Sprint 12.6.3 is highly valuable and necessary for an Enterprise SaaS. The developer implemented a robust partial reconciliation engine using pessimistic locking. The failure is purely procedural (governance). 

**Recommendation:** Approve Sprint 12.6.4 to draft the missing ADR, update the Business Rules, and rewrite the failing test cases to validate over-allocation prevention rather than strict unique insertions.
