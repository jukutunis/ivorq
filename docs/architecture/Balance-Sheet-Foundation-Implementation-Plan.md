# Balance Sheet Foundation Implementation Plan

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Data Source Strategy:** The Balance Sheet must calculate the accumulated balances (from inception up to the requested period) for all `Asset`, `Liability`, and `Equity` accounts. Querying `gl_ledger_balances` directly is the most performant method, bypassing millions of `gl_journal_entry_lines`.
- **Current Year Earnings Strategy:** To calculate `Current Year Earnings`, the `BalanceSheetService` will inject and invoke the `ProfitLossService::generate()` method for the same requested year/month. The `ytd_net_profit` from the P&L DTO will dynamically serve as the Current Year Earnings on the Balance Sheet. This strictly enforces the DRY (Don't Repeat Yourself) principle, ensuring the Net Profit formula is never duplicated and cannot fall out of sync.

## 2. Report Design
The output will be a Data Transfer Object (DTO) capturing the financial position:

### Sections
**Assets**
- Sub-sections: Current Assets, Fixed Assets, Other Assets (Requires sub-classification logic or just grouping by type). *Note: Since `AccountTypeEnum` currently only has `Asset`, we may group all assets under a single "Assets" list for Sprint 11.4 unless specific sub-types exist.*
- Array of `BalanceSheetLineDTO` (Account Code, Name, Type, Balance)
- `total_assets`

**Liabilities**
- Array of `BalanceSheetLineDTO`
- `total_liabilities`

**Equity**
- Array of `BalanceSheetLineDTO` (excluding Current Year Earnings)
- `current_year_earnings` (Dynamically injected)
- `total_equity` (Calculated: sum of Equity accounts + `current_year_earnings`)

**Validation**
- `balanced` (Boolean checking if `total_assets == total_liabilities + total_equity`)

## 3. Business Rules
- **BR-001:** Only `Posted` journals are included (Enforced by `gl_ledger_balances`).
- **BR-002:** `Draft` journals are excluded.
- **BR-003:** `Voided` journals are excluded.
- **BR-004:** Property isolation is strictly mandatory via `property_id` filtering.
- **BR-005:** Only `Asset`, `Liability`, and `Equity` accounts are fetched directly.
- **BR-006:** `Revenue`, `CostOfSales`, and `Expense` accounts do not appear directly.
- **BR-007:** Current Year Earnings is dynamically derived from Profit & Loss YTD.
- **BR-008:** Supports strict `period_year` and `period_month` filtering (aggregating all history up to that point).
- **BR-009:** Balance Sheet generation must be purely read-only (no DB writes).
- **BR-010:** Balance Sheet must validate: `Assets = Liabilities + Equity`.

## 4. Current Year Earnings
- Dynamically derived from `ProfitLossService`.
- **Formula:** `ProfitLossService->generate(property_id, year, month)->ytd_net_profit`.
- Bypasses the need for retained earnings roll-forward, fiscal close processes, or closing entries for this foundational sprint.

## 5. Security & Audit
- **Permission:** `generalledger.balancesheet.view` must be assigned to the user.
- **Policy:** User must have access to the queried `property_id`.
- **Auditability:** Future exports must trigger a system audit log.

## 6. Performance Strategy
- **Volume Estimate:** 100k journals, 1M journal lines, 5 years history.
- **Recommendation:** `gl_ledger_balances` direct query + `ProfitLossService` execution. The combined query load remains extremely light (two aggregate pulls on index-covered tables). No caching is required yet due to the efficiency of the aggregations.

## 7. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Imbalance (`Assets != Liab + Equity`) | Critical | Imbalance means the underlying General Ledger is out of sync, or CYE is calculated incorrectly. The final DTO `balanced` boolean acts as an immediate trap. |
| Property Leakage | Critical | Explicit `property_id` enforcement on the `LedgerBalance` and `ProfitLossService` calls. |
| Incorrect Equity Calculation | High | Normal balance logic must be rigorously applied: Assets (Debit Normal), Liabilities/Equity (Credit Normal). |
| Missing Retained Earnings | Medium | Since closing entries are out of scope, historical retained earnings (prior years' net profit) might be missing unless manually posted. Will note in Open Questions. |

## 8. Testing Plan
- `test_balance_sheet_calculates_correct_totals`
- `test_balance_sheet_injects_current_year_earnings_correctly`
- `test_balance_sheet_is_balanced`
- `test_balance_sheet_excludes_pnl_and_statistical_accounts`
- `test_balance_sheet_enforces_property_isolation`
- `test_balance_sheet_does_not_write_to_database`

## 9. Open Questions
1. **Asset/Liability Sub-classifications:** The prompt mentions "Current Assets, Fixed Assets, Other Assets" and "Current Liabilities, Long-Term Liabilities". Does the `AccountTypeEnum` already support these granular sub-types, or should we group all Assets under a single `total_assets` array and all Liabilities under a single `total_liabilities` array for now?
2. **Prior Year Retained Earnings:** Because there is no month-end/year-end closing process in scope, previous years' Net Profit won't automatically roll into a Retained Earnings account. Does the system expect Finance Admins to post a manual Journal Entry for historical Retained Earnings, or should the Balance Sheet dynamically aggregate *all prior years'* Net Profit into a `Prior Year Retained Earnings` line dynamically?
