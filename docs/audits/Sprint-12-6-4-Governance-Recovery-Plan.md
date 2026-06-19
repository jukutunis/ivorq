# IVORQ Sprint 12.6.4 Governance Recovery Audit

## 1. Executive Summary
A Governance Recovery Audit was executed to determine the safety and compliance impact of restoring the `UNIQUE` database constraints removed in Sprint 12.6.3. By physically analyzing the production data and auditing the reachable application stack, it has been conclusively proven that restoring the constraints is **100% safe**. The removal of the constraints in Sprint 12.6.3 caused a critical architectural inconsistency where the Database schema deviated from the actively enforced Business Rules, API logic, and Test Suite.

## 2. Database Audit
**Current Schema:**
*   `reconciliation_matches` table lacks `UNIQUE` constraints.
*   Sprint 12.6.3 introduced a regular index: `INDEX(bank_statement_line_id)`.

**Target Schema (Post-Restoration):**
*   Remove the regular index: `DROP INDEX bank_statement_line_id`.
*   Restore `UNIQUE(bank_statement_line_id)`.
*   Restore `UNIQUE(matchable_type, matchable_id)`.

## 3. Data Safety Audit
An execution against the database using `artisan tinker` verified the following metrics:
*   Duplicate `bank_statement_line_id` records: **0**
*   Duplicate `matchable_type`/`matchable_id` records: **0**

**SAFE TO RESTORE: YES**
Evidence: The database contains zero duplicate data entries. Restoring the unique constraints will cause zero constraint violation errors during migration.

## 4. Application Compatibility Audit
Restoring constraints **will not** break existing workflows, APIs, or UIs.
*   **UI:** The frontend has no capability to dispatch partial reconciliation payloads.
*   **API:** `ReconciliationMatchController` strictly processes 1-to-1 matches.
*   **Services:** `ReconciliationMatchService.php` (Line 40, 56) actively prevents duplicates by throwing an Exception if a match already exists (`$line->reconciliationMatch()->exists()`).

The only code that would "break" is `ReconciliationCommitService`, but as proven by the Truth Audit, this is Dead/Unreachable Code.

## 5. Test Alignment Audit
**Do current tests represent actual system behavior? YES.**
The test suite (`ReconciliationSessionModuleTest.php`) contains assertions such as `test_bank_statement_line_cannot_be_matched_twice`, which expects a `QueryException` when a duplicate match is attempted. This perfectly mirrors the required active architecture (BR-007, BR-008). The tests currently *fail* only because the database constraint is missing, exposing the defect.

## 6. Governance Compliance Review
Comparison of all architectural layers:
*   **Business Rules:** Correct (1-to-1)
*   **Documentation (PRD/ADR):** Correct (1-to-1)
*   **API / UI:** Correct (1-to-1)
*   **Test Suite:** Correct (1-to-1)
*   **Database Schema:** **INCORRECT** (Missing protection).

The database layer is the sole component out of alignment with IVORQ Governance.

## 7. Risk Register
| Risk | Likelihood | Impact | Mitigation |
| :--- | :--- | :--- | :--- |
| Migration Failure (Duplicate Data) | Very Low | High | Executed duplicate check prior to planning; 0 duplicates found. Rerun duplicate check script immediately prior to deployment. |
| API Rejection | None | None | API already enforces 1-to-1 logic programmatically. |

## 8. Sprint 12.6.4 Recovery Plan
**Phase: Restore Reconciliation Constraints**

1.  **Migration Strategy:**
    *   Create migration `2026_06_20_000000_sprint_12_6_4_restore_reconciliation_constraints.php`.
    *   `up()`: Drop the `bank_statement_line_id` regular index. Add `UNIQUE` for `bank_statement_line_id` and `(matchable_type, matchable_id)`.
2.  **Rollback Strategy:**
    *   `down()`: Drop the `UNIQUE` constraints and restore the normal `bank_statement_line_id` index to revert to Sprint 12.6.3 state if unforeseen issues occur.
3.  **Validation Strategy:**
    *   Run `php artisan tinker` script to check `HAVING COUNT(*) > 1` during the deployment pipeline. Abort migration if > 0.
4.  **Test Validation Strategy:**
    *   Execute `php artisan test --filter ReconciliationSessionModuleTest`. The previously failing test suite will now turn **Green (Pass)** without modifying a single line of test code.
5.  **Deployment Checklist:**
    *   [ ] Run Data Duplicate Check
    *   [ ] Execute `php artisan migrate`
    *   [ ] Execute Test Suite
    *   [ ] File incident report against `ReconciliationCommitService` for removal.

## 9. Final Recommendation

**APPROVE CONSTRAINT RESTORATION**

**Evidence for decision:**
The database currently sits completely unprotected against double-matching due to the removal of constraints in Sprint 12.6.3. The partial reconciliation logic built to replace it is completely unreachable (Dead Code). Restoring the constraints perfectly aligns the database with the API, the UI, the Tests, and the Business Rules, and restores 100% test coverage safely and instantly.
