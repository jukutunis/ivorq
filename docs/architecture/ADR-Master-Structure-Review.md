# IVORQ ADR Master Structure Review

## 1. ADR Portfolio Delivery Status

| ADR Range / ADR | Title | Git Baseline Status | ADR Decision Status | Architecture Role |
| :--- | :--- | :--- | :--- | :--- |
| ADR-001 to ADR-029 | Various Finance & Core Foundations | Tracked / Committed | Various (Immutable) | Finance & Core Foundation Baseline |
| ADR-030 | Identity, Authentication and Session Governance | Tracked / Committed | Proposed | Enterprise Security Boundary |
| ADR-031 | Data Privacy, PII, Retention and Data Residency Governance | Tracked / Committed | Proposed | Enterprise Privacy Boundary |
| ADR-032 | Purchasing, Procurement and Contract Management Architecture | Tracked / Committed | Proposed | Procurement Governance |
| ADR-033 | Global Tax and Jurisdiction Compliance Architecture | Tracked / Committed | Proposed | Tax Governance |
| ADR-034 | Night Audit and Hospitality Business Date Architecture | Tracked / Committed | Approved | PMS Business-Date Foundation |

### Portfolio Capacity and Guardrails
This review's original baseline covered ADR-001 through ADR-034. It is historical portfolio analysis, not the complete current repository ADR inventory. Later ADRs exist, including ADR-087 and ADR-088. ADR numbering must not be used to calculate a fixed remaining "ADR capacity"; architecture creation remains trigger-based, not quota-based. A new ADR must be created only when it records a cross-domain, long-lived, difficult-to-reverse architecture decision. A new module, workflow, UI, report, or implementation need does not automatically justify a new ADR.

## 2. Completed Roadmap Items
The previously planned next five ADRs (ADR-030 through ADR-034) represent completed document delivery milestones. 
- They have been created and baseline-committed.
- ADR-030 through ADR-033 remain **Proposed**. ADR-034 was activated as **Approved** on 2026-07-16.
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
| Night Audit and hospitality business-date foundation | Approved | Architecture activated on 2026-07-16; BD-A1 authoritative Property Business Date runtime, FD-B11 read integration, NA-A1 Night Audit run/active-lock foundation, and FD-B12 read-only Front Desk lock integration are accepted. FD-B13 is accepted and canonical. ADR-089 is Approved and canonical at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is current. Later runtime prerequisites remain locked. | ADR-034, ADR-089 |
| PMS readiness | Partial accepted foundations | PMS Guest Ledger, PMS Cashiering, Front Desk, General Cashier, GLF-D, FD-B9, GC-A1, FD-B10, BD-A1, FD-B11, NA-A1, FD-B12, FD-B13, and ADR-089 are accepted per the current contract baseline. ADR-089 is Approved and canonical because checkout execution orchestration is cross-domain, long-lived, and difficult to reverse. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is under implementation as the PMS terminal financial attestation foundation. Later runtime prerequisites remain locked, checkout execution remains unauthorized, no runtime checkout command has been introduced, and the FD-B13 verdict remains `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`. | ADR-034, ADR-087, ADR-088, ADR-089 |
| Guest Ledger, Folio, and hospitality financial subledger boundary | Active | Completed by ADR-088; durable ownership boundary established across PMS Guest Ledger, PMS Cashiering, General Cashier, Accounting, Finance, Front Desk, and Business Date / Night Audit. Implementation remains package-scoped and does not imply checkout execution. | ADR-088 |
| Cost Control readiness | Planning | Separate Audit / Readiness Workstream | Not included in this ADR update |
| HRIS readiness | Deferred | Deferred roadmap domain | Requires future PRDs |
| Inventory Ledger posting governance | Approved | ADR-035, ADR-036, and ADR-037 already exist and are Approved. Their architecture decisions are no longer future ADR candidates; implementation status remains separate from decision status. | ADR-035, ADR-036, ADR-037 |
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
| **Inventory Ledger Canonical Persistence and Temporal Integrity** | Completed by ADR-035 - no longer a candidate | ADR-035 now exists and is Approved. It promotes `inventory_transactions` as the canonical Inventory Ledger persistence table and defines immutable `business_date`, `occurred_at`, and retained `created_at` temporal semantics. Implementation status remains distinct from architecture decision status. This ADR-034 activation package does not modify or reopen ADR-035. | Completed | ADR-035 approved |
| **Inventory Ledger Source Identity, Idempotency, Reversal and Correction** | Completed by ADR-036 - no longer a candidate | ADR-036 now exists and is Approved. It defines source document/line identity, server-generated idempotency keys, duplicate protection, immutable reversal, and correction semantics. Implementation status remains distinct from architecture decision status. This ADR-034 activation package does not modify or reopen ADR-036. | Completed | ADR-036 approved |
| **Inventory Posting Control and Closing Lock Protocol** | Completed by ADR-037 - no longer a candidate | ADR-037 now exists and is Approved. It defines the future posting-versus-closing consistency protocol, including PropertyBusinessDate, FinancialPeriod, and InventoryStock lock ordering. Implementation status remains distinct from architecture decision status. This ADR-034 activation package does not modify or reopen ADR-037. | Completed | ADR-037 approved |
| **Legal Entity, Accounting Scope and Tax Registration Architecture** | Create ADR later when trigger is met | Interacts with Tenant, intercompany, and tax registration, but not required immediately. | Medium | Prior to complex multi-entity/group accounting expansion |
| **Guest Ledger, Folio and Hospitality Financial Subledger Architecture** | Completed by ADR-088 - no longer a candidate | ADR-088 now exists and is Active. The durable Guest Ledger, Folio, PMS Cashiering, General Cashier, Accounting, Finance, Front Desk, and Business Date / Night Audit ownership boundary has been established. Implementation remains package-scoped and does not imply checkout execution. | Completed | ADR-088 active |
| **Enterprise Document, Evidence and Records Management Architecture** | Create PRD/specification instead | Shared specification is sufficient for evidence boundaries governed by ADR-031. | Low | Prior to broad document module rollout |
| **External Integration and Data Exchange Boundary** | Create PRD/specification instead | Integration specifications are sufficient, constrained by existing resiliency (ADR-017) and privacy (ADR-031) ADRs. | Medium | External POS/PMS Integrations |
| **Reporting, BI, Data Warehouse and Cross-Tenant Benchmarking Governance** | Create PRD/specification instead | Specifications constrained by ADR-031 secondary-use rules are sufficient for now. | Low | Enterprise Analytics Expansion |
| **PMS Core Domain Architecture** | Create PRD/specification instead | PMS should begin with PRDs/specifications constrained by ADR-034. A monolithic ADR is not required. | High | PMS Implementation Start |
| **HRIS Workforce, Employment, Payroll and Privacy Boundary** | Defer without new document now | Future roadmap domain. Operational PRDs will drive this when triggered. | Low | HRIS Implementation Start |

## 6. Recommended Next Governance Sequence

1. **Inventory Ledger posting governance references**: Treat ADR-035, ADR-036, and ADR-037 as existing Approved architecture decisions, not future ADR candidates. This ADR-034 activation package does not modify or reopen those decisions. (Source-truth synchronization)
2. **Cost Control Readiness Audit**: Continue the separate Cost Control audit workstream. (Review/Audit)
3. **PMS Core Module Specifications**: Treat this as historical planning language unless a future package reauthorizes the scope. Current source truth now includes accepted PMS Guest Ledger and PMS Cashiering foundations, ADR-088's active Guest Ledger / Folio ownership boundary, accepted BD-A1 authoritative Property Business Date runtime, accepted FD-B11 Front Desk Business Date read integration, accepted NA-A1 Night Audit run/active-lock foundation, accepted FD-B12 read-only Front Desk Night Audit close-lock integration, accepted FD-B13 checkout execution readiness review, and Approved ADR-089 at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is under implementation as the current runtime prerequisite. Checkout implementation remains locked. No runtime checkout command, route, permission, migration, seeder, sensitive intent, or state transition has been introduced. PR #22 language is historical. (PRD/Specification -> package-scoped delivery)
4. **Integration Specifications**: Draft technical specifications for external data exchange boundaries based on ADR-017 and ADR-031. (PRD/Specification)
5. **Night Audit Checkpoint Catalog**: Draft the operational checklist and checkpoint specification for the Night Audit process based on ADR-034 only after the controlled Business Date / Night Audit package sequence authorizes that scope. NA-A1 does not authorize a checkpoint catalog. (Specification)

## 7. Explicit Architecture Verdict

**Finance Foundation**: Architecture Ready, Implementation Pending
**Enterprise Security & Privacy**: Architecture Ready, Implementation Pending
**Procurement & Tax Governance**: Architecture Ready, Implementation Pending
**PMS Foundation**: Partial Accepted Foundations, BD-A1 Business Date Runtime Accepted, FD-B11 Accepted, NA-A1 Night Audit Run/Lock Foundation Accepted, FD-B12 Front Desk Lock Read Integration Accepted, FD-B13 Checkout Execution Readiness Review Accepted, ADR-089 Checkout Orchestration Architecture Approved at `1682dec0fb7f654e77888a476b4ec55a1507610b`, NA-A2 Accepted at `4241e83e6f9e470a7ff5407179cadc166fc7b555`, GLF-E under implementation
**Cost Control**: Separate Audit / Readiness Workstream, Not Included in This ADR Update

ADR-035, ADR-036, and ADR-037 already exist and are Approved. Their controlled Inventory Ledger posting governance decisions are no longer future ADR candidates. Implementation status remains distinct from architecture decision status, and this ADR-034 activation package does not modify or reopen those decisions.

The current ADR portfolio is architecturally sufficient to continue controlled Finance, Security, Privacy, Procurement, Tax, and package-scoped PMS/Business Date work. It is not a claim of full PMS production readiness or implementation completion. BD-A1, FD-B11, NA-A1, FD-B12, FD-B13, and ADR-089 are accepted; ADR-089 is Approved and canonical at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is under implementation as the PMS terminal financial attestation foundation. Later runtime prerequisite packages remain locked. Checkout implementation, Business Date close/advance/reopen, Night Audit checkpoints, and runtime checkout command activation remain locked or separately unauthorized. No runtime checkout command has been introduced.
