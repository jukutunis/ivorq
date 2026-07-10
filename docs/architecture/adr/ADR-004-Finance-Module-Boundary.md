# ADR-004: Finance Module Boundary Architecture

## ADR Metadata
* **ADR Number:** ADR-004
* **ADR Title:** Finance Module Boundary Architecture
* **Status:** Active
* **Date:** 2026-06-18
* **Authors:** CTO, Enterprise Finance Architect, Hospitality ERP Architect, Accounting Systems Architect, Governance Architect
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-002 (Audit Trail Strategy), ADR-003 (Approval Engine Architecture)

## Context
Hospitality operational platforms frequently suffer from severe architectural drift when financial responsibilities are improperly coupled with operational workflows. If the Finance module attempts to own the lifecycle of operational documents (e.g., Purchase Orders or Inventory Deductions), it creates monolithic bottlenecks, violates segregation of duties, and corrupts the General Ledger boundaries.

Finance requires strict boundaries to maintain data integrity and compliance. Accounting readiness demands that the Finance module acts as a governance and validation layer, not an operational workflow engine. A clear delineation of ownership prevents "spaghetti architecture" where operational modules directly manipulate accounting ledgers without oversight.

## Decision
We formally define the **Finance Domain Boundary** within the IVORQ platform. The Finance module is architected strictly as a governance, validation, reporting, and ledger-management domain. It consumes approved financial events from operational domains but does not own the execution of those operational events.

## Finance Domain Purpose
The Finance module is explicitly responsible for:
* **Financial Governance:** Establishing the Chart of Accounts (COA), tax rules, currency configurations, and fiscal periods.
* **Financial Controls:** Enforcing budget consumption limits and AP/AR reconciliation rules.
* **Financial Validation:** Validating the financial correctness of operational transactions (e.g., matching POs to Receiving and Invoices).
* **Financial Reporting:** Generating P&L, balance sheets, trial balances, and consolidated enterprise reports.
* **Financial Compliance:** Ensuring all ledger postings meet local statutory tax and regulatory requirements.
* **Financial Approval Participation:** Acting as an approver within ADR-003 workflows for financially significant events (e.g., high-value CAPEX).

## Finance Does NOT Own
Finance is strictly prohibited from owning operational execution. Finance **does NOT own**:
* **Inventory Quantities:** Finance owns the *value* of the inventory, but Cost Control/Operations owns the physical *quantity* and stock adjustments.
* **Purchase Requests:** Department heads own purchasing intent.
* **Stock Counts:** Warehousing/Cost Control owns physical counting.
* **Forecast Creation:** Department heads and Revenue Management own operational forecasting.
* **BEO Creation:** Sales & Event Management owns the BEO lifecycle.
* **Operational Workflows:** Finance does not dictate how Engineering executes a work order.
* **Department Operations:** Finance oversees the budget but does not run the department.

*Rationale:* Blurring these lines violates the Segregation of Duties. A system where the person counting the stock is also generating the financial journal entry for shrinkage is a severe audit risk.

## Domain Ownership Matrix
| Domain | Owner | Contributor | Reviewer | Consumer |
| :--- | :--- | :--- | :--- | :--- |
| **Purchasing** | Operations (Depts) | Vendors | Finance / GM | Cost Control |
| **Inventory** | Cost Control | Operations | Finance | Accounting |
| **Cost Control** | Cost Control | F&B / Engineering | Finance | Finance / Ops |
| **Forecasting** | Revenue Mgt / Depts | Sales | Finance / GM | Finance |
| **Budgeting** | Finance / GM | Dept Heads | Corporate | All Domains |
| **Accounting** | Accounting | Finance | External Auditors| Enterprise / GM |
| **Accounts Payable** | Accounting | Purchasing | Finance | Vendors |
| **Accounts Receivable**| Accounting | Front Office / Sales| Finance | Clients |
| **General Ledger** | Accounting | All Domains | Auditors | Finance |
| **Sales & Events** | Sales Team | F&B / Banquets | Revenue / Finance| Operations |
| **Front Office** | Front Office | Housekeeping | Finance | Accounting |
| **Engineering** | Engineering | Operations | Finance (CAPEX) | Finance |
| **Housekeeping** | Housekeeping | Front Office | Executive Office | Finance |

*(Future)*
* **Project Management:** Project Office (Owner), Finance (Reviewer).
* **PMS:** Front Office (Owner), Revenue Management (Contributor), Finance (Consumer).
* **HRIS:** Human Resources (Owner), Dept Heads (Contributor), Finance (Consumer of Payroll).

### Hospitality Financial Subledger Clarification

Clarified by ADR-088.

For PMS financial behavior, Front Office remains the organizational and business steward of PMS operations, but the operational guest-folio owner is PMS Guest Ledger. PMS Guest Ledger owns guest folio identity, folio lifecycle, folio items, guest ledger balance, settlement readiness, settlement evidence, and controlled folio closure after settlement. PMS Cashiering owns guest payment lifecycle, guest payment allocation, deposit application, guest refund, and payment void/reversal relationships.

Finance governs, reviews, configures, and consumes financial outcomes; Finance is not the operational owner of guest folios. Accounting owns Accounts Receivable and General Ledger outcomes after their accepted boundaries: Accounting / AR owns City Ledger receivables only after accepted transfer, and Accounting / GL owns journal entries, revenue recognition, tax posting, and financial-period control. This operational ownership clarification does not grant Front Desk direct folio, payment, deposit, refund, settlement, AR, cashier, or accounting mutation authority.

## Financial Document Ownership
* **Budget:** Owned by Finance (creation and lock). Consumed by Operations.
* **Forecast:** Owned by Operations. Validated and consumed by Finance.
* **Payment Voucher:** Owned by Accounting/AP.
* **Invoice:** Owned by Accounting/AP (Vendor Invoices) or AR (Client Invoices).
* **Journal Entry:** Strictly owned by Accounting.
* **Cost Posting:** Owned by Cost Control, translated to Journal Entries by Accounting.
* **Financial Period Close:** Strictly owned by Finance.
* **Approval Records:** Governed by ADR-003. Finance is merely a participant.
* **Audit Records:** Governed by ADR-002. Immutable system records.

## Integration Boundaries
* **ADR-001 (Multi-Tenant Hierarchy):** Finance enforces consolidated vs. property-isolated reporting.
* **ADR-002 (Audit Trail Strategy):** All financial ledger changes are mandatory audit entities.
* **ADR-003 (Approval Engine):** Finance delegates approval routing to the central engine.
* **Inventory Ledger to Cost Ledger:** Inventory changes (Quantity) trigger asynchronous events that the Cost Ledger (Value) processes based on moving average or FIFO rules.
* **Purchasing:** Finance interacts via AP matching (PO → Receiving → Invoice).
* **Forecasting & Budgeting:** Finance sets the templates; operational domains populate the data.
* **Reporting Engine:** Finance relies on a read-optimized data warehouse/reporting layer for complex aggregations.

## Cost Control Boundary
* **What Cost Control owns:** Recipe costing, yield management, physical stock counts, variance analysis, shrinkage reporting, and par level management.
* **What Finance owns:** The financial valuation of the inventory and the final journal entry posting of cost of goods sold (COGS).
* **Where they meet:** Cost Control identifies a variance of 5 bottles of wine. Finance determines the financial write-off value and posts it to the GL.
* **Where they stop:** Cost Control cannot post directly to the GL. Finance cannot alter the physical count sheet.

## Accounting Boundary
* **Accounting ownership:** General Ledger, AP, AR, Trial Balance, Bank Reconciliation, Tax Compliance.
* **Finance ownership:** Budgets, strategic forecasting, P&L analysis, financial modeling.
* **Operational ownership:** Generating the transactions that eventually feed Accounting (e.g., checking in a guest generates revenue).
* **Approval ownership:** Managed by ADR-003.
* **Audit ownership:** Managed by ADR-002.

## Budgeting Boundary
* **Creation:** Finance provisions the budget framework (COA mapping). Department heads input numbers.
* **Approval:** Routed via ADR-003 (GM → Corporate Finance).
* **Monitoring:** Real-time dashboards comparing Actuals vs. Budget.
* **Consumption:** Purchasing and Inventory deduct from available budget limits in real-time.
* **Override:** Budget limit overrides require specific ADR-003 approval chains.
* **Audit:** All budget revisions are audited per ADR-002.

## Forecasting Boundary
* **Creation:** Department heads generate operational forecasts (Occupancy, Covers, Revenue).
* **Review:** Revenue Management and Finance review for feasibility.
* **Approval:** Approved via ADR-003 to become the "Locked Forecast."
* **Adjustment:** Rolling forecasts are permitted, but historical locked forecasts are immutable.
* **Reporting:** Variance reporting (Actual vs. Forecast vs. Budget).

## Financial Approval Model
* **Integration:** Fully delegated to ADR-003.
* **Who approves:** Defined by property-specific rules (e.g., Director of Finance for >$5k).
* **Who reviews:** Department heads, Cost Controllers.
* **Who overrides:** Regional/Corporate Finance Directors (with mandatory audit trails).
* **Who audits:** External Auditors and Corporate Compliance, utilizing ADR-002 records.

## Financial Audit Model
* **Integration:** Strictly adheres to ADR-002.
* **Mandatory Events:** Journal Entry creation/voiding, AP/AR invoice posting, Budget locks, Period closing, Bank reconciliation finalization.
* **Visibility:** Restricted to authorized financial controllers and auditors, scoped tightly to the `tenant_id` and `property_id`.

## Multi-Tenant Financial Governance
* **Tenant Boundaries:** The General Ledger and Chart of Accounts can be templated at the Tenant level but execute at the Property level.
* **Property Boundaries:** A journal entry belongs to a single `property_id`.
* **Cross-property reporting:** Tenant-level users can generate consolidated P&L statements across multiple properties.
* **Consolidated reporting:** Requires complex currency conversion and inter-company elimination handling at the Tenant level.
* **Financial visibility:** Property controllers cannot see the financials of sibling properties.

## Security Requirements
* **Segregation of Duties:** The user who creates a vendor cannot approve the payment to that vendor.
* **Least Privilege:** Operational staff have zero access to the GL.
* **Financial Integrity:** Once a financial period is closed, NO modifications can be made to any operational or financial transaction within that period without a highly privileged, audited re-opening event.
* **Fraud Prevention:** Anomalous financial transactions (e.g., rapid consecutive voids) trigger immediate security alerts.
* **Approval & Audit Integrity:** Guaranteed by ADR-003 and ADR-002 respectively.

## Anti-Patterns
The following practices are explicitly prohibited:
* **Finance owning operational workflows:** e.g., The Finance team should not be creating Purchase Requests on behalf of the Kitchen.
* **Inventory modifying accounting directly:** A stock deduction must not `INSERT` directly into the `journal_entries` table. It must emit an event that the Accounting module consumes.
* **Purchasing bypassing approval:** Issuing POs without ADR-003 sign-off.
* **Budget overrides without audit:** Changing budget caps directly in the database.
* **Silent financial modifications:** Editing an invoice amount after it has been matched.
* **Cross-tenant financial visibility:** Any query calculating P&L that lacks a `tenant_id` scope.
* **Direct General Ledger manipulation:** Standard users posting manual JEs to bypass AP/AR modules.
* **Domain ownership confusion:** Blending Cost Control views into the core Accounting Ledger screens.

## Consequences
* **Positive consequences:** Prepares IVORQ for true Enterprise ERP status, guarantees audit compliance, prevents data corruption, and clearly delineates developer responsibilities when building new modules.
* **Negative consequences:** Increases system complexity through event-driven boundaries. Simple actions (like writing off a broken glass) now require orchestrated communication between Inventory, Cost Control, and Accounting.
* **Tradeoffs:** Trading rapid, monolithic development speed for long-term, enterprise-grade stability and compliance.

## Future Expansion
This bounded architecture seamlessly supports the Year 3/4 roadmap:
* **Accounting Engine:** Can be swapped or upgraded without breaking Purchasing.
* **General Ledger / AP / AR:** Deep integrations with external banking APIs.
* **Revenue Management:** Can consume clean historical financial data.
* **PMS Financial Posting:** The Night Audit process will cleanly hand off aggregated postings to Finance without mingling guest PII with the GL.
* **HRIS Payroll Integration:** Payroll runs will generate standardized journal entries for Finance to consume.
* **Corporate Consolidation / BI:** Tenant-level data warehouses can reliably aggregate clean, bounded data.
