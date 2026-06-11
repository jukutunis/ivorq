# Month-End Closing Foundation Implementation Plan

## 1. Architecture Review
The Month-End Closing module resides within `Modules/Finance/GeneralLedger`, but acts as a global financial gatekeeper. 
Once a `FinancialPeriod` is closed, the system must immutably freeze historical data across multiple modules:
- **General Ledger:** No new `gl_journal_entries`, `gl_journal_entry_lines`, or `gl_ledger_balances` updates for that period.
- **Accounts Payable:** No creating, approving, or posting of `AccountPayable` or `PaymentVoucher` records that fall within the closed period.
- **Banking:** No new `BankStatement` imports, modifications, or `ReconciliationSession` activities for dates within the closed period.
- **Reporting:** Trial Balance, Profit & Loss, Balance Sheet, and Cash Flow must remain permanently identical to the moment the period was closed.

## 2. FinancialPeriod Design
**Model:** `FinancialPeriod`
**Fields:**
- `id` (ULID)
- `property_id` (ULID, Foreign Key)
- `period_year` (Integer)
- `period_month` (Integer, 1-12)
- `status` (Enum: `Open`, `Closing`, `Closed`, `Reopened`)
- `opened_at` (Timestamp, nullable)
- `closed_at` (Timestamp, nullable)
- `opened_by` (ULID, Foreign Key to User, nullable)
- `closed_by` (ULID, Foreign Key to User, nullable)
- Audit columns (`created_by`, `updated_by`, timestamps)

**Unique Constraint:** `property_id`, `period_year`, `period_month`.

## 3. Business Rules
- **BR-001:** Only one `FinancialPeriod` may exist per property, per month.
- **BR-002:** Only `Open` periods allow financial postings across GL, AP, and Banking.
- **BR-003:** `Closed` periods reject all new postings system-wide.
- **BR-004:** `Closed` periods reject any modification to AP documents (Invoices, Vouchers) mapped to that period.
- **BR-005:** `Closed` periods reject any modification to Bank Statements or Reconciliations mapped to that period.
- **BR-006:** Month-End Closing is strictly isolated by `property_id`.
- **BR-007:** Closing process must calculate and validate that the Trial Balance is perfectly balanced (Debits = Credits).
- **BR-008:** Closing process must validate the Balance Sheet (Assets = Liabilities + Equity).
- **BR-009:** Closing process must validate the Cash Flow statement (Opening + Change = Closing).
- **BR-010:** Closing transitions are strictly auditable.
- **BR-011:** `Reopened` state creates a permanent audit trail tracking the justification and the user.

## 4. Pre-Close Validation (Mandatory Gateways)
Before a period transitions from `Closing` to `Closed`, the system must assert:
1. **Unposted Journals:** Zero `Draft` or `Pending` journal entries exist for the period.
2. **Open Reconciliations:** Zero `Draft` or `In Progress` reconciliation sessions exist.
3. **Unreconciled Bank Statements:** All imported bank statements for the period are fully reconciled.
4. **Draft AP Documents:** Zero unposted/draft vendor invoices exist for the period.
5. **Draft Payment Vouchers:** Zero unposted/draft payment vouchers exist.
6. **Accounting Integrity:** Trial Balance, Balance Sheet, and Cash Flow statements return `balanced = true`.

## 5. Locking Strategy
To guarantee enterprise-grade data protection, the lock must be enforced natively at the **Service Layer**.
- **`PeriodControlService`:** A centralized gatekeeper injected into `GeneralLedgerService`, `SubledgerPostingService`, `AccountPayableService`, and `BankReconciliationService`.
- **Mechanism:** Before any Create/Update/Delete operation that mutates financial data, the respective service queries `PeriodControlService::isOpen($propertyId, $year, $month)`. If false, a `PeriodClosedException` is thrown, instantly halting the database transaction.

## 6. Audit & Security Design
**Permissions:**
- `generalledger.period.view`
- `generalledger.period.manage`
- `generalledger.period.close` (Restricted to Controller/CFO level)
- `generalledger.period.reopen` (Restricted to high-level Admin/CFO)

**Maker-Checker Requirements:**
A period moved to `Closing` status can act as a review phase. Once the CFO verifies the pre-close validations and reports, they execute the final `Closed` transition. Reopening a period must require `generalledger.period.reopen` and physically write a log entry (e.g., to an `audit_logs` table or system logger) detailing the `opened_by` and the reason.

## 7. Performance Strategy
**Volume:** 10 years history * 12 months * 100 properties = 12,000 records.
**Indexing:** 
- A composite unique index on `(property_id, period_year, period_month)` ensures instantaneous lookup.
- The `PeriodControlService` will heavily cache the status of periods (e.g., Redis tag `property:{id}:periods`) because every financial transaction will query it. Cache is invalidated only when a period's status changes.

## 8. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Posting into Closed Periods | Critical | `PeriodControlService` intercept at the service level guarantees no bypass. |
| Pre-Close Validation Bypass | Critical | The `ClosePeriodAction` must run all validations synchronously before committing the `Closed` status. |
| Reopening Abuse | High | Segregation of Duties: Require specific `generalledger.period.reopen` permission. Log all reopening events. |
| Reconciliation Mismatch | High | Bank statements might be imported late. The pre-close validation blocks closing if statements are missing or unreconciled. |

## 9. Testing Plan
- `test_financial_period_creation_is_property_isolated_and_unique`
- `test_period_control_service_blocks_transactions_on_closed_periods`
- `test_pre_close_validation_fails_if_unposted_journals_exist`
- `test_pre_close_validation_fails_if_trial_balance_unbalanced`
- `test_closing_period_updates_status_and_audit_trails`
- `test_reopening_period_requires_permission_and_logs_event`
- `test_ap_and_banking_services_respect_period_locks`

## 10. Open Questions
1. **Caching Layer:** Should `PeriodControlService` utilize Laravel's Cache facade heavily for the `isOpen()` check to prevent a database hit on every single journal line insertion, or is the DB query lightweight enough to rely on the composite index for Sprint 12.0?
2. **Missing Periods:** If a user attempts to post a transaction into a month where the `FinancialPeriod` record does not explicitly exist yet (e.g., next month), should the system auto-create the period in `Open` status, or rigidly reject the posting until a user manually opens the new period?
