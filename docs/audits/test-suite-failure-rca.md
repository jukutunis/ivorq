# Test Suite Failure Forensic Root Cause Analysis

## 1. Executive Summary

A forensic investigation was conducted to determine the root causes of the IVORQ Enterprise Platform test suite failures. 
The investigation occurred in two phases:
1. **Phase 1 (Environment Error)**: 967 tests initially failed due to a missing SQLite driver in the PHP environment. This prevented the in-memory database from booting.
2. **Phase 2 (Source Code Defect)**: After remediating the PHP environment, the test suite revealed a new failure. 1 test failed (`test_bank_statement_line_cannot_be_matched_twice`) because it expected a database `QueryException` that was never thrown. 

The current root cause is a synchronization defect between a recent database migration (Sprint 12.6.3) which intentionally dropped database-level unique constraints, and the test suite which still expects those constraints to enforce 1-to-1 reconciliation matching.

## 2. Root Cause Analysis

### Phase 1 — Environment Failure (Resolved)
- **First Failure**: `PDOException` (could not find driver) across all feature tests.
- **Root Cause**: The `pdo_sqlite` and `sqlite3` extensions were disabled in the `php.ini` of PHP 8.4.22.
- **Resolution**: Enabled the extensions in `php.ini` and cleared the application cache.

### Phase 2 — Source Code Defect (Current)
The test suite was re-run, resulting in: 413 tests executed, 412 passed, 1 failed.
- **First Failure**: `Tests\Feature\Finance\Banking\ReconciliationSessionModuleTest::test_bank_statement_line_cannot_be_matched_twice`
- **Failing Line**: 178
- **Error Message**: `Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown.`
- **WHAT changed**: A migration `2026_06_15_150048_sprint_12_6_3_add_audit_fields_to_reconciliation_matches.php` was introduced, which drops the unique constraints on the `reconciliation_matches` table.
- **WHY it failed**: The test explicitly asserts that a `QueryException` will be thrown when attempting to insert duplicate reconciliation matches (testing the database's unique constraint). Because the migration dropped these constraints, the database accepts the duplicates and no exception is thrown, causing the test assertion to fail.
- **WHICH module introduced the failure**: Finance/Banking Module (Migration vs Test Suite mismatch).

## 3. Evidence

### Finding 1: Database Constraints Dropped in Migration
**Description**: The migration introduced in sprint 12.6.3 explicitly drops the unique constraints `reconciliation_matches_bank_statement_line_id_unique` and `unique_reconciled_matchable`.
**File**: `database/migrations/2026_06_15_150048_sprint_12_6_3_add_audit_fields_to_reconciliation_matches.php`
**Line**: 12-13
**Code**:
```php
Schema::table('reconciliation_matches', function (Blueprint $table) {
    $table->dropUnique('reconciliation_matches_bank_statement_line_id_unique');
    $table->dropUnique('unique_reconciled_matchable');
```

### Finding 2: Test Suite Expects Database Constraint
**Description**: The tests `test_bank_statement_line_cannot_be_matched_twice` and `test_payment_voucher_cannot_be_reconciled_twice` expect the database to reject duplicate insertions by throwing a `QueryException`.
**File**: `tests/Feature/Finance/Banking/ReconciliationSessionModuleTest.php`
**Line**: 194 and 224
**Code**:
```php
$this->expectException(\Illuminate\Database\QueryException::class);
```

## 4. Impact Assessment

### Blast Radius Analysis
- **Group A (Root Cause)**: Dropped database constraints in migration without updating tests/business logic.
- **Group B (Cascading Failures)**: `test_bank_statement_line_cannot_be_matched_twice` and potentially `test_payment_voucher_cannot_be_reconciled_twice`.
- **Group C (Independent Failures)**: None currently observed.

**Severity**: **High**. This is a data integrity risk. If the business rule still dictates 1-to-1 matching, dropping the database constraints allows race conditions to create duplicate matches. If the business rule changed to allow 1-to-many matching (e.g., partial reconciliations), then the tests are stale and misrepresent the current business rules.

## 5. Remediation Plan

Because this is a source code defect involving business logic and data integrity, clarification on business requirements is needed before modifying the code.

**Option A (If 1-to-1 matching is still required)**:
The migration `2026_06_15_150048_sprint_12_6_3...` must be corrected to NOT drop the unique constraints.
1. Remove `dropUnique` lines from the migration.
2. If already deployed, create a new migration to restore the unique constraints.

**Option B (If 1-to-many matching is the new requirement)**:
The tests in `ReconciliationSessionModuleTest.php` are stale and must be updated to reflect the new business logic.
1. Remove `test_bank_statement_line_cannot_be_matched_twice`.
2. Remove `test_payment_voucher_cannot_be_reconciled_twice`.
3. Add new tests that validate the logic for 1-to-many reconciliation matching.

## 6. Validation Checklist

- [x] PHP Environment resolved (SQLite extensions enabled).
- [x] Phase 2 Root Cause identified (Migration vs Test Mismatch).
- [ ] Business rule clarified (1-to-1 vs 1-to-many).
- [ ] Source code corrected based on business rule.
- [ ] Full test suite execution passes.
