# CC-G1 Cost Control Ownership, Activation, and Ledger Precedence Freeze

## Package Identity

- Package: `CC-G1_COST_CONTROL_OWNERSHIP_ACTIVATION_AND_LEDGER_PRECEDENCE_FREEZE`
- Package type: `GOVERNANCE_ONLY`
- Date: 2026-08-15
- Canonical branch: `ivorq-enterprise-core`
- Feature branch: `codex/governance-cc-g1-cost-control-precedence-freeze`
- Runtime authorization: none
- ADR creation: none; `NO_NEW_ADR_REQUIRED`
- Package 21: `NO_PACKAGE_21_ACTIVATED`

This record freezes current source-backed Cost Control ownership and precedence. It does not modify production runtime or activate a later package.

## Canonical Predecessor

- Exact canonical predecessor: `bc17d38060f2cdf91c45049aa593e358ae7e1c4c`
- Accepted predecessor: `CC-R1_COST_CONTROL_CANONICAL_REVALIDATION_AND_BOUNDARY_AUDIT`
- Accepted CC-R1 merge: `bc17d38060f2cdf91c45049aa593e358ae7e1c4c`
- CC-R1 original audit: `cf8d1c840497d476362ca72925dfbfa3bba7ce1f`
- CC-R1 provenance correction / final feature HEAD: `c368a0b93aaca7b963c9ba076268a327d93caf6b`
- Registry and CC-R1 acceptance: PR #55

CC-R1 is `ACCEPTED_AND_MERGED`. Its original review provenance is preserved; later provenance correction does not replace or rewrite the original audit history.

## Contract 1.22

CC-G1 operates under the Approved IVORQ Package Execution Contract Version 1.22, amended 2026-08-15. Version 1.22 records:

- acceptance of the Master Domain Registry and CC-R1 through PR #55;
- activation of this governance-only freeze;
- exact Inventory/Cost Ledger, AVCO authority, delivery-mode, dependency, negative-stock, reversal/correction, Business Date/Financial Period, GRNI/AP, and interaction/write-ownership precedence;
- no runtime implementation;
- no new ADR;
- no `CC-P01`, `INV-R1`, `FIN-R1`, or `PROC-R1` activation; and
- `NO_PACKAGE_21_ACTIVATED`.

## CC-R1 Accepted Findings

The following accepted findings were rechecked against current canonical source before this freeze was written:

1. `InventoryTransaction` / `inventory_transactions` exists and is immutable monetary/source evidence for the current durable Cost Ledger.
2. `cost_ledger_entries.source_inventory_transaction_id` references `InventoryTransaction`, not `InventoryStockMovement`.
3. `InventoryStockMovement` / `inventory_stock_movements` is the newer controlled forward quantity ledger.
4. `InventoryStockMovement` intentionally has no authoritative cost, currency, Business Date, or Financial Period fields.
5. ADR-082 read-only controlled AVCO evidence reads `InventoryStockMovement`.
6. Durable enrolled CostControl AVCO uses enrollment evidence, Inventory valuation sequence, immutable `InventoryTransaction` evidence, `CostAvcoState`, and Cost Ledger.
7. Legacy WAC remains authoritative before enrollment.
8. CostControl is the sole AVCO write authority after accepted complete Property + Item enrollment.
9. Mixed legacy/CostControl authority for one enrolled Property + Item group is prohibited.
10. Accepted production contains synchronous Operations/Inventory or Receiving-to-CostControl composition.
11. ADR-041 prohibits Inventory from constructing or calling CostControl implementation classes in the future deferred architecture.
12. No active production CostControl outbox consumer is source-proven. Foundation services or outbox records do not constitute an activated consumer.
13. Current controlled production rejects negative stock.
14. Provisional-negative/later-correction planner concepts are not active production composition.
15. Bounded reversal exists; generic correction is unsupported.
16. GRNI/AP runtime exists for a bounded supported exact-match path.
17. General allocation, PPV, tax, FX, and enterprise-wide GRNI subledger/reconciliation coverage remain incomplete.

No material source contradiction was found.

## Ownership Matrix

| Fact / aggregate | OWNER | WRITE AUTHORITY | READ CONSUMERS | PRECEDENCE |
| :--- | :--- | :--- | :--- | :--- |
| Inventory physical movement | Operations/Inventory | Approved Inventory posting/movement services only | CostControl, Receiving, GL candidates, Inventory workspaces | Inventory quantity evidence is authoritative; CostControl never rewrites it. |
| `InventoryTransaction` | Operations/Inventory | `InventoryPostingControlCoordinator` through the controlled repository boundary | CostControl durable valuation, GL/GRNI paths, audit/reporting | Immutable monetary/source evidence for current durable Cost Ledger; outranks duplicated or inferred monetary evidence. |
| `InventoryStockMovement` | Operations/Inventory | `InventoryLedgerPostingService` for its controlled forward movement scope | ADR-082 projection and controlled quantity workspaces | Authoritative only for its forward quantity/evidence scope; not a durable Cost Ledger, GL, or AP monetary source. |
| `CostLedgerEntry` | Finance/CostControl | `CostLedgerAppendService` through controlled valuation adapters/coordinators | Cost Control reporting, bounded downstream financial consumers | Durable derived valuation record sourced from immutable `InventoryTransaction`; append-only and not replaced by ADR-082 projection. |
| `CostAvcoState` | Finance/CostControl for enrolled scopes | Controlled valuation apply/state repository boundaries | Enrolled operational cost readers and Cost Ledger planning | Sole durable AVCO state after accepted complete enrollment; legacy authority remains before enrollment. |
| `PurchaseOrder` | Operations/Purchasing | Approved Purchasing services | Receiving, Payables matching, Cost Control analysis | Commercial intent/commitment source; CostControl does not amend it. |
| `GoodsReceipt` / Receiving evidence | Operations/Receiving | Approved Receiving services | Inventory, GRNI, Payables, CostControl valuation | Physical/commercial receipt source evidence; immutable snapshot rules govern supported valuation. |
| GRNI candidate | Finance/GeneralLedger | `GrniPostingEngine` and accepted candidate lifecycle services | Payables, reconciliation, Finance review | Supported only for bounded source-proven paths; not proof of universal GRNI completion. |
| `SupplierInvoice` | Finance/Payables | Registration, matching, exception, and approval services | GL/AP candidate lifecycle, settlement, reporting | AP commercial obligation workflow; CostControl analysis cannot approve or mutate it. |
| AP liability | Finance/GeneralLedger with Payables source workflow | GRNI-clearing/AP-liability candidate followed by accepted GL lifecycle | Payables outstanding/settlement and Finance reporting | Posted GL evidence is authoritative; bounded exact-match support does not imply general allocation coverage. |
| GL Journal | Finance/GeneralLedger | Accepted candidate, draft, finalization, and posting services | Finance reporting, Payables settlement, reconciliation | Accounting record of authority; neither Inventory movement nor CostControl workspace may write it directly. |
| `FinancialPeriod` | Finance/GeneralLedger | Approved period-control services | Inventory, CostControl, Payables, all posting domains | Open/reopened eligibility must be resolved and locked; closed periods fail closed. |
| `PropertyBusinessDate` | Foundation/Property with Night Audit lifecycle coordination | Approved Business Date/Night Audit services | Inventory, CostControl, PMS, Finance | Authoritative Property operational date; monetary Inventory posting may not bypass it. |

## Inventory Ledger Precedence

### InventoryTransaction

`InventoryTransaction` / `inventory_transactions` is:

- the authoritative immutable source evidence for the current durable Cost Ledger;
- the source of controlled monetary valuation evidence for current enrolled CostControl write paths;
- governed by ADR-035, ADR-036, ADR-037, and later applicable decisions; and
- the source identified by the restrictive Cost Ledger foreign key.

### InventoryStockMovement

`InventoryStockMovement` / `inventory_stock_movements` is:

- authoritative only for its controlled forward quantity-movement scope;
- the source used by the ADR-082 read-only controlled AVCO evidence projection;
- not a replacement monetary source for the current durable Cost Ledger;
- not an alternate GL or AP valuation source; and
- intentionally free of duplicated cost, currency, Business Date, or Financial Period state.

No dual-write reconciliation, ledger migration, or attempt to make the two tables structurally identical is authorized. `INV-R1` remains the future audit for broader Inventory reconciliation.

## Cost Ledger Source Precedence

The current durable Cost Ledger is Finance/CostControl-owned derived state. Each current entry is sourced from immutable `InventoryTransaction` evidence through `source_inventory_transaction_id`. Current production adapters validate Property, location, item, valuation sequence, quantity, unit cost, value, currency, Business Date, and occurred-at evidence before append.

The Cost Ledger must not be sourced from a mutable stock balance, from the ADR-082 projection, or from duplicated monetary fields added to `InventoryStockMovement`. GL and AP must not treat the read-only projection as an alternate valuation book.

## AVCO Authority and Enrollment

### Unenrolled Property + Item group

Legacy valuation authority remains authoritative across the complete Property + Item group.

### Enrolled complete Property + Item group

Finance/CostControl is the sole AVCO write authority for every included location. Durable enrolled authority consists of:

- immutable enrollment and cutover evidence;
- Inventory valuation sequence;
- immutable `InventoryTransaction` valuation evidence;
- `CostAvcoState`; and
- Cost Ledger.

The ADR-082 `InventoryStockMovement` projection remains `READ_ONLY_EVIDENCE`. It must not override `CostAvcoState`, override Cost Ledger, become a GL/AP source, or silently operate as a second AVCO writer.

Mixed authority is prohibited. CC-G1 performs no automatic enrollment, backfill, cutover, scope expansion, or global activation.

## Synchronous vs Deferred Delivery

Current accepted enrolled production contains synchronous valuation composition. CC-G1 classifies it as:

`TRANSITIONAL_EXISTING_CANONICAL_PATH`

This current path remains accepted and is not retroactively invalidated.

ADR-041 remains the governing architecture for future deferred Inventory-to-CostControl delivery. Future mode requires Inventory-owned immutable source/outbox evidence followed by a CostControl consumer that resolves the source read-only. Inventory must not construct CostControl implementation classes in that mode.

ADR-041 approval does not activate a consumer. ADR-042 approval governs future sequence semantics but does not implement a consumer. No queue, worker, retry, replay, scheduler, publisher, listener, or after-commit delivery runtime is activated by CC-G1.

## Dependency Direction

No new direct Operations/Inventory-to-Finance/CostControl class dependency may be added. Existing direct coupling must not be expanded.

For future deferred delivery, dependency direction remains:

`Finance/CostControl -> Operations/Inventory`

through Inventory-owned source/outbox evidence and a CostControl-owned consumer. A later runtime package touching the current synchronous dependency must either introduce an approved application/port composition boundary or provide explicit source-backed justification within that package.

## Negative Inventory Precedence

For `ACTIVE_CONTROLLED_ENROLLED_PRODUCTION`, negative inventory is prohibited. Current quantity and valuation guards remain authoritative.

The provisional-negative and later-correction concepts in `AvcoValuationEngine` and related planners are `FOUNDATION / NON-ACTIVE-PRODUCTION`. They do not activate negative inventory and do not override current controlled guards. Any future negative-stock runtime requires separate explicit package authorization.

## Reversal and Correction Precedence

- Current controlled reversal remains supported only for approved bounded cases.
- Reversal uses immutable original quantity/cost evidence and posts through current open context.
- Generic correction is not active.
- Historical evidence must not be silently rewritten.
- Closed periods must not receive silent back-posting.
- ADR-042 does not activate a generic correction, reopen, replay, or recovery workflow.
- CC-G1 activates no correction or reopening behavior.

## Business Date / Financial Period Precedence

Controlled monetary `InventoryTransaction` posting uses authoritative Property Business Date and Finance Financial Period controls. The posting coordinator locks and revalidates the current rows, and enrolled monetary valuation must not bypass that context.

- Closed Business Dates and closed Financial Periods fail closed.
- Current reversal posts only through its approved current-context semantics and retains original Business Date evidence.
- No historical silent rewrite, generic correction, or reopen package is active.
- `InventoryStockMovement` does not become a replacement Business Date or Financial Period ledger.

## GRNI/AP Supported-Case Boundary

Current GRNI/AP support is classified as:

`BOUNDED_SUPPORTED_PATH`

Source-proven supported stages may include:

`Purchase Order -> Goods Receipt -> Inventory/valuation -> GRNI candidate -> controlled journal lifecycle -> Supplier Invoice -> three-way match -> approval -> GRNI clearing/AP liability candidate -> GL posting -> payment proposal/execution/settlement`

This is not universal enterprise GRNI completion. The following remain incomplete or future:

- generalized partial allocation;
- purchase price variance posting;
- broad tax treatment;
- broad foreign-exchange treatment;
- complete GRNI subledger and reconciliation; and
- unsupported variance and many-to-many allocation cases.

CC-G1 does not modify Finance, Payables, General Ledger, Purchasing, or Receiving runtime.

## Analytical Workspace vs Durable Write Ownership

Cost Control UI/workspace is:

`READ-ONLY OPERATIONAL / ANALYTICAL INTERACTION`

This does not mean the Finance/CostControl backend domain is entirely read-only. For enrolled scopes, CostControl write-owns Cost Ledger, `CostAvcoState`, and related durable derived valuation state.

CostControl does not own physical Inventory movement, Purchase Order, Goods Receipt, Supplier Invoice, AP liability source facts, GL journals, Financial Period, or Property Business Date. The workspace remains read-only unless a separate package explicitly authorizes interaction-layer mutation.

## ADR Synchronization Matrix

| ADR | Decision status | CC-G1 current result |
| :--- | :--- | :--- |
| ADR-041 | Approved | Governs future deferred delivery; current synchronous path preserved; direct coupling frozen with no expansion; consumer inactive. |
| ADR-042 | Approved from repository-history provenance | Sequence allocation/state/ledger prerequisites partly exist; strict barrier remains; deferred consumer and generic recovery inactive. |
| ADR-043 | Accepted | Legacy authority before enrollment and sole CostControl authority after complete enrollment remain; no mixed authority or global rollout. |
| ADR-082 | Active | Original `InventoryStockMovement` read-only evidence projection remains; it does not supersede later durable enrolled CostControl state. |
| ADR-083 | Active | Immutable receipt commercial evidence participates in bounded enrolled valuation; unsupported FX, backfill, currency correction, and global activation remain blocked. |

No new ADR is created.

## Existing Canonical Boundary Exception

The source-proven current synchronous direct dependency is recorded as:

`EXISTING_CANONICAL_BOUNDARY_EXCEPTION__NO_EXPANSION`

This label preserves accepted current behavior without describing it as a preferred expansion pattern. It is not new accepted product debt and is not permission for another direct import, service resolution, or class call. CC-G1 records no new accepted debt.

## Explicit Non-Goals

- No Cost Control, Inventory, Receiving, Purchasing, Payables, General Ledger, Business Date, Night Audit, or outbox runtime change.
- No migration, model, service, controller, request, policy, route, permission, seeder, or Sensitive Action intent.
- No React/TypeScript or interaction-layer change.
- No queue, worker, publisher, listener, scheduler, retry, or replay implementation.
- No generic Cost Ledger correction or deferred consumer.
- No negative-stock runtime.
- No enrollment, backfill, cutover, or global rollout.
- No dual-write reconciliation or migration between Inventory ledgers.
- No new ADR.
- No regression-baseline metadata promotion.
- No `CC-P01`, `INV-R1`, `FIN-R1`, or `PROC-R1` activation.
- No Package 21.

## Runtime Readiness Decision

`CC_RUNTIME_STILL_BLOCKED`

The exact blocker is the absence of one source-proven runtime cutover/composition contract that can introduce deferred Inventory-owned outbox delivery without double-applying the accepted synchronous enrolled Cost Ledger path, expanding the frozen direct Operations-to-CostControl dependency, or confusing `inventory_transactions` with the separate `inventory_stock_movements` quantity/evidence ledger.

CC-G1 establishes current precedence but does not establish runtime switching, coexistence, replay, retirement, or duplicate-prevention mechanics between the synchronous and future deferred modes. A `CC-P01` runtime candidate is therefore not activated.

## Next Package Decision

The next required audit is `INV-R1`. Its required boundary is the broader Inventory canonical reconciliation and producer-side source/outbox contract needed before a later CostControl runtime package can be safely identified. It must prove:

- how `inventory_transactions` and `inventory_stock_movements` remain distinct without contradictory authority;
- which Inventory-owned source/outbox evidence is canonical for future deferred Cost Ledger delivery;
- how current synchronous effects and future deferred effects cannot both apply to the same valuation sequence;
- the approved composition boundary and dependency direction; and
- the idempotency, sequence, failure, replay, and Property-isolation evidence required before consumer authorization.

This decision records `INV-R1` only as the next required audit. It does not activate or implement it. No `CC-P01` branch is created. `NO_PACKAGE_21_ACTIVATED`.
