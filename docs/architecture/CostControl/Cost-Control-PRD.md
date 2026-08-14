# IVORQ Cost Control PRD

> **CURRENT-STATE GOVERNANCE NOTICE — 2026-08-15**
>
> This Draft contains historical and planning assumptions. For current ownership, activation, Inventory/Cost Ledger precedence, AVCO authority, delivery-mode precedence, negative-stock policy, and reversal/correction scope, consult the accepted CC-R1 canonical revalidation, the CC-G1 governance freeze, and the current applicable ADRs. The analytical Cost Control workspace remains read-only, but the backend Finance/CostControl domain now write-owns durable Cost Ledger and AVCO state for enrolled scopes. That backend ownership does not authorize the workspace to mutate Inventory, Purchasing, Receiving, AP, GL, Financial Period, or Property Business Date facts.

## Document Status
Draft

## Purpose
This document defines the user-facing operational requirements for IVORQ Cost Control. It establishes Cost Control as an operational cost visibility, variance analysis, investigation, accountability, and controlled recommendation workspace for hospitality operations.

## Product Positioning
An operational cost visibility, variance analysis, investigation, accountability, and controlled recommendation workspace for hospitality operations providing timely, as-of governed source-data visibility.

Cost Control must not be defined as:
- Inventory quantity source of truth
- Inventory valuation engine
- Cost Ledger or General Ledger
- Procurement approval engine
- PO or commercial commitment owner
- Budget engine
- Formal accounting encumbrance engine
- Tax engine
- Direct accounting posting engine

## Business Context
Hospitality operations (especially Food & Beverage) require timely, as-of governed source-data visibility into consumption, theoretical vs. actual variance, and purchase price variances. Source availability, freshness, completeness, business-date status, and reconciliation state determine whether a view is provisional, pending, incomplete, or suitable only for governed financial reference. Cost Control provides operational management with the tools to investigate exceptions, monitor waste, and prepare month-end operational handoffs to Finance, without corrupting or bypassing core financial ledgers and governed procurement boundaries.

## Architecture and Source-of-Truth Boundaries
The PRD preserves the following architecture:
- Inventory Ledger = source of truth for inventory quantity movement
- Inventory Valuation = governed by ADR-010
- GRNI = receipt-to-invoice timing and matching boundary
- Cost Ledger = financial translation of inventory and cost events
- Procurement = commercial intent and commercial commitment ownership
- Recipe and Production Yield = governed by ADR-020
- POS Sales Depletion = governed by ADR-021
- Actual versus Theoretical Variance = governed by ADR-022
- Business Date and Night Audit = governed by ADR-034
- Budgeting, Forecasting, and Formal Accounting Encumbrance = explicitly out of scope pending future authorized ADR-035 work

Cost Control reads, analyzes, references, and investigates governed source data. Cost Control must not overwrite Inventory Ledger movement. Cost Control must not independently post Cost Ledger, General Ledger, tax, GRNI, AP, or payment entries. Cost Control must not modify approved Procurement commitments or Purchase Orders. Cost Control may initiate or recommend a controlled corrective action, but the applicable source-domain process remains responsible for execution. Inventory adjustments, waste postings, stock corrections, cost corrections, and write-offs must follow their respective governed approval, audit, and source-of-truth process. Cost Control must distinguish operational variance from accounting variance. A variance finding must not automatically mean an accounting adjustment. A variance finding must not automatically mean fraud, employee fault, supplier error, or inventory loss. Cost Control must preserve the original evidence, business date, property scope, source reference, and investigation outcome. Closed business-date and Finance-period corrections must follow ADR-013 and ADR-034. Cost Control must not bypass period-close or Night Audit controls. `tax-pending` or unresolved tax-sensitive items must not be presented as final cost outcomes where ADR-033 requires controlled pending treatment. Budget vs actual, forecast vs actual, PO commitment vs budget, and formal encumbrance are deferred. Do not design these features as current requirements.

## Goals
- Provide comprehensive operational cost visibility by authorized Property, Department, and Outlet.
- Enable investigation and tracking of actual vs. theoretical variances and purchase price variances.
- Facilitate daily flash reporting and month-end operational close readiness handoffs.
- Ensure all variances, waste, and adjustments are transparently investigated and routed to the correct governed domains for resolution.

## Non-Goals
- Replacing Finance period-close accounting.
- Direct manipulation of inventory quantities, costs, or ledger balances.
- Automating write-offs or accounting entries from variance findings.

## Users and Operational Roles
Detailed authorization and scope remain governed by ADR-029 and future authorization specifications. Do not define final permissions or a permission matrix here. Operational review, inventory execution, procurement action, Finance posting, and approval are distinct responsibilities.

| Role | Primary Cost Control Need | Boundary |
| ---- | ------------------------- | -------- |
| Cost Controller | Investigate variances, monitor daily food costs, and prepare month-end operational reviews. | Cannot independently post ledger adjustments or mutate inventory ledgers. |
| F&B Manager | View timely operational consumption, waste, and margin performance for departments. | Cannot alter standard recipe costs or approve financial write-offs. |
| Outlet Manager | Monitor daily operational cost and theoretical variance for specific assigned outlets. | Limited to outlet scope; cannot modify procurement or inventory. |
| Executive Chef / Kitchen Manager | Review recipe theoretical consumption against actuals, manage production yield. | Cannot modify supplier pricing or alter AP invoice matching. |
| Storekeeper / Inventory Controller | Resolve operational variance recommendations via governed stock adjustments. | Must follow ADR-003 and governed inventory ledger execution processes. |
| Purchasing Manager | Review purchase price variance (PPV) claims identified by Cost Control. | Owns commercial PO commitment; resolves supplier issues. |
| Finance Controller | Review month-end Cost Control handoff readiness before closing the period. | Owns the Cost Ledger and GL posting boundary. |
| General Manager | High-level property-wide operational cost visibility and major exception review. | Operates within governed Property boundaries. |
| Property Management | Consolidated operational reporting across departments. | Operates within governed Property boundaries. |
| Tenant-level Finance / Group Controller | Multi-property aggregated reporting and enterprise benchmarking. | Operates within Tenant scope; governed by ADR-029 and ADR-031. |

## Scope

### Data Readiness and Dependency Context
Every Cost Control view, report, variance case, and metric must retain or display as appropriate: Tenant and authorized Property scope; Business Date and reporting-period context; source-data freshness/completeness state; applicable source references; source dependency context; calculation or report-run context where applicable; applicable property currency or source currency context.

| State | Meaning |
| ----- | ------- |
| Complete | Required governed source inputs are available and internally consistent for the displayed analysis scope. |
| Partial | Some required inputs are available, but the view must remain explicitly provisional. |
| Pending | Required source processing, source completion, or reconciliation is not yet complete. |
| Unavailable | Required source data cannot currently be retrieved or resolved. |
| Exception | Source data is materially inconsistent, late, unresolved, or requires governed investigation. |

### 1. Cost Visibility Workspace
### 2. Actual Consumption Visibility
### 3. Theoretical Consumption Visibility
### 4. Actual versus Theoretical Variance Investigation
### 5. Purchase Price Variance Visibility
### 6. Waste, Adjustment, and Consumption Exception Analysis
### 7. Daily Flash Cost Reporting
### 8. Month-End Cost Control Review and Handoff Readiness
### 9. Multi-Property Reporting
### 10. Investigation, Evidence, and Controlled Follow-Up
### 11. Incomplete Data, Delayed Sources, and Safe Degradation

## Functional Requirements

### Cost Visibility Workspace
**CC-REQ-001**: Reporting filters must support Tenant-authorized Property scope, Business Date, reporting period, Department, Outlet, Store, Inventory Location, category, and item.
**CC-REQ-002**: The workspace must explicitly show data freshness and completeness status.
**CC-REQ-003**: The workspace must show source-data references, not invented values.
**CC-REQ-004**: The workspace must clearly separate provisional operational analysis from final financial reporting.
**CC-REQ-005**: Partial or delayed data must be made clearly visible.

### Actual and Theoretical Consumption
**CC-REQ-006**: Actual consumption quantity derives only from governed Inventory Ledger movement.
**CC-REQ-007**: Cost Control must not independently calculate, overwrite, or establish official inventory valuation.
**CC-REQ-008**: Financial cost visibility may be shown only where governed Inventory Valuation and/or Cost Ledger context is available.
**CC-REQ-009**: Where valuation or Cost Ledger context is unavailable, delayed, incomplete, unreconciled, or outside the applicable reporting state, the workspace must display the analysis as incomplete, pending, or provisional.
**CC-REQ-010**: A Cost Control view must not present an unofficial cost estimate as final financial cost.
**CC-REQ-011**: Theoretical consumption is available only when approved Recipe/Yield and POS Depletion sources are available and complete.
**CC-REQ-012**: Missing, delayed, mismatched, or unresolved inputs must produce a visible pending, incomplete, or exception state rather than false precision.
**CC-REQ-013**: Theoretical analysis must preserve applicable recipe/version, yield assumptions, source sales reference, Property scope, Business Date, and calculation-run context when implemented.

### Variance Investigation
**CC-REQ-014**: The system must track a variance case/reference.
**CC-REQ-015**: The system must support variance type classification.
**CC-REQ-016**: The system must retain source evidence references.
**CC-REQ-017**: The system must preserve Business Date and Finance period context.
**CC-REQ-018**: The system must retain Property, location, outlet, department, category, and item scope where applicable.
**CC-REQ-019**: The system must support assignee, status, cause hypothesis, action recommendation, reviewer comments, and approval/reference outcome.
**CC-REQ-020**: The system must explicitly distinguish between an investigation conclusion and an executed inventory or finance correction.
**CC-REQ-021**: The system must enforce no automatic write-off, stock adjustment, accounting entry, or supplier claim resulting directly from a variance finding.

### Daily Flash and Month-End Requirements
**CC-REQ-022**: Daily Flash Cost is a business-date-aware operational report and is provisional unless all required governed source inputs are complete.
**CC-REQ-023**: It must clearly state source freshness, completeness, unresolved exception count, and as-of context.
**CC-REQ-024**: Month-End Cost Control Review is an operational review and handoff-readiness activity.
**CC-REQ-025**: It does not itself close the financial period, post accounting adjustments, or replace Finance period close under ADR-013.
**CC-REQ-026**: It must retain references to unresolved investigation cases, pending source items, and controlled post-period adjustment paths where applicable.

### Purchase Price Variance Visibility
**CC-REQ-027**: Cost Control may display governed PPV results, relevant source references, and operational investigation context.
**CC-REQ-028**: Cost Control must distinguish operational purchase-price comparison from Finance-governed accounting PPV.
**CC-REQ-029**: Cost Control must not independently post PPV, alter invoice matching, alter purchase order terms, modify supplier records, or generate accounting correction.
**CC-REQ-030**: PPV visibility must retain applicable Property, supplier reference where authorized, item/category, business date/reporting period, and source-document context.
**CC-REQ-031**: Delayed, incomplete, disputed, tax-pending, unmatched, or unreconciled source data must be visibly marked and must not be presented as final cost outcome.

### Waste, Adjustment, and Consumption Exception Analysis
**CC-REQ-032**: Cost Control can analyze governed waste, spoilage, breakage, transfer, adjustment, and abnormal-consumption references.
**CC-REQ-033**: Cost Control can classify an exception for operational investigation.
**CC-REQ-034**: A Cost Control variance case cannot itself create stock adjustment, write-off, disposal, supplier claim, accounting entry, tax adjustment, or journal.
**CC-REQ-035**: Any corrective action must link to the applicable governed source-domain process, approval reference, and resulting outcome where available.
**CC-REQ-036**: Investigation findings must distinguish observation, hypothesis, recommendation, approved correction reference, and completed source-domain action.

### Multi-Property Reporting
**CC-REQ-037**: Multi-property views must preserve each Property identity, local business-date context, reporting period, and data-readiness status.
**CC-REQ-038**: Multi-property reports must not silently aggregate incompatible business dates, reporting scopes, valuation contexts, or currencies.
**CC-REQ-039**: Where a comparison or aggregation is supported, the report must retain Property-level drill-down and identify applicable currency context.
**CC-REQ-040**: Cross-Property access must follow Tenant-authorized role scope and ADR-029 / ADR-031 controls.

### Investigation, Evidence, and Controlled Follow-Up
**CC-REQ-041**: A variance case must retain status, assignee, reviewer, due/follow-up context, source evidence references, comments, recommendation, approval reference, and final investigation disposition.
**CC-REQ-042**: Reopened investigation must preserve prior disposition and reason for reopening.
**CC-REQ-043**: Investigation closure must not be interpreted as completion of an inventory, procurement, Finance, tax, or supplier correction unless a governed source-domain outcome reference exists.
**CC-REQ-044**: Cost Control must display the difference between `Investigation Closed` and `Source-Domain Corrective Action Completed`.

### Incomplete Data, Delayed Sources, and Safe Degradation
**CC-REQ-045**: The workspace must never silently substitute zero, last-known value, estimated consumption, assumed valuation, or assumed source completion for missing data.
**CC-REQ-046**: Delayed POS, recipe, yield, inventory, procurement, Cost Ledger, or Finance inputs must remain visibly pending or exception-marked.
**CC-REQ-047**: Theoretical consumption and PPV views must fail safely into incomplete/pending/exception state where critical source conditions are unresolved.
**CC-REQ-048**: Cost Control reporting must show the as-of time and data-readiness state for the requested analysis context.
**CC-REQ-049**: No report may be labeled final financial reporting merely because it is generated from a Cost Control workspace.

## Role and Segregation-of-Duties Expectations
Cost Control analysis is separated from inventory execution and accounting ledger posting. A Cost Controller investigates variances but relies on governed processes for the actual correction of stock (Storekeeper/Approval) or ledger adjustments (Finance).

## Reporting and Analysis Requirements
Cost Control views must preserve applicable Property base-currency or source-currency context. Cross-currency comparison, translation, and revaluation must not be invented by Cost Control. Any Finance-governed foreign-currency context remains subject to ADR-018. Operational cost analysis must remain distinct from finalized financial reporting. Reports must distinguish provisional operational analysis from final financial reporting. Multi-property reports must retain individual Property scope and local business-date context.

## Business Date, Financial Period, and Correction Boundaries
Corrections affecting a closed business date or financial period must follow the governed exception paths outlined in ADR-013 and ADR-034. Cost Control analysis does not retroactively mutate historical closed operational or financial data.

## Security, Privacy, Audit, and Evidence Requirements
- Tenant and Property data isolation must be enforced.
- Controlled visibility is required for supplier pricing, unit cost, margin data, and sensitive operational information.
- Audit evidence must be collected for investigation creation, assignment, conclusion, approval reference, reopened investigation, and controlled follow-up.
- No raw payment data, credentials, tokens, unnecessary guest PII, or sensitive payloads in Cost Control views or logs.
- References: ADR-002, ADR-003, ADR-029, ADR-031, ADR-033, and ADR-034.

## Dependencies
- Inventory Ledger
- Cost Ledger
- Finance Module
- Procurement
- Night Audit / Business Date Engine

## Deferred Dependencies
The following are not current Cost Control PRD requirements and depend on future authorized Budgeting / Forecasting / Formal Accounting Encumbrance governance:
- Budget versus actual
- Forecast versus actual
- Procurement commitment versus budget
- Formal accounting encumbrance
- Budget blocking or escalation
- Budget rate versus actual FX analysis

## Out of Scope
- Budget entry, budget approval, budget availability checking, forecast entry, forecasting calculations, budget vs actual reporting, forecast vs actual reporting, formal encumbrance, and PO reservation.
- Accounting journal generation.
- Direct Inventory Ledger movement creation.
- Direct Cost Ledger or General Ledger posting.
- AP invoice matching, GRNI settlement, payment execution, bank reconciliation, or tax calculation.
- POS, recipe, procurement, inventory, or Finance implementation.
- Payroll cost-control calculation.
- Database schema, API endpoints, technical event payloads, queue topology, background-job design, UI wireframes, vendor products, code, or sprint estimates.
- Country-specific accounting, tax, labor, or compliance rules.

## Open Questions Requiring Owner / CTO Decision
- Phase-one operational scope: F&B-only pilot, broader outlet scope, or Property-wide cost visibility.
- Initial Cost Control reporting cadence and required source-completeness threshold for Daily Flash operational use.
- Initial attachment/reference-evidence approach for variance investigation, subject to shared attachment and retention governance.
- Initial multi-property reporting and currency-presentation expectations.

(These are not blockers to preserve the PRD as Draft, but require Owner / CTO decision before implementation planning.)

## Product Acceptance Criteria
- Cost Control cannot mutate Inventory Ledger, Cost Ledger, Finance, Procurement, Tax, or budget data.
- Incomplete source data is visible and does not create false final cost conclusions.
- Every variance investigation retains Property, Business Date, source reference, and evidence context.
- Corrections after business-date or Finance-period close follow governed exception paths.
- Multi-property views preserve Property scope and do not silently aggregate incompatible business-date contexts.
- Restricted cost and supplier data is controlled by authorized role/scope.
- Budget/forecast/encumbrance features are absent or explicitly marked deferred.
- Reports distinguish provisional operational analysis from final financial reporting.

## Future Follow-On Documents
- Recipe and Yield Costing Specification
- POS Depletion Integration Specification
- Theoretical Consumption Calculation Specification
- Variance Investigation and Adjustment Workflow PRD
- Daily Flash Food Cost Reporting PRD
- Month-End Cost Control Close Specification
