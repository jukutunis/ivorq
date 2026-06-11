# Cash Flow Foundation Implementation Plan

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Method:** INDIRECT METHOD. This method reconciles Net Profit to Cash by adjusting for non-cash items and changes in working capital.
- **Data Source Strategy:** The Cash Flow statement requires calculating the *period-over-period change* in Balance Sheet accounts. The `CashFlowService` will:
  1. Inject `ProfitLossService` to pull the `Net Profit` for the target period.
  2. Query `gl_ledger_balances` to calculate the *net change* in Asset, Liability, and Equity accounts between the beginning and end of the requested period.
- **Integration:** Bypassing raw journal lines and directly calculating the delta on `gl_ledger_balances` ensures maximum performance and perfect reconciliation with the Balance Sheet.

## 2. Classification Strategy
In the Indirect Method, Balance Sheet account movements must be classified into Operating, Investing, and Financing:
- **Operating:** Current Assets (Receivables, Inventory), Current Liabilities (Payables).
- **Investing:** Fixed Assets (Property, Plant, Equipment).
- **Financing:** Long-Term Liabilities (Loans), Equity (Capital Injections, excluding Net Profit).

**Limitation & Recommendation:** Currently, `AccountTypeEnum` only has generic `Asset`, `Liability`, and `Equity`. Without deeper categorization, the system cannot programmatically separate a Current Asset (Operating) from a Fixed Asset (Investing). 
**Interim Strategy for Sprint 11.5:** We will design the DTOs to support Operating, Investing, and Financing arrays. If `account_category` is not yet available on the `gl_accounts` table, all Asset changes will temporarily default to Investing (or Operating), and all Liability changes to Financing (or Operating) until the Chart of Accounts data model is expanded. *We highly recommend adding an `AccountCategoryEnum` or explicit cash flow mapping fields in a subsequent sprint to ensure accurate classification.*

## 3. Business Rules
- **BR-001:** Cash Flow generated from `Posted` journals only (inherent to `LedgerBalance`).
- **BR-002:** Property isolation is strictly mandatory.
- **BR-003:** Cash Flow generation is entirely read-only (no DB writes).
- **BR-004:** `Opening Cash` derived from the sum of Cash accounts' balances prior to the requested period.
- **BR-005:** `Closing Cash` derived from the sum of Cash accounts' balances up to the requested period.
- **BR-006:** `Net Cash Change = Operating + Investing + Financing`.
- **BR-007:** `Opening Cash + Net Cash Change = Closing Cash`.
- **BR-008:** `Current Year Earnings` (Net Profit) is rigorously sourced from the Profit & Loss service to anchor the Operating Activities section.

## 4. Report Design
The output will be structured via a Data Transfer Object (`CashFlowDTO`):

- **`opening_cash`**: Total balance of designated Cash accounts prior to the period.
- **Operating Activities:**
  - `net_profit` (from P&L)
  - Array of adjustments (`CashFlowLineDTO`)
  - `net_cash_operating`
- **Investing Activities:**
  - Array of adjustments (`CashFlowLineDTO`)
  - `net_cash_investing`
- **Financing Activities:**
  - Array of adjustments (`CashFlowLineDTO`)
  - `net_cash_financing`
- **`net_cash_change`**: Sum of Operating, Investing, and Financing nets.
- **`closing_cash`**: `opening_cash` + `net_cash_change`.
- **`balanced`**: Boolean validating if computed `closing_cash` exactly matches the actual Balance Sheet balance of the cash accounts.

## 5. Security & Audit
- **Permission:** Enforce `generalledger.cashflow.view` at the Controller layer.
- **Policy:** The user must be authorized for the `property_id` provided.
- **Auditability:** Read-only access does not generate database transactional logs, but export features (when built) will trigger system audit logs.

## 6. Performance Strategy
- **Volume Estimate:** 100k journals, 1M journal lines, 5 years history.
- **Recommendation:** Do not query raw lines. The service will query `gl_ledger_balances` for the beginning-of-period state and end-of-period state, calculating the delta `(End - Begin)` for each account in memory. This reduces the query overhead to two aggregated pulls, scaling perfectly. No caching is required.

## 7. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Misclassified Accounts | High | The lack of granular `account_category` means an Investing movement could land in Operating. Needs Chart of Accounts enrichment. |
| Incorrect Signage (Delta) | Critical | Indirect Method rules dictate that an *increase in Assets* is a *negative* cash flow, while an *increase in Liabilities* is a *positive* cash flow. Mathematical signs must be strictly inverted during delta calculation. |
| Imbalance | Critical | The `balanced` check ensures the calculated `closing_cash` exactly matches the physical `BalanceSheet` cash total. |
| Property Leakage | Critical | Explicit `property_id` scoping applied on all queries and P&L service injections. |

## 8. Testing Plan
- `test_cash_flow_net_profit_anchors_operating_activities`
- `test_cash_flow_calculates_asset_increase_as_negative_cash`
- `test_cash_flow_calculates_liability_increase_as_positive_cash`
- `test_cash_flow_validates_opening_plus_change_equals_closing`
- `test_cash_flow_enforces_property_isolation`
- `test_cash_flow_does_not_write_to_database`

## 9. Open Questions
1. **Cash Account Identification:** How will the `CashFlowService` identify which accounts are actually the "Cash" accounts for the `opening_cash` and `closing_cash` totals? Do we rely on the `AccountRoleEnum::Cash_Account` mapping from the Subledger Posting Engine, or do we need a new explicit flag like `is_cash_equivalent` on the `gl_accounts` table?
2. **Classification Mapping:** Since `AccountTypeEnum` only has generic Asset/Liability, should we build the Operating/Investing/Financing arrays now but just dump all Assets into Investing and all Liabilities into Financing as placeholders, pending future COA enhancements?
