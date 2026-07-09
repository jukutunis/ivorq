# IVORQ Regression Baseline Debt

**Status:** Active
**Version:** 1.0
**Created:** 2026-07-09
**Branch:** `sprint-validation-baseline-governance`

---

## Purpose

This document records all acknowledged baseline validation debt. Each entry identifies the debt, the domain, the root cause, why it is not a defect in the current delivery, and what action the owner must take in a future package.

Debt is not "pre-existing" by default — each entry must be proven or explicitly marked as requiring owner verification.

---

## Debt Entry 1: Inventory/AVCO/Sensitive Legacy Command Not Recorded

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-LEGACY-INVENTORY-AVCO-SENSITIVE-COMMAND` |
| **Domain** | Inventory / AVCO / Sensitive |
| **Severity** | Governance (not runtime) |
| **Legacy Reference** | "247 tests / 927 assertions" |
| **Exact Command** | NOT RECORDED — `LEGACY_EVIDENCE_COMMAND_UNDISCOVERABLE` |
| **Status** | Acknowledged — v2 candidate baseline constructed |

**What happened:** Prior completion reports and ADRs (ADR-084, FD-B3 context) referenced an "Inventory/AVCO/Sensitive baseline" with 247 tests and 927 assertions. No exact `phpunit` command, class list, filter argument, or manifest was committed to the repository.

**Why this matters:** Without the exact command, the 247/927 count cannot be reproduced, verified, or audited. It is not a baseline — it is a historical note.

**What was done:** A v2 candidate baseline (`inventory-avco-sensitive-baseline-v2-candidate`) was constructed with exact test classes covering the Inventory, AVCO CostControl, and Sensitive Action Confirmation domains. This candidate is in `scripts/validation/ivorq-regression-baselines.json`.

**What this is not:** This is not a bug in any application module. The tests themselves pass. The debt is purely governance: the command used to count them was never written down.

**Owner action needed:**
1. Review the v2 candidate class list.
2. Confirm which classes belong in the Inventory/AVCO/Sensitive baseline.
3. Promote the candidate to `active` or adjust the class list.

---

## Debt Entry 2: Banking Master Legacy Command Not Recorded

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-LEGACY-BANKING-MASTER-COMMAND` |
| **Domain** | Banking / Finance Master |
| **Severity** | Governance (not runtime) |
| **Legacy Reference** | "194 tests, 0 failures, 0 errors" |
| **Exact Command** | NOT RECORDED — `LEGACY_EVIDENCE_COMMAND_UNDISCOVERABLE` |
| **Status** | Acknowledged — v2 candidate baseline constructed |

**What happened:** Prior completion reports (Sprint-FD-A2, Sprint-FD-A3) and ADR-080 referenced a "Full Banking/Finance Master Regression" with 194 tests. No exact `phpunit` command, class list, or manifest was committed.

**Why this matters:** Without the exact command, it is not known which 194 tests were included. A broad `--filter Banking` today selects a different count because Banking migration tests have been added since the original count was recorded.

**What was done:** A v2 candidate baseline (`banking-master-baseline-v2-candidate`) was constructed with exact core Banking test classes only. Banking migration tests are excluded (see Debt Entry 4).

**Owner action needed:**
1. Review the v2 candidate class list.
2. Confirm which classes constitute "Banking master" vs. migration/deferred.
3. Promote the candidate to `active` or adjust the class list.

---

## Debt Entry 3: Broad Banking Filter Selects Non-Master Migration Tests

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-BANKING-BROAD-FILTER-MIGRATION-LEAK` |
| **Domain** | Banking |
| **Severity** | Governance (not runtime) |
| **Status** | Acknowledged — excluded from v2 candidate |

**What happened:** A broad `--filter Banking` PHPUnit filter matches all 20 files under `tests/Postgres/Finance/Banking/`. This includes 10 Banking migration test classes (e.g., `BankingMigrationExecutionPreconditionsTest`, `BankingMigrationDryRunTest`) that are not part of the core Banking master operational baseline.

**Why this matters:** The migration tests model a deferred migration workflow. Their presence in a "master" baseline is incorrect — they are not master operational tests, they are migration execution tests for a future migration package.

**What was done:** The v2 candidate baseline explicitly includes only core Banking operational test classes. Migration tests are excluded from the baseline. See Debt Entry 4 for their known issues.

**Owner action needed:** Confirm that Banking migration tests should remain excluded from the master baseline.

---

## Debt Entry 4: BankingMigrationExecutionPreconditionsTest Status-String Drift

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-BANKING-MIGRATION-PRECONDITIONS-STATUS-DRIFT` |
| **Domain** | Banking / Migration |
| **Severity** | Test maintenance (not runtime defect) |
| **Status** | Acknowledged — excluded from v2 candidate, documented for future package |

**What happened:** `BankingMigrationExecutionPreconditionsTest` asserts specific status strings that may drift as the migration domain model evolves. This is a test maintenance concern, not a Banking module defect.

**Why this is not an FD-B3 defect:** FD-B3 is a Front Desk departure operational handover package. It does not modify Banking migration code, status strings, or test expectations.

**What a future package must not do:** Call this "pre-existing" without proving the drift is not caused by that package's own changes. Each package must verify that migration test status assertions still match the committed domain model at the time of its delivery.

**Owner action needed:** When the Banking migration package is implemented, reconcile status-string expectations with the final domain model.

---

## Debt Entry 5: ConfirmedBankPaymentLifecycleTest Business-Date Setup Gap

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-BANKING-CONFIRMED-PAYMENT-LIFECYCLE-BUSINESS-DATE` |
| **Domain** | Banking |
| **Severity** | Test setup (not runtime defect) |
| **Status** | Acknowledged — excluded from v2 candidate, documented for future package |

**What happened:** `ConfirmedBankPaymentLifecycleTest` has a known business-date setup gap. The test may fail in environments where the business date has not been properly initialized for the test property.

**Why this is not an FD-B3 defect:** FD-B3 is a Front Desk departure package. It does not modify Banking payment lifecycle code, business date initialization, or test setup.

**What a future package must not do:** Call this "pre-existing" without verifying that the business-date setup gap is still present and not introduced by the current package's changes.

**Owner action needed:** When Banking payment lifecycle is next modified, fix the business-date setup in `ConfirmedBankPaymentLifecycleTest`.

---

## Debt Entry 6: Inventory Reversal Inherited Trigger-Related Errors

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-INVENTORY-REVERSAL-TRIGGER-ERRORS` |
| **Domain** | Inventory / Reversal |
| **Severity** | Known inherited test debt |
| **Status** | Active — tracked in baseline `inventory-reversal-inherited-debt-v1` |

**What happened:** `InventoryReversalWorkspaceTest` has 8 tests and 72 assertions, with 0 failures and 2 inherited errors. The errors are trigger-related and documented in ADR-080 and Sprint-FD-A3 completion report.

**Expected:** 8 tests, 72 assertions, 0 failures, 2 errors.

**Why this is not an FD-B3 defect:** FD-B3 does not modify inventory reversal triggers or the reversal workspace. The errors exist on the accepted default branch `ivorq-enterprise-core` and pre-date FD-B3.

**What a future package must not do:** Call this "pre-existing" without exact proof. The baseline runner verifies the exact 8/72/0/2 count. Any deviation from this count is a regression that must be investigated.

**Owner action needed:** A future Inventory package should resolve the root cause of the 2 inherited trigger errors.

---

## Related Documents

---

## Debt Entry 7: Inventory/AVCO/Sensitive RefreshDatabase Batch Execution Conflicts

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-INVENTORY-AVCO-SENSITIVE-BATCH-REFRESH-CONFLICT` |
| **Domain** | Inventory / AVCO / CostControl |
| **Severity** | Test infrastructure (not runtime defect) |
| **Status** | Acknowledged — documented in manifest, individual execution required |

**What happened:** Running all 51 Inventory/AVCO/Sensitive test classes in a single PHPUnit batch invocation produces 217+ errors from RefreshDatabase cross-class seed state interference. 48 of the 51 classes use `RefreshDatabase` with `$seed = true`. When run in batch mode, later test classes encounter `Cannot assign null to property ...::$property of type Property` because the seed data state is not properly restored after earlier RefreshDatabase cycles.

**Why this matters:** The legacy 247/927 baseline was likely achieved by running a broad filter that selected a specific subset of tests at a point in time. The exact subset and its interaction with RefreshDatabase cannot be reproduced.

**What was done:** The v2 candidate baseline (`inventory-avco-sensitive-baseline-v2-candidate`) is marked with `execution_mode: individual`. Each test class must be run independently. The Sensitive/Foundation subgroup (3 classes: SensitiveActionConfirmationTest, FinanceApprovalConfirmationTest, FinanceFinalizationConfirmationTest) passes cleanly in batch at 58 tests / 436 assertions.

**Why this is not an FD-B3 defect:** FD-B3 does not modify any Inventory, AVCO, CostControl, or test infrastructure code.

**Owner action needed:**
1. Approve individual-execution mode for this baseline, OR
2. Investigate and fix RefreshDatabase seed interaction across Inventory/CostControl test classes, OR
3. Split into domain sub-baselines (Inventory, CostControl, Sensitive) that can run independently.

---

## Debt Entry 8: ConfirmedBankPaymentLifecycleTest Business-Date Setup Gap Confirmed

| Field | Value |
|-------|-------|
| **Debt ID** | `DEBT-BANKING-CONFIRMED-PAYMENT-LIFECYCLE-BUSINESS-DATE-CONFIRMED` |
| **Domain** | Banking |
| **Severity** | Test setup (not runtime defect) |
| **Status** | Confirmed — excluded from v2 candidate banking master baseline |

**What happened:** Running the banking-master-baseline-v2-candidate with `ConfirmedBankPaymentLifecycleTest` included produces 1 error: "Posting rejected: PropertyBusinessDate not found for property=... date=2026-07-09." The test does not properly initialize a `PropertyBusinessDate` before attempting to post a payment.

**Why this is not an FD-B3 defect:** FD-B3 does not modify Banking payment lifecycle code or test setup.

**What was done:** `ConfirmedBankPaymentLifecycleTest` is excluded from the `banking-master-baseline-v2-candidate` class list. It is documented as accepted debt. The remaining 9 core Banking test classes pass cleanly in batch at 83 tests / 595 assertions / 0 failures / 0 errors.

**Owner action needed:** When Banking payment lifecycle is next modified, fix the business-date setup in `ConfirmedBankPaymentLifecycleTest` and add it back to the master baseline.

---

## Related Documents

- [IVORQ-Regression-Baseline-Registry.md](IVORQ-Regression-Baseline-Registry.md) — Baseline registry and policy
- [ivorq-regression-baselines.json](../../scripts/validation/ivorq-regression-baselines.json) — Machine-readable baseline manifest
