# Cost Control Canonical Revalidation Audit - 2026-08-15

## Executive Summary

- Audit: `CC-R1_COST_CONTROL_CANONICAL_REVALIDATION_AND_BOUNDARY_AUDIT`
- Canonical reviewed: `c5f12ef60b55a31f402b2c4166b7ef2d807cf7d8`
- Contract: Version 1.21, unchanged
- Historical audit treatment: preserved, not edited
- Runtime changes: none
- Tests rerun: none; committed source and test evidence were inspected
- ADR verdict: `NO_NEW_ADR_REQUIRED`
- Next-package verdict: `CC_GOVERNANCE_FREEZE_REQUIRED`
- Runtime authorization: none

The historical Sprint 15 conclusion is no longer an accurate current-state description. Canonical now has durable Inventory Ledger persistence, immutable Cost Ledger persistence, durable AVCO state, sequence allocation, enrollment/cutover evidence, controlled receipt/issue/adjustment/transfer/reversal valuation paths, GRNI accrual candidates, supplier invoice matching, AP-liability candidates, and downstream payment/settlement source.

The source is not, however, one uniformly activated Cost Control system. It contains legacy and controlled valuation authorities, two Inventory movement persistence boundaries, a read-only controlled AVCO projection over `InventoryStockMovement`, and a separate durable Cost Ledger bound by foreign key to `InventoryTransaction`. Operations services also resolve Finance/CostControl classes directly despite ADR-041's declared one-way dependency. Those facts make a governance freeze the smallest safe follow-on. They do not authorize a runtime package.

## Canonical Evidence Reviewed

### Governance evidence

- `.agents/contracts/IVORQ-Package-Execution-Contract.md` - Approved, Version 1.21.
- `docs/architecture/ADR-Master-Structure-Review.md` - current master review with Cost Control deliberately left as a separate audit workstream.
- `docs/architecture/Housekeeping-Package-20-Final-Governance-Closure-Review.md` - closes only the current Housekeeping turnover/readiness train.
- `docs/architecture/CostControl/Sprint-15-Cost-Control-Readiness-Audit.md` - historical point-in-time evidence.
- `docs/architecture/CostControl/Cost-Control-PRD.md` - Draft user-facing requirements; partly stale as an ownership description.
- `docs/architecture/CostControl/Inventory-and-Cost-Ledger-Foundation-Execution-Spec.md` and `Ledger-Event-Integrity-and-Business-Date-Control-Recovery-Spec.md` - historical Draft execution/recovery specifications.
- ADR-008 through ADR-017; ADR-034 through ADR-037; ADR-041 through ADR-047; ADR-079 through ADR-083.

### Source and persistence evidence

- `Modules/Operations/Inventory/Models/InventoryTransaction.php`
- `Modules/Operations/Inventory/Models/InventoryStockMovement.php`
- `Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php`
- `Modules/Operations/Inventory/Services/InventoryLedgerPostingService.php`
- Inventory migrations under `Modules/Operations/Inventory/database/migrations/`
- `Modules/Finance/CostControl/Models/CostLedgerEntry.php`
- `Modules/Finance/CostControl/Models/CostAvcoState.php`
- `Modules/Finance/CostControl/Services/AvcoValuationEngine.php`
- `Modules/Finance/CostControl/Services/CostLedgerAppendService.php`
- `Modules/Finance/CostControl/Services/ControlledValuationApplyCoordinator.php`
- controlled valuation invocation/planner/coordinator source under `Modules/Finance/CostControl/Services/`
- Cost Control migrations under `Modules/Finance/CostControl/database/migrations/`
- `Modules/Finance/GeneralLedger/Services/GrniPostingEngine.php`
- `Modules/Finance/GeneralLedger/Services/GrniClearingApLiabilityCandidateService.php`
- supplier-invoice, matching, payment, and settlement source under `Modules/Finance/Payables/Services/`

### Commit provenance sampled

| Capability | Source commit provenance |
|---|---|
| Controlled Inventory Ledger fields | `75046d5591dd5f3a639c1244431ee9f41f323f1e` |
| Cost Ledger persistence | `2bfb3c1b5d590e537a8bf2be32ee6feb1747d93e` |
| Durable AVCO state | `b62a67165267d7e5da8f32150d0c6d91529f715f` |
| Enrolled receipt invocation | `afd98c739d2138ed8c3f510d89712018f0261524` |
| Controlled issue occurred-at evidence | `62bae697a4473049f295c19ef014ce2221df07c9` |
| GRNI/AP candidate review | `f9017fbfc501fdc68fb610b2b280234769425eab` |
| Inventory reversal stock/cost consistency | `07e9dde13f6173f9abd6e60702b9323f7ad141ac` |

## Historical Audit Delta

| Historical finding | Current classification | Canonical delta |
|---|---|---|
| Inventory Ledger `NOT READY`; persistence absent | `SUPERSEDED` | `inventory_transactions` is immutable persisted ledger evidence under ADR-035/036, and `inventory_stock_movements` is an additional controlled forward quantity ledger under ADR-079/081. |
| No Inventory Ledger service | `SUPERSEDED` | `InventoryPostingControlCoordinator` posts `InventoryTransaction`; `InventoryLedgerPostingService` posts `InventoryStockMovement`; both have committed PostgreSQL tests. |
| Cost Ledger `NOT READY`; no table | `SUPERSEDED` | `cost_ledger_entries` exists with immutable PostgreSQL triggers, source FK, sequence/type checks, and unique idempotency evidence. |
| No Cost Ledger service | `SUPERSEDED` | `CostLedgerAppendService`, repository, adapters, planners, and production invocation paths exist. |
| AVCO only calculated in memory | `PARTIALLY_SUPERSEDED` | Durable `cost_avco_states`, sequence control, and enrolled-path write runtime exist. Legacy Item WAC and the non-persistent `InventoryAvcoCostProjectionService` also remain, so authority is scoped rather than universal. |
| GRNI only partially ready around GL identities | `PARTIALLY_SUPERSEDED` | A bounded receipt -> GRNI candidate -> approved/posted journal -> supplier invoice -> match/approval -> GRNI clearing/AP liability candidate -> GL posting -> payment path exists. Enterprise allocation, variance, tax, FX, and general GRNI-subledger completeness remain partial. |
| Cost Control is entirely read-only | `SUPERSEDED` as a complete architecture statement | The analytical workspace remains read-only, but `Modules/Finance/CostControl` now write-owns durable Cost Ledger and AVCO state for enrolled scopes. |
| Period and Business Date controls require implementation | `PARTIALLY_SUPERSEDED` | `InventoryPostingControlCoordinator::lockContext()` locks and revalidates `PropertyBusinessDate` and `FinancialPeriod`. Full late-event/correction delivery remains constrained and is not a generic runtime path. |
| Dedicated GRNI aging/reconciliation remains needed | `STILL_VALID` in enterprise scope | Current aging is a Payables read projection over supplier invoices/candidates/journals, not proof of a complete dedicated GRNI subledger with general partial allocations. |

## Current Inventory Ledger State

### Persistence verdict

`SOURCE_PROVEN` and `TEST_PROVEN`: Inventory Ledger persistence exists.

There are two bounded persistence forms:

1. `inventory_transactions` is the ADR-035 canonical physical persistence used by legacy/controlled Inventory workflows and by the durable Cost Ledger foreign key. It stores signed quantity deltas, before/after projection evidence, cost evidence, Property/item/location, source document and line identity, business date, occurred-at, idempotency, valuation scope/sequence, period, approval evidence, and reversal/correction links.
2. `inventory_stock_movements` is the ADR-079/081 controlled forward quantity ledger. It is authoritative only for movements posted through `InventoryLedgerPostingService`, does not claim historical completeness, and intentionally contains no cost, currency, Business Date, or financial-period fields.

The actual Cost Ledger source model is `InventoryTransaction`, because `cost_ledger_entries.source_inventory_transaction_id` is a restrictive foreign key to `inventory_transactions`. The newer controlled AVCO evidence workspace instead reads `InventoryStockMovement`. That split is current source truth and requires `INV-R1` plus the proposed `CC-G1` boundary freeze before expansion.

### Temporal and integrity semantics

For controlled `InventoryTransaction` appends:

- `InventoryPostingControlCoordinator::post()` acquires current Property Business Date and Financial Period row locks through `lockContext()`.
- New controlled rows require `business_date`, `occurred_at`, source document/line fields, and movement role when `idempotency_key` is present.
- `created_at` is persistence time and remains distinct from `business_date` and `occurred_at`.
- PostgreSQL update/delete triggers make rows immutable.
- Property/idempotency uniqueness and property/scope/valuation-sequence uniqueness protect retry and ordering.
- Legacy rows may retain null controlled provenance, as ADR-035/036 permit; current reporting must not invent those values.

Verdict: current controlled `InventoryTransaction` temporal semantics conform to the operative ADR-035/036 model for new controlled rows. `InventoryStockMovement` preserves occurred-at/created-at but not Business Date, so it is not independently equivalent to the ADR-035 ledger contract.

## Current Cost Ledger State

### Persistence and append ownership

`SOURCE_PROVEN` and `TEST_PROVEN`: canonical Cost Ledger persistence exists in `cost_ledger_entries` and is write-owned by Finance/CostControl.

- Model: `Modules/Finance/CostControl/Models/CostLedgerEntry.php`.
- Migration: `Modules/Finance/CostControl/database/migrations/2026_06_23_000000_create_cost_ledger_entries_table.php`.
- Append boundary: `CostLedgerAppendService` through `CostLedgerRepository`.
- Production composition: controlled receipt, issue, adjustment, transfer, and reversal services build Cost Ledger intents and append within controlled transactions for enrolled scopes.

PostgreSQL enforces append-only update/delete triggers, positive entry sequence, a restricted entry-type set, source InventoryTransaction FK, prior-entry FK, and unique `(property_id, idempotency_key, entry_sequence)`.

The append boundary rejects duplicate identity with a controlled exception rather than returning an existing equivalent entry. That is duplicate protection, but it is not the full equivalent-replay behavior envisioned by ADR-042 for a future deferred consumer.

### Current limitations

- `prior_cost_ledger_entry_id` is generally passed as null by active invocation services, so a complete mathematical parent-child lineage is not built.
- The durable ledger is tied to `InventoryTransaction`, not `InventoryStockMovement`.
- No production outbox consumer from Inventory to Cost Control was found; enrolled production paths invoke Cost Control synchronously inside the operational transaction.
- Cost Ledger-to-GL behavior is movement-specific. Controlled issue produces a GL `JournalCandidate`; this audit did not find a universal Cost Ledger posting/reconciliation engine covering every entry type.

## Current AVCO State

### Durable state and sequence control

`SOURCE_PROVEN` and `TEST_PROVEN`: `cost_avco_states` persists `on_hand_quantity`, `carrying_value`, `weighted_average_unit_cost`, unresolved provisional quantity, and last valuation sequence/business date per `(property_id, location_id, item_id)`. `inventory_valuation_sequences` allocates monotonic sequence numbers for the same physical scope. Enrollment groups and immutable scope snapshots govern cutover from legacy WAC authority.

For an enrolled Property+Item group, Cost Control is the intended sole AVCO authority across included locations. `ControlledValuationApplyCoordinator` locks the existing seeded state, verifies enrollment provenance, checks exact immutable `InventoryTransaction` evidence, appends the Cost Ledger entry, and persists the resulting state atomically.

### Valuation by movement

| Movement | Current valuation behavior | Classification |
|---|---|---|
| Receipt | Enrolled legacy receipt and Receiving integration post an `InventoryTransaction`, use its approved positive quantity/unit cost/value, append a receipt Cost Ledger entry, and update durable AVCO state. Unenrolled groups retain legacy Item WAC. | `IMPLEMENTED` for enrolled legacy/Receiving paths; scoped activation |
| Issue | Enrolled path locks `CostAvcoState`, derives unit cost from current WAUC, posts negative quantity/value, appends Cost Ledger, updates state, and creates a GL issue candidate. | `IMPLEMENTED` for enrolled path |
| Positive adjustment | Uses approved line cost, calculates positive value, appends ledger, and updates state. | `IMPLEMENTED` for enrolled path |
| Negative adjustment | Uses locked-state prevailing WAUC and relieves value. | `IMPLEMENTED` for enrolled path |
| Transfer | Paired source/destination state is locked; source WAUC moves with quantity; paired Cost Ledger entries and state transitions are atomic. | `IMPLEMENTED` for enrolled path |
| Reversal | Eligible purchase receipt/issue reversal uses original immutable unit/total cost sign-negated, current open date/period, approval evidence, anti-double-reversal protection, Cost Ledger append, and state transition. | `IMPLEMENTED` but deliberately narrow and separately debt-tracked |
| Correction | Generic correction remains unsupported. Planner/guard concepts exist, but no approved general correction runtime was found. | `GOVERNANCE_ONLY` / future contract |

### Negative inventory and provisional correction

Current controlled posting rejects any `InventoryTransaction` that would make `InventoryStock.physical_quantity` negative. `InventoryLedgerPostingService` also rejects outbound controlled movement beyond its ledger-derived quantity. The active enrolled issue planner likewise requires sufficient positive state.

`AvcoValuationEngine` contains provisional-negative and later-correction result semantics, but no production caller of that engine was found; production controlled invocation uses the stricter state-transition planners. Therefore:

- negative inventory in current controlled production paths: not permitted;
- provisional valuation/later correction: foundation/planner behavior, not proven active production runtime;
- this is stricter than the broad ADR-010 negative-stock policy and consistent with the scoped ADR-081 controlled-ledger quantity protection. Precedence and scope should be frozen in `CC-G1`; no new ADR is needed.

## Current GRNI/AP State

### End-to-end stage map

| Stage | Status | Current source/test evidence |
|---|---|---|
| Purchase Order | `IMPLEMENTED` | Purchasing PO source under `Modules/Operations/Purchasing`; supplier-invoice matching reads canonical `purchase_orders`/`purchase_order_lines`. |
| Receiving / Goods Receipt | `IMPLEMENTED` | `Modules/Operations/Receiving` owns `ReceivingDocument`/`ReceivingLine`; `InventoryReceiptIntegrationService` creates controlled Inventory receipt evidence. |
| Inventory receipt and valuation | `IMPLEMENTED` / scoped | Enrolled path uses `ControlledReceiptValuationInvocationService`; unenrolled path remains legacy. `ControlledReceiptValuationInvocationTest` covers both and rollback. |
| GRNI accrual candidate | `IMPLEMENTED` | `GrniPostingEngine` creates balanced Inventory debit / `GRNI_RECEIPT` credit `JournalCandidate` for purchase/vendor-linked receipts. |
| GRNI candidate review, materialization, finalization, posting | `IMPLEMENTED` | Existing General Ledger lifecycle services; committed `ControlledReceiptValuationInvocationTest` source covers review through controlled posting and closed-period/date rejection. |
| Supplier invoice registration | `IMPLEMENTED` | `SupplierInvoiceRegistrationService` persists header/lines with Property/vendor/PO/receipt evidence. |
| Three-way match | `IMPLEMENTED` | `ThreeWayMatchingEngine` persists match/exception and line evidence; `SupplierInvoiceThreeWayMatchFoundationTest` covers exact match and exceptions. |
| Supplier invoice approval | `IMPLEMENTED` | `SupplierInvoiceApprovalService`; approval remains separate from accounting mutation. |
| GRNI clearing/AP liability candidate | `IMPLEMENTED` for narrow exact-match scope | `GrniClearingApLiabilityCandidateService` creates balanced GRNI debit / AP control credit candidate from posted source evidence. It deliberately fails closed for unsupported allocation, currency, tax, or variance conditions. |
| AP liability GL posting | `IMPLEMENTED` for the bounded candidate lifecycle | Existing candidate -> draft -> finalization -> posting lifecycle is covered in `SupplierInvoiceThreeWayMatchFoundationTest`. |
| Payment proposal and approval | `IMPLEMENTED` | Payables payment proposal services and tests. |
| Cash/bank payment execution and posting | `IMPLEMENTED` for governed paths | Payables, General Cashier, and Banking lifecycle source/tests; not changed by this audit. |
| Settlement allocation | `IMPLEMENTED` / partial-split capable | `ApSettlementAllocationService` and partial/split supplier payment tests preserve posted AP and payment journal references. |
| General GRNI subledger, arbitrary partial many-to-many clearing, tax/FX/PPV/credit-note coverage | `PARTIAL` | No complete dedicated GRNI subledger was proven. Initial candidate creation remains conservative and exact-match; unsupported cases fail closed. |

Current GRNI/AP verdict: `PARTIAL`. The old “merely GL operational identities” description is obsolete, but the full enterprise ADR-011/014/016 outcome is not complete.

## Current Procurement/Receiving Dependency State

Purchasing owns commercial intent and PO terms. Receiving owns physical receipt document and line evidence. Inventory owns quantity movement. Cost Control consumes approved immutable inventory evidence and owns derived cost state. Payables owns supplier invoice, match, approval, proposal, and settlement evidence. General Ledger owns candidates, journals, posting, and financial-period enforcement.

The source implements this chain for a bounded supported case, but older Purchasing/Receiving models and newer controlled `GoodsReceipt`/`InventoryStockMovement` source coexist. `PROC-R1` must map canonical PO and Receiving pathways before any broad CC integration expansion.

## Business Date / Financial Period Controls

- Business Date owner: Foundation/Property, coordinated by Night Audit under ADR-034.
- Financial Period owner: Finance/GeneralLedger under ADR-013 and accepted GL services.
- `InventoryPostingControlCoordinator::lockContext()` locks both current rows and revalidates open/reopened status before Inventory append.
- Controlled Cost Ledger append for receipt/issue/adjustment/transfer runs inside the same outer transaction after the Inventory evidence is accepted.
- Inventory reversal posts on the current open Business Date/Financial Period and writes `original_business_date` into the Cost Ledger reversal evidence.
- `CostLedgerPostingGuard` and `AvcoValuationEngine` express correction-required outcomes for closed source windows, but a generic correction delivery/runtime path is not proven.
- Original InventoryTransaction and Cost Ledger entries are immutable; closed-period history is not silently rewritten.

Verdict: active controlled paths fail closed on unavailable/closed controls and preserve original evidence. Generic late-event correction and deferred delivery remain future controlled scope.

## Ownership Matrix

| Fact / aggregate | OWNER | CONSUMER | CONTROLLED_WRITE_PORT | READ_MODEL |
|---|---|---|---|---|
| Inventory quantity movement | Operations/Inventory | Cost Control, GL, operational workspaces | `InventoryPostingControlCoordinator`; separately `InventoryLedgerPostingService` for the ADR-079 forward ledger | `InventoryStock`; controlled ledger quantity projection |
| Inventory Ledger | Operations/Inventory | Cost Control, Procurement/Receiving, GL | `InventoryTransactionRepository::appendControlled`; `InventoryLedgerPostingService::post` for scoped forward movements | Inventory ledger/stock-card workspaces |
| Cost Ledger valuation | Finance/CostControl | GL and Cost Control reporting | `CostLedgerAppendService` via controlled adapters/coordinators | Cost ledger repository/workspace evidence |
| Durable AVCO state | Finance/CostControl for enrolled scopes; legacy Inventory Item WAC before enrollment | Inventory operational cost readers, Cost Ledger | Controlled valuation invocation/apply coordinators plus enrollment boundary | `InventoryAvcoCostProjectionService` is a separate non-persistent evidence projection over `InventoryStockMovement` |
| GL journal | Finance/GeneralLedger | Finance reporting, Payables settlement, reconciliation | accepted candidate -> draft -> finalization -> posting services | Journal workspaces, reports |
| AP commercial obligation workflow | Finance/Payables | GL, General Cashier, Banking | supplier invoice/match/approval/proposal/settlement services | AP/GRNI settlement and aging projections |
| Posted AP liability balance | Finance/GeneralLedger | Payables/settlement/reporting | `GrniClearingApLiabilityCandidateService` followed by accepted GL lifecycle | `ApOutstandingProjectionService`, AP/GRNI projections |
| GRNI accrual/clearing financial evidence | Finance/GeneralLedger | Payables, Procurement, reconciliation | `GrniPostingEngine`; `GrniClearingApLiabilityCandidateService`; GL lifecycle | GRNI control workspace and AP/GRNI aging projection |
| Purchase Order | Operations/Purchasing | Receiving, Payables, Cost Control analysis | Purchasing services/repositories | Purchasing workspaces |
| Receiving | Operations/Receiving | Inventory, GL/GRNI, Payables | Receiving service and `InventoryReceiptIntegrationService` | Receiving workspaces |
| Financial Period | Finance/GeneralLedger | Inventory, Cost Control, AP, all posting domains | Period control services | Period/close projections |
| Business Date | Foundation/Property and Night Audit lifecycle | Inventory, Cost Control, PMS, Finance | approved Business Date/Night Audit services only | current Business Date projections |

### Boundary compliance finding

No direct cross-module table mutation by Cost Control was found in the reviewed production services. Cost Control reads Inventory through repositories and receives source identity.

However, a dependency-direction violation/tension is `SOURCE_PROVEN`: `ReceiptService`, `IssueService`, `TransferService`, `AdjustmentService`, `InventoryReceiptIntegrationService`, and `InventoryReversalPostingService` resolve or inject Finance/CostControl repositories/services. ADR-041 states the dependency should remain `Finance/CostControl -> Operations/Inventory`, with Inventory not importing or instantiating CostControl. The current synchronous enrolled invocation reverses that dependency even though it uses explicit service ports rather than raw table writes. `CC-G1` must freeze the accepted integration direction before runtime expansion.

## Runtime / Foundation / Future-Contract Classification

| Classification | Current components |
|---|---|
| Production runtime | `inventory_transactions`; `cost_ledger_entries`; enrolled receipt/issue/adjustment/transfer/reversal invocations; durable `cost_avco_states`; GRNI and AP candidate lifecycles; read-only Cost Control/AVCO workspaces. |
| Foundation | enrollment groups/snapshots/baseline seed services; Inventory outbox producer; newer `InventoryStockMovement` forward ledger; immutable receipt commercial evidence. |
| Guard-only | `CostLedgerPostingGuard` and portions of closed-source correction decision logic not used by current synchronous enrolled invocation. |
| Planner-only | `CostLedgerPostingPlanner`/`AvcoValuationEngine` negative provisional/correction behavior has unit/PostgreSQL evidence but no production caller found. |
| Future contract | generic correction; automatic/replayable deferred Cost Ledger consumer; complete GRNI allocation/reconciliation; universal Cost Ledger-to-GL posting; foreign-currency/PPV/tax costing expansion. |
| Test-only evidence | Direct construction and edge-case coverage in `tests/Unit/Finance/CostControl/` and `tests/Postgres/Finance/CostControl/`; tests prove committed intent but were not rerun in this documentation-only audit. |

## Existing PostgreSQL Integrity

| Persistence | Current integrity evidence |
|---|---|
| `inventory_transactions` | Update/delete triggers; controlled-field check; partial unique Property/idempotency index; unique Property/scope/valuation sequence; self-reversal check and anti-double-reversal partial unique index; restrictive self-FKs. |
| `inventory_valuation_sequences` | Unique physical scope `(property_id, location_id, item_id)`; row-lock allocation repository. |
| `cost_ledger_entries` | Restrictive source and prior-entry FKs; positive-sequence and entry-type checks; unique Property/idempotency/sequence; update/delete triggers. |
| `cost_avco_states` | Unique physical scope; service-level active-transaction and lock requirements; enrollment provenance fields and controlled persistence methods. |
| `inventory_stock_movements` | Property/idempotency uniqueness; source/source-leg uniqueness after later migration; append-only model and PostgreSQL test source. |

## Current Test Evidence

No test was rerun because this workstream changes documentation only.

Committed source evidence includes:

- `CostLedgerAppendBoundaryTest`: append, duplicate protection, cross-Property rejection, missing-source rejection.
- `CostAvcoStatePersistenceTest`: bootstrap, scope uniqueness, exact state persistence, rejected/pending non-mutation.
- `ControlledReceiptValuationInvocationTest`: enrolled and legacy receipt paths, rollback, GRNI lifecycle, period/Business Date rejection.
- `ControlledIssueValuationInvocationTest`, `ControlledAdjustmentValuationInvocationTest`, and `ControlledTransferValuationInvocationTest`: enrolled/legacy selection, atomic state/ledger behavior, and rollback.
- `ControlledReversalValuationPlannerTest` and Inventory reversal PostgreSQL service tests.
- `SupplierInvoiceThreeWayMatchFoundationTest`: registration through AP liability posting and payment proposal foundation.
- `SupplierPaymentLifecycleTest`: cash supplier payment candidate/draft/posting lifecycle.

The registry still classifies the wider Inventory/AVCO/Sensitive baseline as candidate, so committed test source is evidence of behavior and integrity, not a claim that the candidate baseline is an accepted active gate.

## Inherited Debt Impact

The active `inventory-reversal-inherited-debt-v1` baseline records `InventoryReversalWorkspaceTest` at 8 tests / 72 assertions / 0 failures / 2 accepted trigger-related errors. This audit did not rerun, change, reclassify, or increase that debt.

Decision: `DOES_NOT_BLOCK_CC_RUNTIME`.

Reason: the registered debt is scoped to the Inventory Reversal workspace test class. Cost Ledger append, AVCO persistence, receipt/issue/adjustment/transfer invocation, and reversal posting/planner behaviors have separate committed PostgreSQL test classes. The debt must remain visible and would be a prerequisite consideration for any package that expands the reversal UI or its trigger path, but it does not by itself block a separately bounded non-reversal Cost Control governance/runtime decision.

## Remaining Gaps

1. Canonical precedence between `inventory_transactions` and `inventory_stock_movements` is not expressed in one current governance record for Cost Control consumption.
2. The PRD and historical execution specifications are stale against durable Cost Control ownership.
3. The enrolled synchronous integration reverses ADR-041's declared module dependency direction.
4. Enrollment/cutover capability is durable but not proven as a broad operator-facing activation workflow; universal activation must not be inferred.
5. Legacy Item WAC remains authoritative for unenrolled groups.
6. The read-only AVCO projection over `InventoryStockMovement` and durable AVCO state over `InventoryTransaction` are distinct and must not be presented as the same book of record.
7. Generic correction, deferred consumer replay, and full original-to-derived lineage remain incomplete.
8. GRNI/AP is bounded to conservative supported cases; general partial allocation, PPV, tax, FX, credit notes, and comprehensive reconciliation remain partial.
9. Cost Ledger-to-GL coverage is movement-specific rather than proven universal.
10. `prior_cost_ledger_entry_id` is not populated by active invocation paths, limiting exact dependency lineage.

## Obsolete Historical Blockers

- Inventory Ledger persistence absent.
- Inventory Ledger append service absent.
- Cost Ledger persistence absent.
- Cost Ledger append service absent.
- AVCO has no durable state or history at all.
- Property Business Date persistence and server-side enforcement absent.
- No durable outbox foundation exists.
- GRNI stops at operational identity names only.

These statements remain valid only as historical evidence about the branches reviewed when written.

## Still-Valid Historical Constraints

- Inventory quantity movement remains Inventory-owned.
- Cost Ledger and AVCO state must not absorb PO, Receiving, AP liability, GL journal, Financial Period, or Business Date ownership.
- Cost Control analytical findings must not directly rewrite foreign-domain state.
- Historical ledger rows must not be fabricated or rewritten.
- Current Property, actor, Business Date, period, source identity, currency, idempotency, and audit evidence remain mandatory.
- Closed Business Dates and Financial Periods must fail closed; corrections are compensating/current-period actions, not historical mutation.
- GRNI/AP/PPV/tax/FX conditions must fail closed when unsupported.
- Reversal/correction must preserve immutable originals and explicit provenance.

## ADR Sufficiency Verdict

`NO_NEW_ADR_REQUIRED`

ADRs 035-037 define InventoryTransaction persistence, source identity, and locks; ADRs 041-043 define derived ledger delivery, AVCO ordering, and enrollment authority; ADRs 044-047 define reversal and GRNI/AP boundaries; ADRs 079-083 define the newer controlled movement, commercial evidence, and AVCO evidence scopes; ADR-013 and ADR-034 govern Financial Period and Business Date. The current issue is synchronization and scoped precedence among existing decisions and source, not absence of a durable cross-domain decision.

No ADR is created by this audit.

## Candidate Next Cost Control Package

Verdict: `CC_GOVERNANCE_FREEZE_REQUIRED`.

Candidate identifier: `CC-G1_COST_CONTROL_OWNERSHIP_ACTIVATION_AND_LEDGER_PRECEDENCE_FREEZE`.

Objective: synchronize current Cost Control documentation and freeze, without runtime change:

- which Inventory persistence feeds durable Cost Ledger versus read-only AVCO evidence;
- legacy versus enrolled valuation authority and activation scope;
- approved Inventory-to-CostControl dependency/port direction;
- movement-by-movement production versus foundation status;
- negative-stock and correction scope precedence;
- GRNI/AP supported-case boundary; and
- the separation between analytical Cost Control workspaces and CostControl-owned durable valuation records.

Allowed future categories should be governance documents already identified as stale, plus exact Contract/ADR-master synchronization only if separately Owner-authorized. Runtime, migrations, models, services, controllers, routes, permissions, tests, regression metadata, and ADR creation are non-goals. `CC-G1` is not authorized by this audit.

No `CC-P01` candidate is identified because the smallest safe next action is governance synchronization.

## Required Revalidation Questions - Direct Answers

1. **Inventory Ledger persistence?** Yes; `inventory_transactions` and a separately scoped `inventory_stock_movements` ledger both exist.
2. **Actual source-of-truth model/table?** `InventoryTransaction`/`inventory_transactions` for durable Cost Ledger source; `InventoryStockMovement`/`inventory_stock_movements` for ADR-079 forward controlled quantity movements only.
3. **Temporal semantics?** Implemented for new controlled `InventoryTransaction` rows with server-governed lock context and distinct Business Date/occurred-at/created-at; legacy null provenance remains permitted. The newer movement ledger lacks Business Date.
4. **Cost Ledger persistence?** Yes, `cost_ledger_entries`.
5. **Append owner?** Finance/CostControl through `CostLedgerAppendService` and `CostLedgerRepository`, composed by controlled valuation coordinators.
6. **Persistent AVCO state/history?** Yes: mutable durable state in `cost_avco_states` plus immutable Cost Ledger entry history for enrolled scopes.
7. **Deterministic/sequence controlled?** Yes for the durable enrolled path through `inventory_valuation_sequences`, state row locks, and strict no-gap planners.
8. **Movement valuation?** Receipt at approved source cost; issue/negative adjustment at locked WAUC; positive adjustment at approved line cost; transfer at source WAUC with paired states; reversal at sign-negated original immutable cost; generic correction unsupported.
9. **Negative inventory?** Current controlled posting blocks it. Provisional-negative engine behavior is foundation-only.
10. **Provisional valuation/later correction?** Expressed in `AvcoValuationEngine`, not proven in active controlled production composition.
11. **Closed periods?** Active controlled paths fail closed; reversal posts in current open context; generic correction/reopen path is not active.
12. **Original Business Date evidence?** Preserved on immutable InventoryTransaction; reversal also records `original_business_date` in Cost Ledger.
13. **GRNI beyond identities?** Yes: candidate, review, journal draft/finalization/posting, supplier invoice, match, AP-liability candidate, and settlement evidence exist.
14. **Receipt to payment extent?** Implemented for a bounded exact-match supported path; broader allocations/variance/tax/FX remain partial.
15. **Write-owned vs read/analyze?** CostControl write-owns durable Cost Ledger and AVCO state for enrolled scopes; analytical workspaces/read projections remain read-only and do not own Inventory/AP/GL/Purchasing facts.
16. **Ownership violation?** No raw foreign-table mutation by Cost Control found; direct Operations-to-CostControl class dependencies conflict with ADR-041's stated one-way direction and require governance freeze.
17. **Obsolete blockers?** Missing Inventory Ledger, Cost Ledger, persistent AVCO, Business Date foundation, outbox foundation, and meaningful GRNI/AP flow.
18. **Remaining blockers?** Dual ledger precedence, legacy/enrolled authority, dependency direction, stale governance, scoped activation, incomplete generic correction/deferred delivery, and partial enterprise GRNI/AP.
19. **New ADR?** `NO_NEW_ADR_REQUIRED`.
20. **Smallest next package?** `CC-G1_COST_CONTROL_OWNERSHIP_ACTIVATION_AND_LEDGER_PRECEDENCE_FREEZE`; no runtime implementation.

## Explicit Non-Goals

- No Cost Control, Inventory, Finance, Procurement, Receiving, Business Date, or Night Audit runtime change.
- No migration, model, service, controller, request, policy, route, seeder, permission, Sensitive Action intent, UI, dependency, test, or regression metadata change.
- No ledger data inspection or mutation.
- No test rerun or baseline promotion.
- No inherited debt reclassification.
- No new ADR.
- No `CC-P01` implementation.
- No Package 21.

## Final Readiness Verdict

`CC_GOVERNANCE_FREEZE_REQUIRED`

Canonical is no longer blocked by absent Inventory/Cost Ledger foundations. It is blocked from a responsibly defined next runtime package by governance staleness, split Inventory evidence boundaries, scoped legacy/enrolled authority, and a source-proven dependency-direction mismatch. A documentation/architecture synchronization package is the smallest justified next boundary.

This audit grants no implementation authorization.

`NO_PACKAGE_21_ACTIVATED`
