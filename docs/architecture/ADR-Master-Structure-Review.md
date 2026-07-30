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
| Night Audit and hospitality business-date foundation | Approved | Architecture activated on 2026-07-16; BD-A1 authoritative Property Business Date runtime, FD-B11 read integration, NA-A1 Night Audit run/active-lock foundation, and FD-B12 read-only Front Desk lock integration are accepted. FD-B13 is accepted and canonical. ADR-089 is Approved and canonical at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 is accepted and merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. The GLF-E savepoint defect is corrected. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is accepted and merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`. FD-C2 is accepted and merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`. Package 8 is accepted and merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e`. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`. Package 11 is governance-authorized only and requires a separate Draft PR for runtime. | ADR-034, ADR-089 |
| PMS readiness | Partial accepted foundations | PMS Guest Ledger, PMS Cashiering, Front Desk, General Cashier, GLF-D, FD-B9, GC-A1, FD-B10, BD-A1, FD-B11, NA-A1, FD-B12, FD-B13, ADR-089, NA-A2, GLF-E, GLF-E-S1, GC-A2, FD-C1, FD-C2, Package 8, and Package 9 are accepted per the current contract baseline. ADR-089 is Approved and canonical because checkout execution orchestration is cross-domain, long-lived, and difficult to reverse. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`, with accepted final source SHA `77a82dd3951b7bb5804efb496b8939163ba2076d` and accepted feature/metadata SHA `df27dc8b7b33caf98ba2dd61305c652069780601`. Checkout execution is implemented as server-projected authority, not browser-granted authority. Package 11 is Housekeeping-owned downstream handoff consumption and room turnover start, not a continuation of Front Desk checkout execution. The FD-B13 verdict remains historical pre-Package-9 evidence: `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`. | ADR-034, ADR-087, ADR-088, ADR-089 |
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
3. **PMS Core Module Specifications**: Treat this as historical planning language unless a future package reauthorizes the scope. Current source truth now includes accepted PMS Guest Ledger and PMS Cashiering foundations, ADR-088's active Guest Ledger / Folio ownership boundary, accepted BD-A1 authoritative Property Business Date runtime, accepted FD-B11 Front Desk Business Date read integration, accepted NA-A1 Night Audit run/active-lock foundation, accepted FD-B12 read-only Front Desk Night Audit close-lock integration, accepted FD-B13 checkout execution readiness review, Approved ADR-089 at `1682dec0fb7f654e77888a476b4ec55a1507610b`, accepted FD-C1, accepted FD-C2 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`, accepted Package 8 at `2395884479a69dfa3a876728137676e61a7b374e`, and accepted Package 9 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`. Package 11 is the current governance-authorized Housekeeping-owned downstream handoff consumption boundary; runtime remains locked until a separate Package 11 runtime package is delivered, reviewed, accepted, and merged. PR #22 language is historical. (PRD/Specification -> package-scoped delivery)
4. **Integration Specifications**: Draft technical specifications for external data exchange boundaries based on ADR-017 and ADR-031. (PRD/Specification)
5. **Night Audit Checkpoint Catalog**: Draft the operational checklist and checkpoint specification for the Night Audit process based on ADR-034 only after the controlled Business Date / Night Audit package sequence authorizes that scope. NA-A1 does not authorize a checkpoint catalog. (Specification)

## 7. Explicit Architecture Verdict

**Finance Foundation**: Architecture Ready, Implementation Pending
**Enterprise Security & Privacy**: Architecture Ready, Implementation Pending
**Procurement & Tax Governance**: Architecture Ready, Implementation Pending
**PMS Foundation**: Partial Accepted Foundations, BD-A1 Business Date Runtime Accepted, FD-B11 Accepted, NA-A1 Night Audit Run/Lock Foundation Accepted, FD-B12 Front Desk Lock Read Integration Accepted, FD-B13 Checkout Execution Readiness Review Accepted, ADR-089 Checkout Orchestration Architecture Approved at `1682dec0fb7f654e77888a476b4ec55a1507610b`, NA-A2 Accepted at `4241e83e6f9e470a7ff5407179cadc166fc7b555`, GLF-E Accepted at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`, GLF-E-S1 Accepted at `f91621b58fe5743ed2a60980a70475cae40331bc`, GC-A2 Accepted at `f0635b6c402ea095a1cd21b1a1510008c49e7739`, FD-C1 Accepted and Merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`, FD-C2 Accepted and Merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`, Package 8 Accepted and Merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e`, Package 9 Accepted and Merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`, Package 11 Governance Authorized Only
**Cost Control**: Separate Audit / Readiness Workstream, Not Included in This ADR Update

ADR-035, ADR-036, and ADR-037 already exist and are Approved. Their controlled Inventory Ledger posting governance decisions are no longer future ADR candidates. Implementation status remains distinct from architecture decision status, and this ADR-034 activation package does not modify or reopen those decisions.

The current ADR portfolio is architecturally sufficient to continue controlled Finance, Security, Privacy, Procurement, Tax, and package-scoped PMS/Business Date work. It is not a claim of full PMS production readiness or implementation completion. BD-A1, FD-B11, NA-A1, FD-B12, FD-B13, and ADR-089 are accepted; ADR-089 is Approved and canonical at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 is accepted and merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. The GLF-E savepoint defect is corrected. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is accepted and merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`. FD-C2 is accepted and merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`. Package 8 is accepted and merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e`. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`. Checkout execution is implemented and `CAN_EXECUTE_SERVER_PROJECTED` is the current governance marker: not universally true, but resolved server-side per actor, Company, Property, stay, Business Date, Night Audit, financial, cashier, final-review, permission, confirmation, and idempotency context. Business Date close/advance/reopen and Night Audit checkpoints remain separately unauthorized. Package 11 runtime remains separately unauthorized until its own Draft PR.

### FD-C1 Governance Synchronization (2026-07-23)

Contract Version 1.12. Canonical SHA: `f0635b6c402ea095a1cd21b1a1510008c49e7739`. GC-A2 accepted merge at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 activated as the then-current authorized runtime prerequisite. No new ADR required because ADR-087 and ADR-089 already defined the boundary. Historical pre-Package-9 later checkout packages remained locked. Historical pre-Package-9 `can_execute=false`. Historical pre-Package-9 checkout unauthorized.

### Historical FD-C2 Governance Synchronization (2026-07-23)

Contract Version 1.13. Canonical SHA: `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`. FD-C1 accepted and merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e` (feature head: `a05e9296578bc0672792f531240837f9149b583b`, 7 commits, 10 files). At that historical Version 1.13 point, FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation was activated as the authorized runtime package. FD-C2 is now accepted and merged. FD-C2 was foundation-only: dedicated checkout-to-Housekeeping handoff/outbox persistence, additive migration, minimized identifier-only payload, application and PostgreSQL integrity controls, pending/retryable delivery foundation, idempotent claim/delivery contract. Historical pre-Package-9 FD-C2 did not execute checkout, transition a stay to CHECKED_OUT, create FrontDeskCheckoutExecution in production, create checkout orchestration, create a final checkout command, add a write route, create execute permission, create sensitive confirmation intent, update Housekeeping readiness, mutate foreign domains, generalize inventory outbox_messages, create UI, change can_execute, remove CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED, implement Package 8, or implement Package 9. No new ADR was required — ADR-087 and ADR-089 remained sufficient governing architecture. At that historical point, Package 8 had not yet been activated and Package 9 remained locked. Historical pre-Package-9 `can_execute=false`. Historical pre-Package-9 checkout unauthorized. Front Desk zero-failure baseline: 534 tests / 4817 assertions / 0 failures / 0 errors. Complete active runner: 8 passed / 6 MISMATCH / 0 skipped — MISMATCH results are a test-runner / DatabaseMigrations infrastructure exception, not FD-C1 source failure, not new accepted product debt.

### Package 8 Governance Activation (2026-07-26)

Contract Version 1.14. Canonical predecessor: `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`. FD-C2 is accepted and merged through PR #38 with accepted feature head `ce05c4217dcf763ccd5e308f66a01201975036a1`. At that Version 1.14 point, Package 8 - Checkout Sensitive Confirmation, Durable One-Time Consumption, and Execute Permission Foundation was activated as the current authorized package boundary. No new ADR required - ADR-087 and ADR-089 remain sufficient governing architecture.

Package 8 is eligible only for a separate runtime implementation package. This governance activation creates no runtime source, migration, model, enum, service, permission seeder, Sensitive Action Confirmation registration, durable consumption persistence, command, route, controller, request class, policy, UI, React/TypeScript, test, baseline metadata, queue, worker, event, scheduler, WebSocket, external integration, checkout execution, stay transition, Housekeeping readiness mutation, or foreign-domain mutation.

Future checkout Sensitive Action Confirmation intent: `frontdesk-checkout-execution`. Future checkout execute permission: `frontdesk.checkout-execution.execute`. Confirmation is not authorization and cannot grant execute permission. Authorization must occur before stay resolution; browser-supplied property, actor, role, permission, or authorization state is never trusted. Unauthorized actors must not cause a stay query. Boundary-view permission does not imply execute permission. Execute permission alone does not make checkout executable while Package 9 remains absent.

Future durable one-time confirmation consumption must occur inside the same PostgreSQL checkout transaction, after approved locks and execution-time attestations, before or atomically with the first persistent checkout mutation, and must commit with immutable checkout execution evidence, terminal stay transition, and transactional Housekeeping handoff. It must roll back when the checkout transaction rolls back, prevent successful duplicate consumption through database-enforced uniqueness, fail closed when already consumed, and never rely on session invalidation as authoritative consumption. Same-idempotency replay of an already committed checkout returns immutable committed evidence without requiring or consuming another confirmation.

Front Desk remains the future checkout orchestration owner, but must not directly mutate foreign-domain lifecycle tables. PMS Guest Ledger, General Cashier, Business Date / Night Audit, and Housekeeping ownership invariants remain preserved. FD-C2 handoff remains dedicated and must not generalize the Inventory outbox. Engineering checkout handoff remains non-mandatory. Housekeeping readiness itself remains `NOT_REQUIRED` as a checkout gate unless later architecture explicitly changes it.

Runtime status after activation:

```text
historical pre-Package-9 can_execute=false
historical pre-Package-9 CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED
historical pre-Package-9 checkout unauthorized at runtime
```

At the Package 8 governance activation point, Package 9 remained locked: final checkout command, terminal transaction orchestration, write route, stay terminal transition command, production Housekeeping handoff creation through checkout execution, final interaction layer, and checkout execution UI were unauthorized. Version 1.15 now governance-authorizes Package 9 runtime as a separate future package, but this governance package still implements none of that runtime.

### Package 9 Historical Governance Activation (2026-07-27)

Contract Version 1.15 historically activated Package 9 after accepted Package 8. At that historical pre-Package-9 point, the Package 9 runtime was still unauthorized and the old runtime markers were valid only as pre-implementation evidence.

### Package 10 Governance Synchronization and Package 11 Activation (2026-07-30)

Contract Version 1.16. Canonical predecessor: `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`; accepted Package 9 feature and metadata SHA: `df27dc8b7b33caf98ba2dd61305c652069780601`; accepted Package 9 final source SHA: `77a82dd3951b7bb5804efb496b8939163ba2076d`.

Package 9 accepted validation evidence: Scenario I focused 3 tests / 130 assertions / 0 failures / 0 errors; Package 9 isolated concurrency 15 tests / 417 assertions / 0 failures / 0 errors; Package 9 focused final batch 41 tests / 708 assertions / 0 failures / 0 errors; Package 8 confirmation 33 tests / 346 assertions / 0 failures / 0 errors; adjacent NA-A2 + GLF-E + registered GC-A2 150 tests / 1447 assertions / 0 failures / 0 errors; exact Front Desk baseline 68 classes, 729 tests / 5539 assertions / 0 failures / 0 errors, exit code 0; RegressionBaselineManifestTest 34 tests / 1150 assertions / 0 failures / 0 errors; complete active baseline runner 14 passed / 0 failed / 0 skipped, 1205 tests / 9378 assertions, exit code 0; Inventory Reversal accepted inherited debt 8 tests / 72 assertions / 2 accepted errors.

Current runtime classification:

```text
PACKAGE_9_RUNTIME_ACCEPTED_AND_MERGED
CHECKOUT_EXECUTION_IMPLEMENTED
CAN_EXECUTE_SERVER_PROJECTED
PACKAGE_11_GOVERNANCE_ACTIVATION_AUTHORIZED
PACKAGE_11_RUNTIME_REQUIRES_SEPARATE_DRAFT_PR
NO_NEW_ADR_REQUIRED
ADR_086_AMENDMENT_REQUIRED_AND_INCLUDED
ADR_040_ADR_086_ADR_087_ADR_089_REMAIN_GOVERNING
```

`CAN_EXECUTE_SERVER_PROJECTED` is not universally true. It is resolved server-side per actor, Company, Property, stay, Business Date, Night Audit, financial, cashier, final-review, permission, confirmation, and idempotency context. Browser input cannot grant execution authority.

Package 9 current runtime truth: execute permission `frontdesk.checkout-execution.execute`; confirmation intent `frontdesk-checkout-execution`; authorization before requested-stay query; non-disclosing unknown and cross-Property stay behavior after authorization succeeds; final browser execution input remains identifier-only with route stay identifier plus `idempotency_key`; password is confirmation-preparation input only and is never execution input; checkout runs through the accepted controlled PostgreSQL transaction; same-key same-stay committed replay returns immutable evidence without duplicate consumption, execution, handoff, audit event, or stay transition; conflicting authoritative identity fails closed; Front Desk owns orchestration, terminal stay transition, execution evidence, Package 9 idempotency, and creation of the checkout-specific Housekeeping handoff; Housekeeping handoff is created as PENDING; Front Desk does not claim that Housekeeping room readiness changed; Housekeeping remains lifecycle owner of post-checkout room turnover; PMS, PMS Cashiering, General Cashier, Business Date, Night Audit, Housekeeping readiness, Engineering, Accounting, GL, AR, tax, revenue, and financial-period ownership remain unchanged; no external HTTP call is performed inside the checkout transaction; and Package 9 does not authorize Package 11 runtime automatically.

Package 11 - Housekeeping Checkout Handoff Consumption and Room Turnover Start - is not a continuation of Front Desk checkout execution. It is a Housekeeping-owned downstream consumption package for checkout-specific FD-C2 handoffs. Source determination for the Package 10 correction proves no canonical durable idempotent checkout-turnover intake target currently exists. `CleaningTaskService::generateDepartureTask()` directly creates a `checkout_cleaning` task without accepted durable source-identity, source-hash, checkout-execution, handoff, or idempotency protection, and a `CleaningTask` row alone is not accepted as the recovery identity unless Package 11 explicitly hardens it with the required source identity, uniqueness, immutability, and replay contract.

Package 11 must add one dedicated Housekeeping-owned durable checkout-turnover intake evidence boundary before marking an FD-C2 handoff DELIVERED. Task creation and readiness mutation must be correlated to that durable intake identity; duplicate delivery must resolve the same intake and same task/outcome; and crash after Housekeeping commit but before FD-C2 markDelivered must recover through the intake identity. Package 11 must not create a parallel generic workflow framework.

Package 11 must use actual canonical Housekeeping state names from source: readiness states `dirty`, `waiting_cleaning`, `cleaning`, `waiting_inspection`, `ready_for_sale`, `ready_for_arrival`, `ready_for_vip`, and `blocked`; readiness projections `HOUSEKEEPING_READY`, `HOUSEKEEPING_BLOCKED`, and `HOUSEKEEPING_UNKNOWN`; currently source-proven transition types `START_CLEANING`, `SUBMIT_INSPECTION`, and `RELEASE_READY`; and cleanliness statuses `dirty`, `clean`, and `inspected`. The FD-C2 handoff delivery states are `PENDING`, `CLAIMED`, `DELIVERED`, and `FAILED`. No checkout-turnover intake transition type currently exists; Package 11 may add one only through the amended ADR-086 boundary and its own runtime PR.

No new ADR is required because ADR-040 governs the interaction layer, ADR-086 now includes the checkout-turnover intake amendment, ADR-087 remains the Front Desk checkout boundary record, and ADR-089 governs atomic checkout orchestration and the checkout-specific Housekeeping handoff. This Package 10 governance correction creates no runtime source, test, migration, baseline metadata, database, or UI change.
