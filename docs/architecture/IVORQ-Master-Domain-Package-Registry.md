# IVORQ Master Domain Package Registry

## Registry status

- Review date: 2026-08-15
- Canonical branch: `ivorq-enterprise-core`
- Canonical SHA reviewed: `c5f12ef60b55a31f402b2c4166b7ef2d807cf7d8`
- Package Execution Contract: Version 1.21, unchanged
- Registry role: governance and workstream selection only
- Registered master domains: 19
- Runtime authorization: none

`NO_PACKAGE_21_ACTIVATED`

This registry groups logical bounded-domain workstreams. A master domain is an ownership and planning boundary; it is not required to map one-to-one to a source folder. A runtime package is a separately authorized, narrowly bounded delivery against one master domain. A registry row, priority, readiness class, or next-candidate identifier never authorizes implementation.

## Package naming standard for new workstreams

| Form | Meaning |
|---|---|
| `<DOMAIN>-R#` | Readiness or current-state revalidation |
| `<DOMAIN>-G#` | Governance, architecture synchronization, or boundary freeze |
| `<DOMAIN>-P##` | Runtime implementation package |
| `<DOMAIN>-C#` | Governance closure |

This standard applies only to new domain-coded workstreams. Historical Packages 1-20, their commits, pull requests, identifiers, Contract provenance, and accepted meanings remain unchanged. They must not be renamed or retrofitted into the new scheme.

## Status taxonomy and closure semantics

This registry uses only: `CANONICAL_CLOSED`, `ACCEPTED_PARTIAL`, `SOURCE_PRESENT_REVALIDATION_REQUIRED`, `ARCHITECTURE_READY_IMPLEMENTATION_AUDIT_REQUIRED`, `READINESS_AUDIT_REQUIRED`, `SPECIFICATION_REQUIRED`, `ROADMAP_DEFERRED`, and `ROADMAP_DISCOVERY_REQUIRED`.

`CANONICAL_CLOSED` always names an explicitly bounded scope. For MPD-HKG it applies only to the current Housekeeping turnover/readiness package train accepted through Packages 11-20. It does not mean all Housekeeping capability is complete. Closure records that a bounded train has reached its accepted governance endpoint; later unrelated work requires fresh source review and explicit Owner authorization.

## Master registry

Evidence labels: `SOURCE_PROVEN` means current source or schema exists; `TEST_PROVEN` means committed tests exercise the behavior; `GOVERNANCE_PROVEN` means an accepted ADR, Contract record, or accepted package record governs it; `DOCUMENTATION_ONLY` means no matching runtime was found; `INFERENCE` identifies a selection-level conclusion that requires its own future audit.

| Code and logical scope | Source directories / source presence | Governing ADRs and current documents | Accepted provenance and documentation currency | Readiness | Major dependencies | Next source-backed candidate | Priority |
|---|---|---|---|---|---|---|---|
| **MPD-FND** - Foundation, tenancy, Property, identity, organizational foundation | `Modules/Foundation/{Property,User,Department,Authentication,Authorization,Audit,Approval,Outbox}`; substantial source (`SOURCE_PROVEN`) | ADR-001, ADR-002, ADR-003, ADR-030; `docs/audits/Property-Isolation-Audit.md` | Foundation source remains active; general knowledge-base material includes archived documents and requires source revalidation (`INFERENCE`) | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | SEC, every Property-scoped operational domain | `FND-R1` | P2 |
| **MPD-SEC** - Security, Authorization, Privacy, Data Governance | `Modules/Foundation/{Authentication,Authorization,Audit,User}` and shared policy/session controls (`SOURCE_PROVEN`) | ADR-002, ADR-029, ADR-030, ADR-031, ADR-066; `docs/security/` | Sensitive confirmation and Housekeeping/Finance permission controls have accepted runtime provenance; the broader security/privacy portfolio is not canonically closed (`GOVERNANCE_PROVEN`) | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FND and all domains; especially FIN, PMS, HKG | `SEC-R1` | P1 |
| **MPD-PMS** - PMS, Front Desk, Guest Ledger, Folio, Cashiering | `Modules/Operations/{PMS,FrontDesk}` plus `Modules/Finance/GeneralCashier` and `Modules/Operations/GeneralCashier` (`SOURCE_PROVEN`) | ADR-034, ADR-084, ADR-087, ADR-088, ADR-089; `docs/architecture/FD-B13-Checkout-Execution-Readiness-Review.md` | Accepted package-scoped foundations include checkout and related handoffs; broad PMS PRDs under `docs/knowledge-base/07-PMS/` predate current runtime and require revalidation | `ACCEPTED_PARTIAL` | FND, SEC, BDN, FIN, HKG, ENG | `PMS-R1` | P1 |
| **MPD-HKG** - Housekeeping | `Modules/Operations/Housekeeping` (`SOURCE_PROVEN`) | ADR-086; `docs/architecture/Housekeeping-Package-20-Final-Governance-Closure-Review.md` | Packages 11-20 are accepted for the current turnover/readiness train; PR #54 merged at the reviewed canonical SHA (`GOVERNANCE_PROVEN`, `TEST_PROVEN`) | `CANONICAL_CLOSED` for the current turnover/readiness package train only | PMS, SEC, FND | No new candidate activated | P3 monitoring only |
| **MPD-BDN** - Business Date and Night Audit | `Modules/Foundation/Property` and `Modules/Operations/NightAudit` (`SOURCE_PROVEN`) | ADR-034, ADR-089; Contract 1.21 accepted predecessor records | BD-A1, NA-A1, NA-A2 and read integrations have accepted package provenance; full close/advance/reopen/checkpoint scope is not declared complete | `ACCEPTED_PARTIAL` | FND, PMS, FIN, INV, CC, TAX | `BDN-R1` | P2 |
| **MPD-FIN** - GL, AP, AR, Banking, General Cashier, FX, reporting | `Modules/Finance/{GeneralLedger,AccountsPayable,AccountsReceivable,Payables,Banking,GeneralCashier,FxReference,Treasury}` and `Modules/Operations/GeneralCashier` (`SOURCE_PROVEN`) | ADR-004, ADR-013, ADR-016, ADR-018, ADR-019, ADR-028, ADR-047-ADR-078; Finance implementation plans and audits under `docs/architecture/Finance/` | Multiple accepted runtime trains and commit provenance exist; breadth, parallel legacy/controlled paths, and dated foundation reviews require an end-to-end audit | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FND, SEC, BDN, CC, INV, PROC, TAX, PMS | `FIN-R1` | P0 |
| **MPD-INV** - Inventory Ledger, stock, transfer, reversal | `Modules/Operations/Inventory`; both `inventory_transactions` and `inventory_stock_movements` persistence are present (`SOURCE_PROVEN`) | ADR-008-ADR-010, ADR-035-ADR-037, ADR-041-ADR-046, ADR-079-ADR-083; `docs/02-operations/reviews/Inventory-Validation-Audit.md` | Controlled posting and movement tests exist, but two forward-ledger representations and an active inherited reversal-test debt require canonical reconciliation; historical foundation docs are stale | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FND, BDN, CC, PROC, FIN, POS | `INV-R1` | P0 |
| **MPD-CC** - Cost Control, Cost Ledger, AVCO, valuation | `Modules/Finance/CostControl`; `cost_ledger_entries` and `cost_avco_states` are durable (`SOURCE_PROVEN`, `TEST_PROVEN`) | ADR-010-ADR-017, ADR-041-ADR-047, ADR-082-ADR-083; `docs/architecture/CostControl/Cost-Control-PRD.md`; historical Sprint 15 audit/specifications | Sprint 15 commits delivered persistence and enrolled-path valuation, but the PRD and historical readiness documents still describe Cost Control as only read/analyze and claim absent ledgers; current source therefore outranks those documents | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | INV, FIN, PROC, BDN, FND, TAX, POS | `CC-G1` after completed `CC-R1` | P0; selected first |
| **MPD-PROC** - Procurement, Purchasing, Receiving, Vendor controls | `Modules/Operations/{Purchasing,Receiving}` plus Purchasing/Receiving aggregates in Inventory and tenant-owned Vendor source (`SOURCE_PROVEN`) | ADR-006, ADR-011, ADR-014, ADR-015, ADR-032, ADR-047, ADR-080, ADR-083; Purchasing and Receiving plans/reviews | PO, receiving, commercial-evidence, supplier-invoice, and GRNI/AP integration source is present; documents span older and newer models and require a canonical audit | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FND, SEC, INV, CC, FIN, TAX | `PROC-R1` | P0 |
| **MPD-TAX** - Tax and multi-jurisdiction | No dedicated current Tax runtime module found (`DOCUMENTATION_ONLY`) | ADR-025, ADR-033 and tax-sensitive clauses in ADR-060/ADR-083 | Architecture exists, but no dedicated runtime provenance was established by this selection audit | `ARCHITECTURE_READY_IMPLEMENTATION_AUDIT_REQUIRED` | FND, SEC, FIN, PROC, PMS, POS | `TAX-R1` | P2 |
| **MPD-ENG** - Engineering, Maintenance, Asset, Contractor PTW | `Modules/Operations/{Engineering,EngineeringWorkspace,Maintenance,AssetManagement,ContractorPTW,WorkOrder}` (`SOURCE_PROVEN`) | ADR-027, ADR-085; `docs/completion-reports/Sprint-ENG-A1-Engineering-Room-Availability-Readiness-Record.md`; engineering PRD/foundation reviews | ENG-A1 room-availability evidence is accepted; broader maintenance, asset, and contractor documentation requires current-source revalidation | `ACCEPTED_PARTIAL` | FND, SEC, PMS, HKG, FIN, DOC | `ENG-R1` | P1 |
| **MPD-SEM** - Sales and Event Management, BEO, Function Space | `Modules/SalesAndEventManagement` and `Modules/FunctionSpace` (`SOURCE_PROVEN`) | Sales/Event architecture audits under `docs/architecture/sales-event-management/`; Function Space foundation source | Sprint 14.8.x and Function Space commits prove runtime presence; historical audit/recovery records require a consolidated readiness check | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FND, SEC, CAL, PLAN, WORK, PMS, POS | `SEM-R1` | P1 |
| **MPD-CAL** - Operations Calendar | `Modules/OperationsCalendar` (`SOURCE_PROVEN`) | `docs/architecture/operations-calendar/sprint_14_7_0_event_calendar_architecture_audit.md` and `sprint_14_7_1_operations_calendar_revision.md` | Foundation commit exists; documentation is point-in-time and should be revalidated before expansion | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | SEM, ENG, HKG, WORK, FND | `CAL-R1` | P2 |
| **MPD-PLAN** - Planning, Budgeting, Forecasting | `Modules/PlanningAndBudgeting` and `Modules/Finance/{Budgeting,Forecasting}` (`SOURCE_PROVEN`) | Budget/Forecast implementation plans and PRDs; planning contracts | Foundation/remediation commits prove source, but duplicate module placement and older plans require ownership/readiness audit | `SOURCE_PRESENT_REVALIDATION_REQUIRED` | FIN, SEM, MET, WORK, FND | `PLAN-R1` | P2 |
| **MPD-WORK** - Workforce Planning and future HRIS | `Modules/WorkforcePlanning` contains contracts only; HRIS PRDs exist under `docs/knowledge-base/10-HRIS/` (`SOURCE_PROVEN` for limited contracts, `DOCUMENTATION_ONLY` for HRIS) | ADR-Master HRIS deferral; workforce contracts and HRIS PRDs | Workforce foundation commit exists, but no dedicated HRIS runtime was found; PRDs are roadmap material | `ROADMAP_DEFERRED` | FND, SEC, ENG, HKG, SEM, CAL | `WORK-R1` only after Owner reactivation | P2 |
| **MPD-MET** - Operational Metrics, BI, Analytics | `Modules/OperationalMetrics` contains contracts only (`SOURCE_PROVEN` limited) | ADR-Master reporting/BI assessment; reporting specifications in the knowledge base | No dedicated analytics runtime provenance was established; current material is partial specification | `SPECIFICATION_REQUIRED` | All source domains, especially FIN, CC, PMS, SEM; SEC/privacy | `MET-R1` | P2 |
| **MPD-INT** - External Integration and Data Exchange | No dedicated bounded module; integration code is dispersed and a knowledge-base integration specification exists (`INFERENCE`) | ADR-017, ADR-031; `docs/knowledge-base/03-Foundation-Engines/integration-spec.md` | Event/outbox primitives exist, but no canonical external integration boundary or accepted domain package was found | `ROADMAP_DISCOVERY_REQUIRED` | SEC, FND, PMS, POS, FIN, BDN, DOC | `INT-R1` | P1 |
| **MPD-DOC** - Document, Evidence, Records Management | No dedicated bounded runtime module; evidence/attachments are dispersed across domains (`INFERENCE`) | ADR-031; documentation-governance records; attachment specifications | Repository documentation is extensive, but a shared records/evidence runtime and current specification were not established | `SPECIFICATION_REQUIRED` | SEC, FND and every evidence-producing domain | `DOC-R1` | P3 |
| **MPD-POS** - POS and F&B enterprise transactions | No dedicated current runtime module found. POS PRDs exist under `docs/knowledge-base/08-POS/` only (`DOCUMENTATION_ONLY`) | ADR-020, ADR-021, ADR-022, ADR-034 and POS PRDs | No dedicated POS bounded-context runtime or accepted package provenance was proven | `ROADMAP_DISCOVERY_REQUIRED` | FND, SEC, PMS, INV, CC, TAX, BDN, SEM | `POS-R1` | P1 |

## Priority interpretation

- P0 domains sit on the physical-to-financial evidence chain and have current source plus material governance staleness or cross-domain centrality.
- P1 domains have operational value or integration/security centrality but do not outrank the immediate Inventory/Cost/Finance/Procurement evidence chain.
- P2 domains have source foundations, architecture, or deferred product material that needs scoped revalidation before implementation.
- P3 is intentionally low because no current source evidence requires immediate runtime delivery.

Priorities select audits; they do not activate `R`, `G`, `P`, or `C` packages. The completed selection audit chose `CC-R1_COST_CONTROL_CANONICAL_REVALIDATION_AND_BOUNDARY_AUDIT`. No global Package 21 exists, and no `CC-P01` is activated.

## Housekeeping closure preservation

PR #54 merged the Package 20 governance closure into canonical at `c5f12ef60b55a31f402b2c4166b7ef2d807cf7d8`. `HOUSEKEEPING_TURNOVER_READINESS_PACKAGE_TRAIN_CLOSED` is preserved exactly for the accepted current train. This registry does not reopen it, broaden its closure claim, or rename any historical package.

## Authorization boundary

This document is a registry and selection aid. It grants no permission for a migration, model, service, controller, request, policy, route, seeder, permission, Sensitive Action intent, UI, dependency, database change, test change, deployment, or runtime implementation.

`NO_PACKAGE_21_ACTIVATED`
