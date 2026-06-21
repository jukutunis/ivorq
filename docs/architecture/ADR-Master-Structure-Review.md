# IVORQ ADR Master Structure Review

## 1. ADR Portfolio Delivery Status

| ADR Range / ADR | Title | Git Baseline Status | ADR Decision Status | Architecture Role |
| :--- | :--- | :--- | :--- | :--- |
| ADR-001 to ADR-029 | Various Finance & Core Foundations | Tracked / Committed | Various (Immutable) | Finance & Core Foundation Baseline |
| ADR-030 | Identity, Authentication and Session Governance | Tracked / Committed | Proposed | Enterprise Security Boundary |
| ADR-031 | Data Privacy, PII, Retention and Data Residency Governance | Tracked / Committed | Proposed | Enterprise Privacy Boundary |
| ADR-032 | Purchasing, Procurement and Contract Management Architecture | Tracked / Committed | Proposed | Procurement Governance |
| ADR-033 | Global Tax and Jurisdiction Compliance Architecture | Tracked / Committed | Proposed | Tax Governance |
| ADR-034 | Night Audit and Hospitality Business Date Architecture | Tracked / Committed | Proposed | PMS Business-Date Foundation |

### Portfolio Capacity and Guardrails
IVORQ currently has 34 tracked ADRs, ADR-001 through ADR-034. The intended healthy architecture portfolio range remains approximately 37–41 ADRs. This leaves an estimated conceptual capacity of approximately 3–7 additional ADRs. This range is a governance guardrail, not a quota. A new ADR must be created only when it records a cross-domain, long-lived, difficult-to-reverse architecture decision. A new module, workflow, UI, report, or implementation need does not automatically justify a new ADR.

## 2. Completed Roadmap Items
The previously planned next five ADRs (ADR-030 through ADR-034) represent completed document delivery milestones. 
- They have been created and baseline-committed.
- Their decision status remains **Proposed**.
- Implementation remains separately governed and is not yet production complete.

## 3. Current Architecture Readiness Assessment

| Architecture Area | Current Position | Readiness | Notes |
| :--- | :--- | :--- | :--- |
| Enterprise hierarchy and tenant isolation | Active | Foundation ready | ADR-001 |
| Audit, approvals, RBAC, session governance | Tracked | Architecture ready, implementation pending | ADR-002, ADR-003, ADR-029, ADR-030 |
| Privacy, PII, retention, residency | Tracked | Architecture ready, implementation pending | ADR-031 |
| Procurement and vendor commercial controls | Tracked | Architecture ready, implementation pending | ADR-006, ADR-032 |
| Inventory, GRNI, Cost Ledger, Finance boundary | Active | Foundation ready | ADR-004, ADR-008 through ADR-015 |
| Tax and multi-jurisdiction architecture | Tracked | Architecture ready, implementation pending | ADR-033 |
| Night Audit and hospitality business-date foundation | Tracked | Architecture ready, implementation pending | ADR-034 |
| PMS readiness | Planning | Architecture prerequisites established, module design pending | Requires PRDs/Specifications based on ADR-034 |
| Cost Control readiness | Planning | Separate Audit / Readiness Workstream | Not included in this ADR update |
| HRIS readiness | Deferred | Deferred roadmap domain | Requires future PRDs |
| Budgeting / Forecasting / Encumbrance | Deferred | Requires future ADR | Deferred by ADR-032 |
| Contract / document evidence management | Deferred | Requires PRD/specification | - |
| Integration architecture and external data exchange | Partially governed | Requires PRD/specification | - |
| Reporting / BI / analytics secondary use | Partially governed | Requires PRD/specification | - |

## 4. ADR vs PRD vs Specification Decision Matrix

### Use an ADR for:
- Cross-domain source-of-truth decisions
- Irreversible accounting, tenancy, security, tax, or business-date boundaries
- Long-lived ownership boundaries
- Architecture-wide control models

### Use a PRD for:
- PMS workflows
- Housekeeping, Engineering, HRIS, Purchasing, Cost Control module requirements
- User roles in a module
- Operational exceptions and user-facing behavior
- Dashboard and report requirements

### Use a Specification for:
- API contracts
- Event payloads
- Posting-engine behavior
- Reconciliation algorithms
- Data mapping
- Export formats
- Integration adapters
- Night Audit checkpoint catalog
- Tax calculation implementation contract

### Use an Implementation Plan for:
- Sprint sequencing
- Migration
- Release strategy
- Rollback
- Validation
- Rollout controls

## 5. Candidate Future ADR Assessment

| Candidate | Recommendation | Reason | Priority | Prerequisite / Trigger |
| :--- | :--- | :--- | :--- | :--- |
| **Budgeting, Forecasting and Formal Accounting Encumbrance Architecture** | Create ADR next | It is the highest-priority remaining ADR candidate because ADR-032 explicitly deferred formal budget reservation and accounting encumbrance. The architecture decision should begin after a short scoped readiness review confirms the intended budget-control scope, Finance ownership, Procurement interaction, and relationship to Cost Control. It should not be created merely to fill the ADR portfolio target. | High | Before authorized implementation begins for Budgeting, Forecasting, formal budget control, or accounting encumbrance. |
| **Legal Entity, Accounting Scope and Tax Registration Architecture** | Create ADR later when trigger is met | Interacts with Tenant, intercompany, and tax registration, but not required immediately. | Medium | Prior to complex multi-entity/group accounting expansion |
| **Guest Ledger, Folio and Hospitality Financial Subledger Architecture** | Create ADR later when trigger is met | Guest Ledger, Folio, and Hospitality Financial Subledger may become a durable cross-domain source-of-truth and financial-boundary decision. It may intersect PMS, Front Office, guest folio, Revenue Recognition, Tax, City Ledger / AR, Night Audit, payments, and Finance. It should not be created automatically now because PMS implementation has not begun. Before PMS financial implementation starts, conduct a focused boundary review. Create the ADR only if that review confirms the need for a durable cross-domain source-of-truth or subledger ownership decision. PRDs and specifications remain required after the architecture boundary is established. | High | Before PMS financial / guest-folio implementation begins |
| **Enterprise Document, Evidence and Records Management Architecture** | Create PRD/specification instead | Shared specification is sufficient for evidence boundaries governed by ADR-031. | Low | Prior to broad document module rollout |
| **External Integration and Data Exchange Boundary** | Create PRD/specification instead | Integration specifications are sufficient, constrained by existing resiliency (ADR-017) and privacy (ADR-031) ADRs. | Medium | External POS/PMS Integrations |
| **Reporting, BI, Data Warehouse and Cross-Tenant Benchmarking Governance** | Create PRD/specification instead | Specifications constrained by ADR-031 secondary-use rules are sufficient for now. | Low | Enterprise Analytics Expansion |
| **PMS Core Domain Architecture** | Create PRD/specification instead | PMS should begin with PRDs/specifications constrained by ADR-034. A monolithic ADR is not required. | High | PMS Implementation Start |
| **HRIS Workforce, Employment, Payroll and Privacy Boundary** | Defer without new document now | Future roadmap domain. Operational PRDs will drive this when triggered. | Low | HRIS Implementation Start |

## 6. Recommended Next Governance Sequence

1. **Budgeting / Forecasting / Encumbrance Readiness Review**: Conduct a focused cross-domain readiness review. Draft ADR-035 only when Budgeting, Forecasting, formal budget control, or accounting encumbrance implementation is authorized. (Architecture decision readiness review → potential new ADR)
2. **Cost Control Readiness Audit**: Continue the separate Cost Control audit workstream. (Review/Audit)
3. **PMS Core Module Specifications**: Begin PMS discovery PRDs and operational specifications constrained by ADR-034. Before guest-folio or PMS financial implementation, perform the Guest Ledger / Folio boundary review described in the candidate assessment. Do not automatically create a PMS umbrella ADR. (PRD/Specification → potential boundary review)
4. **Integration Specifications**: Draft technical specifications for external data exchange boundaries based on ADR-017 and ADR-031. (PRD/Specification)
5. **Night Audit Checkpoint Catalog**: Draft the operational checklist and checkpoint specification for the Night Audit process based on ADR-034. (Specification)

## 7. Explicit Architecture Verdict

**Finance Foundation**: Architecture Ready, Implementation Pending
**Enterprise Security & Privacy**: Architecture Ready, Implementation Pending
**Procurement & Tax Governance**: Architecture Ready, Implementation Pending
**PMS Foundation**: Architecture Prerequisites Established, Module Design Pending
**Cost Control**: Separate Audit / Readiness Workstream, Not Included in This ADR Update

The highest-priority remaining architecture decision is **Budgeting, Forecasting and Formal Accounting Encumbrance Architecture** (ADR-035 candidate).

The current ADR portfolio is architecturally sufficient to continue controlled Finance, Security, Privacy, Procurement, Tax, and PMS discovery work. It is not a claim of production readiness or implementation completion. The next ADR is conditional on a real cross-domain implementation trigger, not on document count. The highest-priority candidate remains Budgeting, Forecasting and Formal Accounting Encumbrance Architecture, subject to the stated readiness trigger.
