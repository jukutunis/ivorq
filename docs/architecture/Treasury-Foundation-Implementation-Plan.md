# Treasury Foundation Implementation Plan

## 1. Architecture Review
The Treasury Foundation operates as the ultimate executive aggregation layer (`Modules/Finance/Treasury`). It functions exclusively as a read-only analytical engine that fuses disparate operational data—Actual Bank Balances, AP/AR Subledger exposure, and the approved Forecasts/Budgets—to project organizational liquidity. It absolutely never creates underlying accounting entries or bank statements, operating solely to provide the CFO and Treasury team with a crystalline view of cash risk and availability.

## 2. Treasury Structure Design
Since Treasury is largely analytical, its structure blends persistent snapshots with dynamic DTOs:
- **`BankBalanceSnapshot` (Model):** A daily persistent record capturing the ending balance of all bank accounts per property, vital for historical trend analysis.
- **`CashPosition` (DTO):** A real-time aggregation of current bank balances, unreconciled movements, and `is_cash_equivalent` GL accounts.
- **`LiquidityProjection` (DTO):** A time-series generation mapping expected cash inflows and outflows across 7, 30, and 90-day horizons.
- **`TreasuryAlert` (Model/DTO):** Evaluated warnings (e.g., "Cash drops below 10% OPEX within 30 days"). Can be persistent for audit or dynamically computed on Dashboard load.

## 3. Cash Position Engine
To deliver a true Current Cash Position, the engine will query:
1. `BankAccount` records for the latest reconciled ledger balance.
2. `gl_ledger_balances` specifically filtering for `is_cash_equivalent == true` to identify petty cash or non-bank liquidity.
3. Unreconciled `gl_journal_entry_lines` hitting cash accounts to bridge the gap between the last bank reconciliation and "today".

## 4. Liquidity Projection (7/30/90 Days)
Liquidity forecasting requires converting monthly OPEX/REV into daily burn rates:
- **7-Day:** Heavily weighted on the Accounts Payable (Vendor Invoices due) and Accounts Receivable aging.
- **30-Day:** Fuses near-term AP/AR with the `ForecastLine` projections for the current month.
- **90-Day:** Primarily relies on the Active Approved `ForecastVersion` lines, calculating average daily cash burn based on projected Net Income (Revenue minus Expenses).

## 5. Treasury Alerts
The `TreasuryAlertService` will scan the `CashPosition` and `LiquidityProjection` to generate:
- **Low Cash Alert:** Triggered if projected 30-day cash dips below a defined baseline (e.g., 1-month OPEX).
- **Negative Cash Alert:** Triggered if any single bank account or overall property position projects into the negative within 90 days.
- **Large Variance Alert:** Triggered if the Actual Cash Position deviates by >10% from the Forecasted Cash Position for the current month.
- **Liquidity Risk Alert:** Systemic warning if total Liquid Assets cannot cover short-term Liabilities.

## 6. Business Rules
- **BR-001/BR-002/BR-003:** The Treasury module is strictly read-only. It never creates `gl_journal_entries`, nor does it alter `BankAccounts` or `BankStatements`.
- **BR-004:** Long-term liquidity projections mandate the use of the singular active `Approved` (or Locked) `ForecastVersion`.
- **BR-005:** If a Forecast is unavailable, projections fallback to the `Approved` Budget.
- **BR-006:** All dashboards, projections, and alerts are strictly `property_id` isolated.
- **BR-007:** Alerts are generated dynamically upon request to guarantee the CFO is viewing up-to-the-second risk assessments based on live ledger data.

## 7. Security Design
Role-based access strictly isolates Treasury visibility:
- `treasury.view`: Baseline access to view static cash positions.
- `treasury.alert.view`: Granted to controllers to monitor daily liquidity risks.
- `treasury.dashboard.view`: Executive-level access combining projections, alerts, and multi-property cash consolidations.

## 8. Performance & Snapshot Strategy
**Volume:** 100 properties * 20 bank accounts * 365 days * 10 years = ~7,300,000 daily `BankBalanceSnapshot` rows.
- **Snapshot Strategy:** Instead of calculating cash position dynamically from the dawn of time, a nightly scheduled command (`treasury:snapshot-balances`) will calculate and store the ending cash position per bank account/property.
- **Caching:** The 30/90 Day Liquidity Projections are mathematically heavy. They should be calculated nightly and cached (`treasury:liquidity:{property_id}`), with only the 7-day projection adjusting dynamically based on live AP invoice approvals.
- **Aggregation:** Rely heavily on database-level `SUM()` grouping by `property_id` rather than loading millions of ledger lines into PHP memory.

## 9. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Stale Cash Position | High | If bank reconciliation is delayed, the engine must actively scan unreconciled GL lines to provide an accurate "Unreconciled Cash Position". |
| Forecast Mismatch | Medium | If the FP&A team provides an inaccurate forecast, 90-day projections will fail. Implement the "Large Variance Alert" to warn the CFO of baseline inaccuracy. |
| Bank Reconciliation Dependency | Critical | The entire module loses value if Banking is not maintained. Expose a "Days Since Last Reconciliation" metric directly on the Treasury Dashboard. |
| Performance Bottlenecks | High | Prevent on-the-fly 10-year calculations. Enforce the nightly `BankBalanceSnapshot` table for historical graphing. |

## 10. Testing Plan
- `test_cash_position_aggregates_bank_and_unreconciled_gl`
- `test_liquidity_projection_utilizes_approved_forecast`
- `test_treasury_alerts_trigger_on_negative_cash`
- `test_treasury_module_is_strictly_read_only`
- `test_nightly_snapshot_generates_accurate_balances`
- `test_treasury_property_isolation`

## 11. Open Questions
1. **Accounts Payable / Accounts Receivable Exposure:** To generate an accurate 7-Day Liquidity Projection, Treasury should factor in unpaid Vendor Invoices. Is the Accounts Payable module currently mature enough to dynamically provide a "Cash Required by Date" metric, or should we rely purely on straight-line GL forecast averages for Sprint 15.0?
2. **Treasury Alert Persistence:** The prompt mentions "alerts generated dynamically" (BR-007). Does this explicitly mean alerts should *never* be saved to the database (strictly DTOs served to the frontend), or should they be dynamically generated but logged for audit purposes?
