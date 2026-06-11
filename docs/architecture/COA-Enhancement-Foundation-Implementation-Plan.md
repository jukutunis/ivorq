# COA Enhancement Foundation Implementation Plan

## 1. Architecture Review
- **Module:** `Modules/Finance/GeneralLedger`
- **Strategy:** The current `AccountTypeEnum` successfully supports the foundational reports (Trial Balance, P&L, Balance Sheet) but lacks the depth required for Cash Flow and advanced Treasury operations. The safest enhancement strategy is to leave `account_type` fully intact to ensure zero regression on Sprints 11.2, 11.3, and 11.4. We will introduce `account_category` and `is_cash_equivalent` as complementary, deeper classifications. This allows the General Ledger to scale gracefully into complex financial reporting without disrupting the established high-level architecture.

## 2. Enum Design
**`AccountCategoryEnum`**
Values:
- `CurrentAsset`
- `FixedAsset`
- `OtherAsset`
- `CurrentLiability`
- `LongTermLiability`
- `Equity`
- `Revenue`
- `CostOfSales`
- `Expense`
- `Statistical`

## 3. Migration Strategy
Adding new columns to a live `gl_accounts` table carries deployment risk.
- **Migration 1 (Schema Add):** Add `account_category` (string, nullable) and `is_cash_equivalent` (boolean, default false) to `gl_accounts`.
- **Migration 2 (Data Backfill):** Run a data backfill within the migration (or a dedicated console command) to populate `account_category` based on existing `account_type`.
  - *Mapping Rule:* `Asset` -> `CurrentAsset`, `Liability` -> `CurrentLiability`, `Equity` -> `Equity`, etc.
- **Migration 3 (Schema Enforce):** Alter `account_category` to be non-nullable to guarantee data integrity moving forward.

## 4. Business Rules
- **BR-001:** Every GL Account must have an `AccountType`.
- **BR-002:** Every GL Account must have an `AccountCategory`.
- **BR-003:** Category must be compatible with Type. For example:
  - `Asset` strictly maps to `CurrentAsset`, `FixedAsset`, or `OtherAsset`.
  - `Liability` strictly maps to `CurrentLiability` or `LongTermLiability`.
  - `Revenue`, `CostOfSales`, `Expense`, `Equity`, `Statistical` map exactly 1:1 with their respective types.
- **BR-004:** Only Asset categories (`CurrentAsset`, `FixedAsset`, `OtherAsset`) may have `is_cash_equivalent = true`.
- **BR-005:** `Statistical` accounts cannot be cash equivalent.
- **BR-006:** Property isolation remains mandatory for all account queries.

## 5. Dependency Analysis
- **Trial Balance:** Zero impact. Continues relying on `account_type`.
- **Profit & Loss:** Zero impact. Continues relying on `account_type`.
- **Balance Sheet:** Zero impact for Sprint 11.4 calculations. However, this sprint enables the future ability to group the Balance Sheet DTO arrays by `account_category` for a richer presentation.
- **Cash Flow (Future):** CRITICAL dependency. The Indirect Method will use `AccountCategoryEnum` to slot balance changes into Operating (CurrentAssets/CurrentLiabilities), Investing (FixedAssets), and Financing (LongTermLiabilities/Equity). It will use `is_cash_equivalent` to accurately sum Opening and Closing Cash balances.
- **Budget/Forecast/Treasury:** Will heavily leverage `is_cash_equivalent` to project cash positions.

## 6. Security & Audit
- **Audit Impact:** Minimal. Changing an account's category after transactions have been posted may shift how historical Cash Flow reports generate. This implies category edits on active accounts should ideally trigger audit logs or be restricted.
- **Data Migration Risk:** High if a deployed environment has thousands of accounts. The backfill strategy mitigates nullable constraint failures.
- **Permission Impact:** No new permissions required. Inherits standard GL setup permissions.

## 7. Testing Plan
- `test_account_requires_valid_category`
- `test_account_category_must_be_compatible_with_type`
- `test_only_asset_types_can_be_cash_equivalent`
- `test_statistical_accounts_cannot_be_cash_equivalent`
- `test_account_migration_backfills_categories_correctly`

## 8. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Incompatible Type/Category | High | Enforce BR-003 explicitly within the Account model's `creating` and `updating` Eloquent events, or via FormRequest validation. |
| Non-Nullable Migration Failure | High | Deploying a non-nullable column without a default value will crash if rows exist. Execute the 3-step migration strategy (Nullable -> Backfill -> Enforce). |
| Historical Report Shifting | Medium | If an admin changes an account from `CurrentAsset` to `FixedAsset`, past Cash Flow reports shift from Operating to Investing. Prevent category changes on accounts with posted activity, or accept it as dynamic restatement. |

## 9. Open Questions
1. **Backfill Execution:** Should the data backfill occur directly inside the `up()` method of the database migration, or should it be separated into a distinct `php artisan finance:backfill-coa` console command to ensure it doesn't timeout on massive databases?
2. **Immutability:** Should `account_category` and `is_cash_equivalent` be immutable once an account has associated `gl_ledger_balances`, similar to how `account_type` is typically locked?
