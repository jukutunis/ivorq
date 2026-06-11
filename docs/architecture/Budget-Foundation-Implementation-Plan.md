# Budget Foundation Implementation Plan

## 1. Architecture Review
The Budget Foundation establishes a critical new financial pillar, distinct from yet perfectly aligned with the General Ledger. It should be architected within its own boundary (`Modules/Finance/Budgeting`) to respect domain separation. 
Since budgets compare future projections against historical reality, the budget engine will strictly consume `gl_ledger_balances` in a read-only capacity. It will absolutely never write to the core ledger, ensuring complete segregation of actual accounting data from financial planning data.

## 2. Budget Structure Design
The entity relationship demands a robust versioning tree to handle multi-departmental revisions:

- **`Budget`:** The root container (e.g., "2027 Operating Budget"). Links to `property_id` and the fiscal year.
- **`BudgetVersion`:** A specific iteration (e.g., v1, v2). Houses the state (`Draft`, `Submitted`, `Approved`, `Locked`).
- **`BudgetLine`:** The atomic value. Composite key of `budget_version_id`, `department_id`, `account_id`, `period_month`. Holds the projected `amount`.
- **`BudgetApproval`:** The audit trail of the maker-checker workflow, linking a user, a status action, a timestamp, and optional comments.
- **`BudgetVariance` (DTO/Service output):** A dynamic comparative orchestration combining `BudgetLine->amount` vs `LedgerBalance->ending_balance`.

## 3. Versioning Strategy
To maintain the integrity of financial planning:
- Budgets begin in `Draft` state on `v1`. 
- When `Submitted`, lines are frozen pending review.
- If `Rejected`, `v1` is closed and a new `v2` cloned in `Draft` state.
- Once `Approved`, the active version is marked `Locked` and becomes immutable. All comparative financial reporting will automatically bind to the most recently `Approved` version.

## 4. Budget vs Actual Orchestration
Because budgets represent period-specific projections per account, the Variance Engine will dynamically pull the `Approved` `BudgetVersion` and join it against `gl_ledger_balances`. 
- **P&L Variance:** Compares period/YTD revenues and expenses against the budget.
- **Statement Integration:** The orchestration layer will dynamically inject budget targets next to actuals across the Financial Statement Package when requested.

## 5. Business Rules
- **BR-001:** Budget boundaries are strictly isolated by `property_id`.
- **BR-002:** Planning granularity supports `department_id` allocations.
- **BR-003:** Planning targets specific GL `account_id`s.
- **BR-004:** An `Approved` budget becomes permanently immutable to preserve the integrity of performance targets.
- **BR-005:** `Locked` budgets reject all editing attempts natively at the Service Layer.
- **BR-006:** `BudgetVariance` is strictly read-only and dynamically calculated.
- **BR-007:** The Budget module absolutely never interacts with `gl_journal_entries`.
- **BR-008:** Actual accounting data (`gl_ledger_balances`) remains perfectly untouched by budget interactions.

## 6. Approval Workflow (Maker-Checker)
1. **Maker (budget.create/edit):** Department head or financial analyst drafts the lines and triggers `Submit`.
2. **Reviewer (budget.view):** Controller audits the submitted lines, optionally attaching notes to `BudgetApproval`.
3. **Approver (budget.approve):** CFO executes final approval, transitioning the version to `Approved` and `Locked`. 

## 7. Security Design
- `budget.view`: Read-only access to versions and variance reports.
- `budget.create` / `budget.edit`: Granted to Makers. Denied on `Submitted` or `Locked` versions.
- `budget.submit`: Transitions state to review.
- `budget.approve`: Restricted strictly to CFO/Director level.
- `budget.lock`: Executive override to forcibly freeze a budget.

## 8. Performance Strategy
**Volume:** 100 properties * 10 depts * 1000 accounts * 12 months * 5 years = ~6,000,000 `BudgetLine` rows.
- **Indexing:** Composite unique index on `(budget_version_id, department_id, account_id, period_month)` is absolutely mandatory to prevent table scans during variance calculations.
- **Aggregation Strategy:** Variance calculations should be heavily cached on a monthly basis, similar to the Financial Package cache.
- **Caching:** The ID of the currently `Approved` version per property/year should be cached (`budget:active:{property_id}:{year}`) so variance reports skip database lookups to find the master target.

## 9. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Budget Manipulation | Critical | Strict service-layer block preventing `UPDATE` or `DELETE` on `BudgetLine` where `version.status == Locked`. |
| Department Leakage | High | Enforce `property_id` validation hierarchically down through `department_id` binding. |
| Version Conflicts | High | Database unique constraint preventing multiple versions from being flagged as the "Active/Approved" master simultaneously. |
| Performance Degradation | Medium | Implement the composite unique index for O(1) row lookups during Variance joining against Ledger Balances. |
| Approval Bypass | Critical | Only users possessing `budget.approve` can transition a version to `Approved` status. |

## 10. Testing Plan
- `test_budget_creation_enforces_property_isolation`
- `test_budget_version_progression_draft_to_submitted_to_approved`
- `test_approved_budget_rejects_line_edits`
- `test_budget_variance_engine_calculates_actual_vs_budget_accurately`
- `test_budget_approval_generates_audit_trail`
- `test_multiple_approved_versions_blocked_per_year`

## 11. Open Questions
1. **Department Handling:** The prompt mentions `department_id`. Does the system currently possess a dedicated `Department` module or model within the multi-property architecture, or should the budget just treat department dynamically/hierarchically?
2. **Balance Sheet Budgeting:** Is the budget exclusively focused on the P&L (Revenue, Cost of Sales, Expense) for Sprint 13.0, or does it also require Capital Expenditure (Balance Sheet) planning?
