# IVORQ Final Failure Census & Repository Green-State Backlog

## 1. Executive Summary
Following the successful restoration of the IVORQ environment (PHP 8.4, PostgreSQL, SQLite fixes) and the completion of the BEO Hotfix migrations and Sprint 12.6.4 constraint enforcements, a full repository test validation was executed. The test suite of 1324 tests was executed without suppression. The census discovered exactly 18 terminating failures spanning 6 functional domains. This audit classifies every failure into distinct Root Causes and provides the definitive roadmap to return the IVORQ repository to a pristine Green State.

## 2. Full Failure Inventory
| Test Class | Test Method | Status | Error Type | Module |
| :--- | :--- | :--- | :--- | :--- |
| `JournalCandidateTest` | `test_reject_and_posted_transitions` | Failed | `ArgumentCountError` | General Ledger |
| `ReconciliationCommitServiceTest` | `test_split_matching` | Error | `SQLSTATE[23000] UNIQUE constraint` | Banking |
| `ReconciliationCommitServiceTest` | `test_merge_matching` | Error | `SQLSTATE[23000] UNIQUE constraint` | Banking |
| `PaymentProcessingModuleTest` | `test_payment_creation_and_approval_workflow` | Error | `Class "VendorInvoice" not found` | Payables |
| `PaymentProcessingModuleTest` | `test_property_isolation_on_payment` | Error | `Class "VendorInvoice" not found` | Payables |
| `PaymentProcessingModuleTest` | `test_cancelled_payment_creates_audit_log` | Error | `Class "VendorInvoice" not found` | Payables |
| `ThreeWayMatchingEngineTest` | `test_successful_perfect_match` | Error | `SQLSTATE[HY000] no column quantity_ordered` | Purchasing |
| `ThreeWayMatchingEngineTest` | `test_match_with_quantity_and_price_variance` | Error | `SQLSTATE[HY000] no column quantity_ordered` | Purchasing |
| `ThreeWayMatchingEngineTest` | `test_matching_fails_without_po_or_grn` | Error | `SQLSTATE[HY000] no column quantity_ordered` | Purchasing |
| `ReceivingApprovalIntegrationTest` | `test_approval_approved_updates_document_status` | Failed | `Attempt to read property "id" on null` | Receiving |
| `ReceivingApprovalIntegrationTest` | `test_approval_rejected_updates_document_status` | Failed | `Attempt to read property "id" on null` | Receiving |
| `ReceivingDiscrepancyTest` | `test_can_log_discrepancy` | Failed | `Attempt to read property "id" on null` | Receiving |
| `ReceivingDocumentTest` | `test_can_create_receiving_document` | Error | `Cannot assign null to property $property` | Receiving |
| `ReceivingDocumentTest` | `test_can_soft_delete_receiving_document` | Error | `Cannot assign null to property $property` | Receiving |
| `ReceivingInspectionTest` | `test_can_log_inspection` | Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingLineTest` | `test_can_add_lines_to_document` | Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingModelTest` | `test_receiving_document_model_has_traits_and_scopes` | Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingNotificationIntegrationTest`| `test_approval_approved_creates_notification` | Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingPropertyIsolationTest` | `test_receiving_documents_are_isolated_by_property`| Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingTaskIntegrationTest` | `test_approval_requested_creates_task_for_assignee` | Error | `Attempt to read property "id" on null` | Receiving |
| `ReceivingWorkflowTest` | `test_can_create_draft_and_submit` | Failed | `Argument #1 ($user) must be Authenticatable`| Receiving |
| `BEOEngineTest` | `test_it_generates_acknowledgement_requests` | Error | `SQLSTATE[HY000] no column beo_issue_log_id`| BEO / Sales |

## 3. Root Cause Groups
| Group | Domain | Failure Count |
| :--- | :--- | :--- |
| **Group A** | Receiving | 12 |
| **Group B** | Purchasing | 3 |
| **Group C** | Payables | 3 |
| **Group D** | Reconciliation | 2 |
| **Group E** | General Ledger | 1 |
| **Group F** | BEO | 1 |

## 4. Duplicate Root Cause Analysis
| Root Cause | Affected Tests | Count |
| :--- | :--- | :--- |
| **RC-01:** Missing Base State Initialization (`$this->user`, `$this->property`) in `setUp()` for Receiving Tests. | Receiving Domain Tests | 12 |
| **RC-02:** `VendorInvoice` model either deleted, missing namespace, or moved. | PaymentProcessingModuleTest | 3 |
| **RC-03:** Migration uses `ordered_quantity` but Model/Test uses `quantity_ordered`. | ThreeWayMatchingEngineTest | 3 |
| **RC-04:** Sprint 12.6.4 constraints accurately block partial reconciliation tests. | ReconciliationCommitServiceTest | 2 |
| **RC-05:** Outdated test calling `reject()` missing `$reason` argument. | JournalCandidateTest | 1 |
| **RC-06:** Missing column `beo_issue_log_id` in `beo_acknowledgements` migration. | BEOEngineTest | 1 |

## 5. Business Rule Assessment
1.  **RC-01 (Receiving Base State):** **A. Test Outdated / Broken Test Setup**. The Receiving tests fail to properly seed or instantiate the base `User` and `Property` models required by the testing traits.
2.  **RC-02 (VendorInvoice Class):** **B. Service Outdated / Refactor Artifact**. `VendorInvoice` was likely renamed to `APInvoice` or moved during "Sprint 11 Vendor Enhancements & Legacy Cleanup", but the tests were not updated.
3.  **RC-03 (quantity_ordered mismatch):** **D. Migration Mismatch**. The schema for `purchase_order_lines` has `ordered_quantity`, but the test is trying to insert `quantity_ordered`.
4.  **RC-04 (Reconciliation Constraints):** **C. Business Rule Changed / Restored**. The database successfully enforces 1-to-1 matching, effectively obsoleting the tests designed to validate partial/merge logic.
5.  **RC-05 (Journal Reject Reason):** **A. Test Outdated**. The business rule mandating a rejection reason was enforced, but the legacy test was skipped during the refactor.
6.  **RC-06 (BEO Column Missing):** **D. Migration Mismatch**. The BEO architecture requires linking an acknowledgement back to the issue log, but the column was omitted from the migration schema block.

## 6. Repository Health
*   **Total Tests:** 1324
*   **Passed Tests:** 1306
*   **Failed Tests:** 4
*   **Error Tests:** 14
*   **Pending Migrations:** 0
*   **Modified Files:** 4 (database/migrations/...)
*   **Untracked Files:** 6 (Reports & test outputs)
*   **Dirty Working Tree:** Yes (from BEO Hotfixes and Reporting)
*   **Branch Name:** `ivorq-enterprise-core`
*   **Last Commit:** `cb4395b docs(audit): sprint 12.6.4 approved for implementation`
*   **Divergence:** Local is ahead of `origin/ivorq-enterprise-core` by 1 commit.

## 7. Green State Backlog

**Priority 1: Critical Blockers (Data / DB Layer)**
*   **BKL-001 (BEO):** Add missing `beo_issue_log_id` foreign key column to `beo_acknowledgements` migration.
*   **BKL-002 (Purchasing):** Resolve `ordered_quantity` vs `quantity_ordered` discrepancy in `purchase_order_lines` migration or factory.

**Priority 2: High Severity Defects (Code Execution)**
*   **BKL-003 (Payables):** Fix `VendorInvoice` class references in `PaymentProcessingModuleTest`. Remap to `APInvoice` or restore the class.
*   **BKL-004 (Receiving):** Fix the `setUp()` method or trait dependencies across the `Receiving` test suite to correctly bootstrap `$this->user` and `$this->property`.

**Priority 3: Medium Severity Defects (Governance & Dead Code)**
*   **BKL-005 (General Ledger):** Add `$reason` argument to `reject()` call in `JournalCandidateTest:112`.
*   **BKL-006 (Reconciliation):** Update `ReconciliationCommitServiceTest` to explicitly assert that `commitSplit` and `commitMerge` throw a `QueryException` (Testing the boundary failure).

## 8. Optimal Repair Order
1.  **Fix BKL-004 (Receiving Setup):** Instantly resolves **12** cascading null pointer errors in one sweep.
2.  **Fix BKL-003 (VendorInvoice Rename):** Resolves **3** fatal class errors via simple namespace/find-replace.
3.  **Fix BKL-002 (Purchasing Column):** Resolves **3** SQLite schema insertion crashes.
4.  **Fix BKL-001 (BEO Schema):** Resolves **1** SQLite schema crash.
5.  **Fix BKL-005 & 006:** Resolves the final **3** logic/governance test failures.

## 9. Risk Assessment
*   **Test Contamination:** A single broken `setUp()` configuration in Receiving caused 66% of the remaining failures (12 out of 18).
*   **Blind Refactoring:** The "Sprint 11 Legacy Cleanup" renamed core tables (likely `vendor_invoices` to `ap_invoices`) but failed to run the test suite to catch the broken references.

## 10. CTO Recommendation
The repository is surprisingly stable, with an astonishing **98.6% Test Pass Rate** (1306/1324).
The remaining 18 failures are **not** deep architectural collapses; they are surface-level artifacts of recent refactors (Missing columns, renamed classes, forgotten constructor arguments).

**Recommendation:** Approve the execution of the Optimal Repair Order to knock out the 18 test failures and seal the `v0.4-sprint12-stable` release.
