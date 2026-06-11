# Trial Balance Foundation Implementation Plan

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Data Source Strategy:** The Trial Balance should be calculated primarily from the `gl_ledger_balances` table rather than dynamically summing millions of `gl_journal_entry_lines`. The `LedgerBalance` table already maintains synchronous aggregations of `debit_total` and `credit_total` per period, making it the perfect performant source for Opening Balance (sum of prior periods) and Period Activity.
- **Future Scalability:** By relying on `gl_ledger_balances`, the system can instantly generate a Trial Balance for any historical period without CPU-intensive SUM operations over raw journal lines.

## 2. Data Model Review
- **Current Capability:** A Trial Balance can be generated dynamically entirely from `gl_accounts` joined with `gl_ledger_balances`. 
- **Snapshot Table (`gl_trial_balance_snapshots`):** While dynamic generation is performant, a snapshot table (`gl_trial_balance_snapshots`) is highly recommended for **Compliance and Auditability**. When a month is officially closed (Future Scope), a snapshot is frozen. For Sprint 11.2, we will focus on Dynamic Generation but design the service to be easily adaptable to saving snapshots in the future.
- **Decision:** NO new database migrations are strictly required for dynamic generation in Sprint 11.2. 

## 3. Business Rules
- **BR-001:** Trial Balance only includes `Posted` journals (inherently true if using `LedgerBalance` as it only updates on post).
- **BR-002:** `Draft` journals are excluded.
- **BR-003:** `Voided` journals are excluded.
- **BR-004:** Property isolation is mandatory. The report must filter strictly by `property_id`.
- **BR-005:** Total Debit must equal Total Credit.
- **BR-006:** Trial Balance must be reproducible for any historical period.
- **BR-007:** Only active accounts with activity or balances appear by default.
- **BR-008:** Option to include zero-balance/inactive accounts if requested.
- **BR-009:** `Statistical` accounts are excluded from the financial Trial Balance.
- **BR-010:** Generation must be auditable (logged or tracked if exported).

## 4. Report Design Output
The Trial Balance service will output a DTO or Collection structured as follows:

**Line Items:**
- Account Code
- Account Name
- Account Type
- Opening Balance
- Debit Activity (For the requested period)
- Credit Activity (For the requested period)
- Ending Balance

**Footer/Totals:**
- Total Debit
- Total Credit
- Balanced (Boolean: Yes/No)

## 5. Security & Audit
- **Permission:** `generalledger.trialbalance.view` must be assigned to the user via Spatie Permissions.
- **Policy:** The user must have access to the specific `property_id` being queried.
- **Audit:** While generation is dynamic, if the user exports the report, the export action should be logged in standard system audit logs.

## 6. Performance Strategy
- **Volume Estimate:** 100k journals, 1M journal lines, 5 years history.
- **Recommendation:** Do NOT query `gl_journal_entry_lines`. Query `gl_ledger_balances`.
- **Opening Balance Calculation:** Sum `ending_balance` (or net of debit/credit) from all periods prior to the requested period.
- **Period Activity Calculation:** Sum `debit_total` and `credit_total` exactly matching the requested `period_year` and `period_month`.
- **Indexing:** Ensure a composite index exists on `gl_ledger_balances` for `(property_id, account_id, period_year, period_month)` – which is already covered by our unique constraint.

## 7. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Performance Degradation | Medium | Prevent raw journal line summation. Strictly use `gl_ledger_balances` aggregations. |
| Out of Balance Report | Critical | The `LedgerBalance` is updated synchronously during strict double-entry posting. The TB Service will perform a final sanity check (`Total Debit == Total Credit`) before returning the report. |
| Historical Restatement | High | If backdated journals are posted into closed periods, historical dynamic Trial Balances will shift. This necessitates the future Snapshot Strategy and Month-End closing locks. |
| Statistical Accounts mixing | Low | Explicitly filter out `AccountTypeEnum::Statistical` in the base query. |

## 8. Testing Plan
- `test_trial_balance_calculates_correct_opening_balance`
- `test_trial_balance_calculates_correct_period_activity`
- `test_trial_balance_excludes_statistical_accounts`
- `test_trial_balance_enforces_property_isolation`
- `test_trial_balance_totals_are_balanced`

## 9. Open Questions
1. **Snapshots vs Dynamic:** Are we completely bypassing the `gl_trial_balance_snapshots` table for this sprint and relying 100% on dynamic generation from `gl_ledger_balances`?
2. **Opening Balance Formula:** For Income Statement accounts (Revenue/Expense), does the Opening Balance roll over continuously, or does it reset at the start of the fiscal year? (Usually, P&L accounts zero out at year-end into Retained Earnings, but since Month-End/Year-End close is out of scope, how should we calculate Opening Balance for Revenue/Expense?)
