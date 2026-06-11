# Forecast Foundation Implementation Plan

## 1. Architecture Review
The Forecast Foundation serves as the agile counterpart to the static Operating Budget. While the budget remains an immutable anchor for the fiscal year, the forecast continually adapts based on shifting market realities and month-to-month actuals. This foundation will be housed in its own dedicated namespace (`Modules/Finance/Forecasting`) but tightly integrated with both `GeneralLedger` (Actuals) and `Budgeting` (Targets). The forecast engine will remain strictly read-only against actual ledger data and journal entries.

## 2. Forecast Structure
The architecture deeply mirrors the Budget structure to provide uniform workflows, introducing:
- **`Forecast`:** The root container (e.g., "2027 Operating Forecast"). Scoped by `property_id` and `fiscal_year`.
- **`ForecastVersion`:** Tracks the iteration state (`Draft`, `Submitted`, `Approved`, `Locked`). Allows mid-year re-forecasting (e.g., "3+9 Forecast", "6+6 Forecast").
- **`ForecastLine`:** Atomic value storing the projected `amount`. Unique on `(forecast_version_id, department_id, account_id, period_month)`.
- **`ForecastApproval`:** The immutable audit ledger capturing maker-checker actions.
- **`ForecastVariance` (DTO/Service output):** A complex dynamic comparative structure evaluating Actual vs Budget vs Forecast.

## 3. Forecast Methods (Foundation Approach)
To support robust FP&A workflows, the system should structurally embrace **Method 3: Manual Override**, while offering baseline initializations utilizing Methods 1 & 2:
1. **Budget Based Forecast:** Initializes `ForecastLine` amounts as an exact 1:1 copy of the `Locked` Budget.
2. **Actual + Remaining Budget:** Initializes past periods using `gl_ledger_balances` Actuals, and future periods using `BudgetLine` figures.
3. **Manual Override (Core):** Allows the FP&A team to manually adjust the initialized `ForecastLine` amounts for future periods to reflect changing expectations.

## 4. Forecast vs Budget vs Actual
The orchestration layer (`ForecastVarianceService`) will aggregate data from three separate domains to generate a unified comparative DTO per line:
- `Actual` (from `gl_ledger_balances`)
- `Budget` (from `BudgetLine` belonging to active locked budget)
- `Forecast` (from `ForecastLine` belonging to active locked forecast)
- `Variance` (Forecast - Actual, or Budget - Forecast depending on FP&A context)
- `Variance %` ((Variance / Baseline) * 100)

## 5. Versioning
Identical strict version control to the Budget module:
- `Draft`: Writable by Maker.
- `Submitted`: Frozen, pending Reviewer/Approver.
- `Rejected`: Clones to a new Draft version.
- `Approved`: Becomes the active Master Forecast. Triggers transition to `Locked`.
- `Locked`: Immutable final state. 

## 6. Business Rules
- **BR-001:** Strict property isolation.
- **BR-002:** Supports `department_id` granularity.
- **BR-003:** Targets specific `account_id`s (P&L only for operating forecast).
- **BR-004/005:** `Approved` and `Locked` forecast versions are deeply immutable at the service layer.
- **BR-006:** Forecast variance calculations are generated on-the-fly and strictly read-only.
- **BR-007/008:** Forecast operations absolutely never create, alter, or impact `gl_journal_entries` or `gl_ledger_balances`.

## 7. Approval Design (Maker-Checker)
1. **Maker (`forecast.create`, `forecast.edit`):** Department head generates draft and triggers `Submit`.
2. **Reviewer (`forecast.view`):** Regional Controller reviews submissions.
3. **Approver (`forecast.approve`):** Executive CFO approves, cementing it as the official forward-looking target and locking the version.

## 8. Security Design
- Route and service authorization enforced via specific permissions: `forecast.view`, `forecast.create`, `forecast.edit`, `forecast.submit`, `forecast.approve`, `forecast.lock`.
- Hierarchical property isolation ensures users can only forecast for assigned properties.

## 9. Performance Review
**Volume Estimate:** 100 properties * 10 departments * 1000 accounts * 5 years * 12 months = 6,000,000 `ForecastLine` rows.
- **Indexes:** Composite unique indexing on `(forecast_version_id, department_id, account_id, period_month)` is critical.
- **Caching:** The active `Approved` forecast version ID will be cached at `forecast:active:{property_id}:{year}`.
- **Aggregation:** Since Variance requires joining `ForecastLine`, `BudgetLine`, and `LedgerBalance`, heavy reliance on indexed primary/composite keys is mandatory to prevent massive DB table scans.

## 10. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Forecast Manipulation | Critical | Strict service-layer lock on any `UPDATE` or `DELETE` attempt against lines in an `Approved` or `Locked` version. |
| Version Conflicts | High | Enforce a rule that only one Forecast version can be `Approved` per property/year/forecast-period. |
| Performance Degradation | Medium | Rely heavily on composite indexes and cache the active master version ID. |
| Budget Mismatch | Medium | Variances pull directly from the active locked Budget. A stale budget cache could distort forecast variance; flush variance caches if Budget is forcibly altered. |

## 11. Testing Plan
- `test_forecast_version_progression`
- `test_locked_forecast_is_immutable`
- `test_forecast_variance_engine_aggregates_actual_budget_and_forecast`
- `test_forecast_initialization_from_budget`
- `test_forecast_property_isolation`
- `test_only_one_approved_forecast_allowed`

## 12. Open Questions
1. **Forecast Frequency:** Unlike the Budget (which is generally one Active version per year), Forecasts often shift monthly or quarterly (e.g., Q1 Forecast vs Q2 Forecast). Should the system allow ONE active Forecast per fiscal year, or ONE active Forecast per *Month/Quarter* of the fiscal year?
2. **Initialization Workflow:** Should the initial instantiation of a `ForecastVersion` automatically seed the `ForecastLine` rows by pulling from the `Budget` and `LedgerBalances` (Method 2), or should it spawn completely empty requiring manual data entry or explicit initialization commands?
