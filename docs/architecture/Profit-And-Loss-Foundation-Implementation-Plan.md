# Profit & Loss Foundation Implementation Plan

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Data Source Strategy:** The Profit & Loss (P&L) statement will be generated directly from `gl_ledger_balances` rather than reading through the `TrialBalanceService`. While the Trial Balance calculates opening and ending balances across the entire chart of accounts, the P&L specifically focuses on Year-To-Date (YTD) and Period-To-Date (PTD) activity for Income Statement accounts. Querying `LedgerBalance` directly allows the `ProfitLossService` to cleanly aggregate `debit_total` and `credit_total` for the exact fiscal year without the overhead of calculating Balance Sheet opening balances.
- **Future Scalability:** Direct aggregation from `gl_ledger_balances` scales perfectly to handle 5+ years of historical reporting, departmental P&L grouping, and future budget comparison features.

## 2. Report Design
The P&L output will be structured as a Data Transfer Object (DTO) containing categorized sections:

### Categorized Sections
**Revenue**
- Array of `ProfitLossLineDTO` (Account Code, Name, Type, Amount)
- `total_revenue`

**Cost Of Sales**
- Array of `ProfitLossLineDTO`
- `total_cost_of_sales`

**Gross Profit**
- `gross_profit` (Calculation: `total_revenue` - `total_cost_of_sales`)

**Operating Expenses**
- Array of `ProfitLossLineDTO`
- `total_expense`

**Net Profit / Loss**
- `net_profit` (Calculation: `gross_profit` - `total_expense`)

## 3. Business Rules
- **BR-001:** P&L only includes `Posted` journal balances (guaranteed by `LedgerBalance` integrity).
- **BR-002:** P&L strictly excludes `Asset`, `Liability`, and `Equity` accounts.
- **BR-003:** P&L strictly includes `Revenue`, `CostOfSales`, and `Expense` accounts.
- **BR-004:** P&L strictly excludes `Statistical` accounts.
- **BR-005:** Revenue accounts display as positive income (Formula: `Credit - Debit`).
- **BR-006:** CostOfSales and Expense accounts display as positive deductions (Formula: `Debit - Credit`).
- **BR-007:** Net Profit = `Revenue - CostOfSales - Expense`.
- **BR-008:** Property isolation is mandatory via `property_id` filtering.
- **BR-009:** P&L must support filtering by `period_year` and `period_month`. For Sprint 11.3, this will calculate the YTD amount up to and including the requested month.
- **BR-010:** P&L generation must be completely read-only and not write to the database.

## 4. Security & Audit
- **Permission:** `generalledger.profitloss.view` must be enforced at the controller level.
- **Policy:** The user must be authorized for the `property_id` provided in the request payload.
- **Audit:** Any eventual PDF/Excel export of this report should generate a system audit log.

## 5. Performance Strategy
- **Volume Estimate:** 100k journals, 1M journal lines, 5 years history.
- **Recommendation:** Do NOT query `gl_journal_entry_lines`. By querying `gl_ledger_balances`, we only process one row per account, per period. A 12-month P&L for 500 accounts scans a maximum of 6,000 index-covered rows.
- **DTO Strategy:** The entire P&L hierarchy should be built in-memory via Collections and DTOs after a single aggregated database pull.

## 6. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Negative Revenue Display | Medium | Enforce strict BR-005 and BR-006 formulas. Ensure contra-revenue (like Discounts) which normally have Debit balances are subtracted properly so they reduce total revenue logically. |
| Misclassified Accounts | High | Filter exactly by `AccountTypeEnum::Revenue`, `CostOfSales`, `Expense`. If an admin misclassifies a bank account as Revenue, it will falsely inflate profit. System must prevent changing active account types. |
| Performance Degradation | Medium | Prevent raw line summation. Use `LedgerBalance` exclusively. |
| Property Leakage | Critical | Force `property_id` on the base Eloquent Builder before executing any aggregate `sum()` functions. |

## 7. Testing Plan
- `test_profit_loss_calculates_correct_net_profit`
- `test_profit_loss_revenue_is_displayed_positive`
- `test_profit_loss_expenses_are_displayed_positive`
- `test_profit_loss_excludes_balance_sheet_and_statistical_accounts`
- `test_profit_loss_enforces_property_isolation`
- `test_profit_loss_does_not_write_to_database`

## 8. Open Questions
1. **Period Amount vs YTD Amount:** Should the P&L DTO return *both* the Period-To-Date (amount for the specific requested month) and the Year-To-Date (amount for all months in the year up to the requested month) side-by-side, or just focus on Year-To-Date for this foundational sprint?
2. **Gross Profit Formula:** Does IVORQ define Gross Profit strictly as `Revenue - CostOfSales`? Are there any indirect operational revenues that should sit below Gross Profit?
