# Financial Statement Package Foundation Implementation Plan

## 1. Architecture Review
The Financial Statement Package acts as an orchestration layer above the existing financial reporting engines. Rather than directly querying the database or implementing new accounting logic, the `FinancialPackageService` will inject and invoke the four core reporting services:
- `TrialBalanceService`
- `ProfitLossService`
- `BalanceSheetService`
- `CashFlowService`

This service will aggregate the generated Data Transfer Objects (DTOs) into a unified `FinancialStatementPackageDTO`, run high-level reconciliations, and determine the overall health and validity of the period's financial standing.

## 2. Package Design
**DTO:** `FinancialStatementPackageDTO`
This orchestrator object encapsulates all reports and metadata cleanly for frontend consumption or PDF export.

**Structure:**
- `metadata`
  - `property_id`
  - `period_year`
  - `period_month`
  - `generated_at` (Timestamp)
  - `period_status` (Open/Closed)
- `reports`
  - `trial_balance` (`TrialBalanceDTO`)
  - `profit_loss` (`ProfitLossDTO`)
  - `balance_sheet` (`BalanceSheetDTO`)
  - `cash_flow` (`CashFlowDTO`)
- `validations`
  - `trial_balance_valid` (Boolean)
  - `balance_sheet_valid` (Boolean)
  - `cash_flow_valid` (Boolean)
  - `cross_report_valid` (Boolean)
- `status` (`PackageStatusEnum`)

## 3. Validation Strategy
The package validates the integrity of the underlying ledgers in four dimensions:
1. **Trial Balance Integrity:** Ensures `debit_total == credit_total`.
2. **Balance Sheet Integrity:** Ensures `assets == liabilities + equity`.
3. **Cash Flow Integrity:** Ensures `opening_cash + net_cash_change == closing_cash`.
4. **Cross-Report Integrity (P&L to BS):** Verifies that `ProfitLossDTO::ytd_net_profit` exactly matches `BalanceSheetDTO::current_year_earnings`.

## 4. Business Rules
- **BR-001:** Financial Package is strictly read-only.
- **BR-002:** Can be generated for both Open and Closed periods.
- **BR-003:** Mandatory property isolation. The orchestrator must pass `property_id` to all underlying services.
- **BR-004:** Must purely consume existing report services. No direct database querying for balances.
- **BR-005:** No duplicate calculations; strictly leverages the aggregation logic of the underlying services.
- **BR-006:** Package validation must pass perfectly before achieving `status = Valid`.
- **BR-007/008:** Absolute prohibition of journal creation or closing entries within this orchestration.

## 5. Package Status Design
The overall health of the package is classified into three statuses:
- **Valid:** All internal report structures reconcile, and the cross-report net profit tie-out is exact.
- **Warning:** Extremely minor fractional deviations (e.g., floating-point rounding mismatches < 0.05) or non-critical missing mappings that don't technically throw the ledger out of balance.
- **Invalid:** Critical reconciliation failures (e.g., Trial Balance out of balance, Assets != Liabilities + Equity, or Net Profit mismatch).

## 6. Security & Audit Design
**Security:**
- Enforced via the `generalledger.financialpackage.view` permission.
- Route level middleware guarantees property isolation check against the authenticated user's assigned properties.

**Auditability:**
- **Open Periods:** Generating packages for open periods is highly volatile and frequent; routine access logging is sufficient without creating database audit records.
- **Closed Periods:** Since closed periods represent official finalized history, accessing or downloading a finalized Financial Package should be logged as a read-event in the system audit log (e.g., "CFO viewed Financial Package for 2026-05") for strict compliance and data loss prevention tracking.

## 7. Performance Strategy
**Volume Estimate:** 100 properties * 10 years * 12 months = 12,000 potential packages.
- **On-Demand Generation:** For `Open` periods, the package is generated on-the-fly to guarantee real-time data representation. This leverages the existing highly optimized `gl_ledger_balances` table.
- **Caching Strategy:** For `Closed` periods, the data is immutable. The `FinancialStatementPackageDTO` should be serialized and cached in Redis using the `reporting:{property_id}` tag. Once a period is closed, its package generation time drops to O(1) cache retrieval, ensuring massive scalability.

## 8. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Report Mismatch | High | Cross-report validation definitively catches if P&L Net Profit decouples from Balance Sheet Retained Earnings. |
| Stale Cache | High | Cache is strictly tagged (`reporting:{property_id}`). The `PeriodControlService::reopen()` forcefully flushes this tag. |
| Property Leakage | Critical | Orchestration layer strictly requires `property_id` injection to all four underlying report engines simultaneously. |
| Performance Bottleneck | Medium | Generating 4 reports sequentially might cause slight HTTP delay. Mitigation: Caching for closed periods, and the underlying `gl_ledger_balances` table is highly indexed. |

## 9. Testing Plan
- `test_financial_package_orchestrates_all_four_reports`
- `test_financial_package_validates_cross_report_net_profit`
- `test_financial_package_detects_invalid_reconciliation`
- `test_financial_package_enforces_property_isolation`
- `test_financial_package_caches_closed_periods`

## 10. Open Questions
1. **Asynchronous Generation:** Should the orchestration of the 4 reports happen synchronously via the HTTP request, or should the package be dispatched as a background Job that notifies the user when complete (useful for very large enterprise ledgers)?
2. **Snapshot Storage:** For permanently closed periods, instead of just Redis caching, should we serialize the final `FinancialStatementPackageDTO` and store it natively in the database (e.g., a `gl_financial_package_snapshots` table) to guarantee absolute historical preservation independent of cache flushes?
