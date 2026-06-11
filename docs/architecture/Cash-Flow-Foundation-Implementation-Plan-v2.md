# Cash Flow Foundation Implementation Plan v2

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Method:** INDIRECT METHOD. This method bridges Net Profit to actual Cash by calculating the adjustments from non-cash items and working capital shifts.
- **Data Source Strategy:**
  1. **Net Profit Anchor:** `CashFlowService` injects the `ProfitLossService` to pull the strictly calculated `Net Profit` for the target period.
  2. **Cash Balances:** Calculates `opening_cash` and `closing_cash` strictly by aggregating `gl_ledger_balances` for accounts flagged as `is_cash_equivalent = true`.
  3. **Working Capital & Non-Cash Adjustments:** Computes the period-over-period delta `(Closing Balance - Opening Balance)` for all non-cash Asset, Liability, and Equity accounts by querying `gl_ledger_balances` directly.
- **Integration:** This architecture prevents deep query overhead. Relying exclusively on aggregated ledger balances perfectly synchronizes the Cash Flow statement with the Balance Sheet and P&L simultaneously.

## 2. Classification Strategy
Leveraging the newly introduced `AccountCategoryEnum`, the Cash Flow statement dynamically routes balance shifts:
- **Operating Activities:** `Net Profit` (Anchor) + Changes in `CurrentAsset` + Changes in `CurrentLiability`.
- **Investing Activities:** Changes in `FixedAsset` + Changes in `OtherAsset`.
- **Financing Activities:** Changes in `LongTermLiability` + Changes in `Equity` (excluding the current period's Net Profit to avoid double counting).
- **Exclusions:**
  - `is_cash_equivalent = true` accounts (used only for Opening/Closing Cash lines).
  - `Revenue`, `CostOfSales`, `Expense` accounts (already represented by Net Profit).
  - `Statistical` accounts.

## 3. Business Rules
- **BR-001:** Cash Flow generated from `Posted` journals only (inherent to `LedgerBalance`).
- **BR-002:** Property isolation is strictly mandatory.
- **BR-003:** Generation is entirely read-only (no DB writes).
- **BR-004:** `Opening Cash` derived from the sum of `is_cash_equivalent = true` balances prior to the requested period.
- **BR-005:** `Closing Cash` derived from the sum of `is_cash_equivalent = true` balances up to and including the requested period.
- **BR-006:** `Net Cash Change = Operating + Investing + Financing`.
- **BR-007:** `Opening Cash + Net Cash Change = Closing Cash`.
- **BR-008:** `Net Profit` rigorously sourced from the Profit & Loss service to anchor Operating Activities.
- **BR-009:** Cash equivalent accounts must be excluded from adjustment lines to prevent circular accounting.
- **BR-010:** `Statistical` accounts are excluded.
- **BR-011:** `CurrentAsset` and `CurrentLiability` movements go to Operating.
- **BR-012:** `FixedAsset` and `OtherAsset` movements go to Investing.
- **BR-013:** `LongTermLiability` and `Equity` movements go to Financing.

## 4. Sign Rules
The Indirect Method requires strict signage inversion to accurately reflect how balance changes impact cash liquidity:
- **Asset Increase:** Represents cash *spent* -> **Negative Cash Flow** (Output = `Prior Balance - Current Balance`).
- **Asset Decrease:** Represents cash *received* -> **Positive Cash Flow**.
- **Liability/Equity Increase:** Represents cash *received* -> **Positive Cash Flow** (Output = `Current Balance - Prior Balance`).
- **Liability/Equity Decrease:** Represents cash *spent* -> **Negative Cash Flow**.

## 5. Report Design
The output will be structured via a Data Transfer Object (`CashFlowDTO`):

- **`opening_cash`**
- **Operating Activities:**
  - `net_profit`
  - Array of adjustments (`CashFlowLineDTO` containing Account Code, Name, Amount)
  - `net_cash_operating`
- **Investing Activities:**
  - Array of adjustments (`CashFlowLineDTO`)
  - `net_cash_investing`
- **Financing Activities:**
  - Array of adjustments (`CashFlowLineDTO`)
  - `net_cash_financing`
- **`net_cash_change`**
- **`closing_cash`**
- **`balanced`** (Boolean: `opening_cash + net_cash_change == closing_cash`)

## 6. Security & Audit
- **Permission:** Enforce `generalledger.cashflow.view` at the Controller layer.
- **Policy:** The user must be authorized for the `property_id` provided.
- **Auditability:** Safe, read-only extraction. Future export endpoints will trigger audit logs.

## 7. Performance Strategy
- **Volume Estimate:** 100k journals, 1M journal lines, 5 years history.
- **Recommendation:** Query `gl_ledger_balances` grouping by `account_id` where `period_year` and `period_month` fall into the requested window, returning the Beginning and Ending snapshot. Calculate the delta entirely in-memory. This reduces query load to index-covered aggregates, scaling exceptionally well.

## 8. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Sign Inversion Error | Critical | If an asset increase is mathematically computed as positive cash flow, the statement will catastrophically fail the `balanced` validation. Strict adherence to Section 4 formulas is required. |
| Double Counting Net Profit | High | If prior year Retained Earnings or current year Net Profit are accidentally included in the Equity adjustment lines, the statement will imbalance. |
| Cash Equivalent Inclusion | High | Including Cash accounts in the Operating or Investing adjustment sections will falsely inflate movements. BR-009 strictly blocks this. |
| Property Leakage | Critical | Explicit `property_id` scoping applied on all queries and P&L service injections. |

## 9. Testing Plan
- `test_cash_flow_net_profit_anchors_operating_activities`
- `test_cash_flow_calculates_asset_increase_as_negative_cash`
- `test_cash_flow_calculates_liability_increase_as_positive_cash`
- `test_cash_flow_validates_opening_plus_change_equals_closing`
- `test_cash_flow_routes_categories_to_correct_sections`
- `test_cash_flow_excludes_cash_equivalents_from_adjustments`
- `test_cash_flow_enforces_property_isolation`
- `test_cash_flow_does_not_write_to_database`

## 10. Open Questions
1. **Delta Period:** Should the Cash Flow statement generate the period-over-period change for a *single month* (e.g., May vs June), or should it generate the *Year-To-Date* change (e.g., Year Start vs June)? Most ERPs default Cash Flow to Year-To-Date (YTD). For Sprint 11.5, I will assume we are calculating the **Year-To-Date** delta (comparing the Opening Balance of Period 1 against the Ending Balance of the requested period), but please confirm.
2. **Equity Delta & Net Profit:** The Financing Activities section usually tracks Equity movements (like Capital Injection). Since `Net Profit` already anchors Operating Activities, we must ensure we don't accidentally double-count the change in Retained Earnings. Is it acceptable to dynamically exclude any Equity balance shift that exactly matches the prior/current year earnings, or should we just calculate the Equity delta strictly on non-earnings Equity accounts?
