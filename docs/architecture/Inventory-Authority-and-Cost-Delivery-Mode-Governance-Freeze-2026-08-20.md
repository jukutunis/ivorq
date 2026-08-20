# Inventory Authority and Cost Delivery Mode Governance Freeze (INV-G1)

## Package identity and authority

- Package: `INV-G1_INVENTORY_AUTHORITY_AND_COST_DELIVERY_MODE_GOVERNANCE_FREEZE`
- Package type: `GOVERNANCE_ONLY / FREEZE_ONLY`
- Record date: 2026-08-20
- Canonical branch: `ivorq-enterprise-core`
- Canonical predecessor: `8df32e31a332c9c55c052ade145d2328ff690859`
- Accepted predecessor workstream: `INV-R1_INVENTORY_CANONICAL_RECONCILIATION_AND_PRODUCER_SOURCE_OUTBOX_BOUNDARY_AUDIT`
- Accepted INV-R1 PR: #58
- Accepted INV-R1 final feature HEAD: `93b0ea6e40ecc12cd10e06334e60887450f8c9ce`
- Accepted INV-R1 canonical merge: `8df32e31a332c9c55c052ade145d2328ff690859`
- Package Execution Contract: Version 1.22, `UNCHANGED`
- Runtime authorization: `NONE`

This record converts the accepted INV-R1 findings into a non-ambiguous governance boundary. It changes no runtime, persistence, tests, ADRs, regression metadata, or Contract text. It does not reconcile the two Inventory ledgers, activate a deferred CostControl consumer, retire synchronous valuation, or authorize rollout.

## Bounded canonical revalidation

INV-G1 performed only the Owner-authorized bounded read-only revalidation of the named Inventory, CostControl, enrollment, outbox, ADR, INV-R1, and CC-G1 sources. The canonical predecessor contains no material contradiction to accepted INV-R1 truth:

- `InventoryPostingControlCoordinator` atomically allocates the Inventory-owned valuation sequence, appends immutable `InventoryTransaction` evidence, creates the minimal pending outbox record, and updates `InventoryStock.physical_quantity`.
- `InventoryLedgerPostingService` writes the distinct immutable `InventoryStockMovement` quantity ledger and derives its controlled quantity by signed IN/OUT aggregation.
- current controlled valuation invocation services use the posted `InventoryTransaction` and its exact sequence to synchronously apply CostControl effects for enrolled workflows;
- enrollment is identified by Property + Item and preserves included location/scope snapshots with opening quantity, carrying value, currency, Business Date, and Financial Period evidence;
- `CostLedgerEntry.source_inventory_transaction_id` resolves to `InventoryTransaction`, while `CostAvcoState.last_valuation_sequence` records the consumed Inventory sequence;
- the Inventory outbox producer exists, but no production deferred CostControl delivery consumer is composed;
- `InventoryReversalPostingService` appends a new opposite-signed `InventoryTransaction`, allocates a new sequence, synchronously appends Cost Ledger/state effects, and creates no transaction outbox row; and
- the accepted INV-R1 and CC-G1 records remain aligned with ADR-041, ADR-042, ADR-043, and ADR-079 through ADR-083.

The consumer-shaped paired-transfer service remains uncomposed production code as classified by INV-R1; Foundation outbox state operations and enrollment preflight reads do not constitute deferred delivery activation.

## Canonical authority matrix

| Evidence or state | Owner | Canonical authority | Permitted role under this freeze | Prohibited inference |
|---|---|---|---|---|
| `InventoryTransaction` | Operations/Inventory | Immutable transaction, monetary, valuation-provenance, reversal, and Cost Ledger source evidence within its bounded workflow family | Current synchronous and future deferred CostControl source evidence | Completeness for movement-only workflows |
| `InventoryStock.physical_quantity` | Operations/Inventory | Mutable current operational balance projection for the `InventoryTransaction` workflow universe | Current balance for that universe only | Historical valuation evidence, deferred delivery evidence, or unified enterprise stock |
| `InventoryStockMovement` | Operations/Inventory | Immutable controlled forward physical movement/quantity evidence within its bounded workflow family | Signed quantity evidence: IN positive, OUT negative | Monetary Cost Ledger, GL/AP, CostAvcoState, or alternate valuation-sequence authority |
| Inventory valuation sequence | Operations/Inventory | Immutable posting-time sequence per Property + Location + Item | Exact sequence CostControl must consume | Queue, ULID, timestamp, outbox delivery, or Cost Ledger insertion ordering |
| Inventory outbox record | Operations/Inventory via Foundation primitive | Minimal durable delivery-source candidate tied to `InventoryTransaction` | Future deferred eligibility only after all gates in this freeze | Eligibility from topic, pending state, or existence alone |
| `CostAuthorityEnrollmentGroup` and snapshots | Finance/CostControl | Complete Property + Item authority group across accepted location/scope snapshots | Governs one valuation authority and one delivery-mode owner for the complete group | Mixed location-level mode authority within the accepted group |
| `CostAvcoState` | Finance/CostControl | Durable AVCO state per Property + Location + Item | Advances only from the exact eligible Inventory valuation sequence | Reconstruction from mutable `InventoryStock` or movement-only evidence |
| `CostLedgerEntry` | Finance/CostControl | Immutable derived monetary valuation record sourced from `InventoryTransaction` | One equivalent durable effect for an eligible source transaction | Second effect under a different delivery-mode idempotency string |
| ADR-082 movement projection | Finance/CostControl analytical boundary | `READ_ONLY_EVIDENCE` | Read-only controlled AVCO evidence projection | Second durable valuation book, GL/AP source, or Cost Ledger replacement |

## Freeze 1 — Inventory universe authority

Exactly two current Inventory workflow universes are frozen.

### Transaction / valuation universe

`InventoryTransaction` plus `InventoryStock.physical_quantity` form the transaction/valuation universe within its bounded workflow family. `InventoryTransaction` is immutable transaction, monetary, valuation-provenance, reversal, and Cost Ledger source evidence. `InventoryStock.physical_quantity` is its mutable current operational balance projection.

### Controlled movement universe

`InventoryStockMovement` is immutable controlled forward physical movement evidence within its bounded workflow family. Its quantity projection treats IN as positive and OUT as negative. It is not monetary Cost Ledger source authority.

The governing classifications are:

- `INVENTORY_UNIVERSE_RELATIONSHIP = PARALLEL_BOUNDED_WORKFLOW_UNIVERSES`
- `UNIFIED_ENTERPRISE_STOCK_AUTHORITY = NOT_CLAIMED`
- `LEDGER_AUTO_MERGE = PROHIBITED`
- `CROSS_UNIVERSE_DUAL_WRITE = NOT_AUTHORIZED`
- `INVENTORY_STOCK_MOVEMENT_MONETARY_PROMOTION = PROHIBITED`
- `INVENTORY_TRANSACTION_MOVEMENT_COMPLETENESS_CLAIM = PROHIBITED`

No service, workspace, report, integration, or downstream domain may silently treat either ledger as complete for the other universe.

## Freeze 2 — Cost delivery source authority

`COST_DELIVERY_SOURCE_AUTHORITY = InventoryTransaction`

A future deferred CostControl path must resolve immutable `InventoryTransaction` evidence read-only. `InventoryStockMovement` must not become Cost Ledger, CostAvcoState, GL monetary, AP monetary, or alternate valuation-sequence authority. `InventoryStock.physical_quantity` must not become deferred valuation evidence, valuation-sequence evidence, or Cost Ledger reconstruction evidence. The ADR-082 movement-based AVCO projection remains `READ_ONLY_EVIDENCE` and never becomes a second durable valuation book.

## Freeze 3 — Cost authority and enrollment scope

`DELIVERY_MODE_OWNERSHIP_SCOPE = COMPLETE_PROPERTY_ITEM_ENROLLMENT_GROUP`

`CostAuthorityEnrollmentGroup` is identified by Property + Item. Its accepted scope snapshots preserve the complete included locations, canonical valuation scopes, opening quantities, opening carrying values, currencies, Business Dates, and Financial Period evidence. One delivery mode owns the complete accepted Property + Item group across all included location snapshots. Mixed synchronous/deferred ownership inside that group is prohibited.

INV-G1 selects no database field, table, column, enum, or migration for delivery-mode persistence. Exact persistence belongs to a later authorized runtime plan.

## Freeze 4 — Current delivery mode

- `CURRENT_COST_DELIVERY_MODE = SYNCHRONOUS_TRANSITIONAL_ACTIVE`
- Current path: `TRANSITIONAL_EXISTING_CANONICAL_PATH`
- Existing dependency: `EXISTING_CANONICAL_BOUNDARY_EXCEPTION__NO_EXPANSION`

The accepted synchronous Operations/Inventory or Receiving-to-CostControl composition remains active until a later explicitly authorized cutover package safely disables or bypasses it for deferred-owned sequences. No new direct Inventory/Receiving-to-CostControl dependency may be added.

## Freeze 5 — Future deferred mode and ownership

`DEFERRED_COST_DELIVERY_MODE = INACTIVE_FUTURE_CANDIDATE`

The future dependency direction remains `Finance/CostControl -> Operations/Inventory`. Inventory owns `InventoryTransaction`, immutable source evidence, valuation-sequence allocation, and outbox production. CostControl owns deferred source resolution, validation, AVCO plan/apply, `CostAvcoState`, Cost Ledger, and consumer-processing outcome. Inventory must not construct, import, instantiate, or invoke CostControl implementation classes in deferred mode.

## Freeze 6 — Mode mutual exclusion

`ONE_SOURCE_TRANSACTION_ONE_VALUATION_MODE`

For one `InventoryTransaction`, exactly one of `SYNCHRONOUS_OWNED` or `DEFERRED_OWNED` may apply durable CostControl valuation. Never both. Dual-run, shadow write, canary double-apply, fallback to synchronous after deferred apply, and deferred replay of synchronously satisfied history are prohibited.

## Freeze 7 — Cutover unit and watermark

Future cutover operates on the complete accepted Property + Item enrollment group, never an arbitrary request, line, location, or UI action. Within the group, valuation ordering remains per `PROPERTY_LOCATION_ITEM` scope.

Before deferred processing begins, every included valuation scope must have durable mode ownership and a durable cutover watermark identifying the boundary between:

- `LAST_SYNCHRONOUSLY_OWNED_SEQUENCE`; and
- `FIRST_DEFERRED_OWNED_SEQUENCE`.

The exact schema representation is deferred. Governance requires:

- `NO_SEQUENCE_MAY_BE_OWNED_BY_BOTH_MODES`; and
- `NO_SEQUENCE_MAY_HAVE_UNDEFINED_MODE_OWNERSHIP_AFTER_CUTOVER`.

## Freeze 8 — Valuation-sequence ownership

Inventory owns the immutable valuation sequence allocated during source posting. CostControl must consume that exact sequence. Queue order, ULID order, timestamp order, Cost Ledger insertion order, and outbox delivery order may not replace it.

ADR-042's strict sequence barrier remains governing per Property + Location + Item: sequence N+1 must not plan, append, or advance `CostAvcoState` while sequence N is unresolved.

## Freeze 9 — Historical outbox disposition

Existing pending outbox rows may represent transactions already valued synchronously. They are not automatically delivery-eligible when a future consumer is activated and must be durably classified before cutover using policy classes equivalent to:

| Historical class | Deferred disposition |
|---|---|
| `SYNCHRONOUSLY_SATISFIED_HISTORY` | `MUST_NOT_APPLY_DEFERRED` |
| `UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY` | `MUST_NOT_APPLY_DEFERRED` merely because an outbox row exists |
| `DEFERRED_OWNED_AFTER_CUTOVER` | Eligible only under the approved consumer rules |

INV-G1 does not add an enum or persistence field and does not mutate outbox rows. A later runtime package must prove a durable historical-disposition mechanism before consumer activation.

## Freeze 10 — Deferred eligibility

A future outbox row is not CostControl delivery-eligible from topic, pending state, or existence alone. Eligibility fails closed unless source evidence proves all applicable facts:

- supported topic;
- valid immutable `InventoryTransaction`;
- correct Property;
- supported source and transaction semantics;
- accepted complete CostAuthority enrollment;
- deferred mode ownership for the complete Property + Item group;
- valuation scope membership in the accepted enrollment snapshot;
- valuation sequence on or after the deferred cutover watermark;
- no prior synchronous satisfaction;
- valid Business Date and Financial Period evidence under governing rules; and
- no durably equivalent prior application.

## Freeze 11 — Idempotency across modes

Mode-specific idempotency strings are insufficient. Future deferred execution must prove exact source equivalence including Property identity, source `InventoryTransaction` identity, valuation scope, valuation sequence, quantity/value evidence, Cost Ledger source relation, and ADR-042 structured idempotency evidence.

A synchronously valued source transaction must not create a second Cost Ledger effect merely because deferred processing uses another idempotency string. INV-G1 prescribes no new unique index.

## Freeze 12 — Reversal mode alignment

Current reversal creates a new immutable opposite-signed `InventoryTransaction`, allocates a new valuation sequence, applies CostControl synchronously, and creates no Inventory transaction outbox row.

`DEFERRED_MODE_CANNOT_ACTIVATE_WHILE_A_VALUATION_SEQUENCE_PRODUCING_REVERSAL_CAN_BYPASS_THE_SELECTED_DELIVERY_MODE`

A later runtime plan must either provide deferred reversal source/outbox treatment under the same sequence barrier or another source-proven mode-safe design preventing mixed synchronous/deferred ownership. INV-G1 chooses no implementation and changes no reversal runtime.

## Freeze 13 — Crash and failure semantics

ADR-042 remains governing. Where applicable, one future deferred-processing transaction must atomically compose source validation, AVCO planning, Cost Ledger append, `CostAvcoState` update, and outbox delivered transition.

- A completed business failure fails closed, appends no valuation effect, and does not advance state.
- Interrupted execution must not silently become successful.
- Later sequences remain blocked behind an unresolved earlier sequence.
- No automatic retry is authorized.
- No replay command is authorized by INV-G1.

## Freeze 14 — Synchronous retirement conditions

Adding a consumer does not activate deferred delivery. At the same durable cutover boundary, a future runtime package must prove that:

- synchronous ownership is disabled or bypassed for deferred-owned sequences;
- deferred eligibility begins only for deferred-owned sequences;
- historical synchronously satisfied rows remain non-deliverable;
- no request can pass through both paths;
- every supported valuation-sequence-producing transaction type follows exactly one mode;
- reversal behavior is mode-safe; and
- the existing direct dependency is not expanded.

The current synchronous path remains active until all of that proof exists.

## Freeze 15 — Stock universe versus cost delivery

Deferred CostControl activation must not depend on a false unified-stock claim. `InventoryTransaction` is the cost-delivery source universe. `InventoryStockMovement` remains outside CostControl monetary delivery unless a separately authorized future governance/runtime package changes that boundary.

Controlled Goods Receipt and movement-only workflows do not silently become CostControl transaction sources because deferred delivery exists. Any future bridge between the two Inventory universes requires separate explicit Owner authorization.

## Freeze 16 — Explicit non-goals and no global activation

INV-G1 authorizes none of the following:

- production PHP, React/TypeScript, runtime, queue, worker, listener, scheduler, publisher, retry, replay, or consumer changes;
- migration, model, service, controller, request, policy, route, permission, outbox-state, Inventory-data, Cost-Ledger-data, or CostAvcoState-data changes;
- Inventory ledger merge, auto-merge, cross-universe dual write, historical ledger rewrite, or backfill;
- all-Property rollout, automatic enrollment, or global activation;
- FX expansion, negative inventory, generic correction, Business Date reopen, Financial Period reopen, or new GL posting behavior;
- POS integration or Procurement expansion;
- test, regression-baseline metadata, Contract, or ADR changes; or
- `CC-P01` or Package 21 activation.

## ADR reconciliation and verdict

| ADR | Existing decision applied by INV-G1 |
|---|---|
| ADR-041 | Inventory-owned immutable source/outbox evidence, CostControl read-only source resolution, one-way future dependency, and no consumer activation |
| ADR-042 | Exact Inventory sequence, strict per-scope barrier, atomic deferred outcome, fail-closed errors, and no automatic retry/replay authorization |
| ADR-043 | Complete Property + Item authority enrollment, no mixed authority, immutable cutover evidence, and per-location state/sequence scope |
| ADR-079 | Bounded immutable quantity-only `InventoryStockMovement` universe with no historical completeness or monetary claim |
| ADR-080 | Controlled Goods Receipt writes the movement ledger without direct stock mutation or AP/accounting expansion |
| ADR-081 | Inventory-owned directional movement lifecycle and quantity protection with no unified or correction claim |
| ADR-082 | Movement-based AVCO projection remains `READ_ONLY_EVIDENCE`, not durable monetary authority |
| ADR-083 | Immutable receipt commercial/currency evidence and bounded activation, without FX or correction expansion |

`ADR_VERDICT = NO_NEW_ADR_REQUIRED`

Existing ADRs define the applicable architecture. INV-G1 synchronizes and freezes those decisions; it does not create a new architecture decision.

## Runtime-planning gate

`RUNTIME_PLANNING_VERDICT = CC_P01_RUNTIME_PLANNING_ELIGIBLE_NOT_ACTIVATED`

The accepted INV-R1 source findings plus this explicit mode, cutover, sequence, historical-disposition, idempotency, reversal, and retirement freeze are sufficient to permit a later separately authorized CC-P01 planning package. Planning eligibility is not runtime authorization. CC-P01 remains `NOT ACTIVATED`, and INV-G1 creates no CC-P01 branch, code, migration, consumer, queue, worker, listener, scheduler, replay command, or runtime test package.

`NO_PACKAGE_21_ACTIVATED`

## Validation evidence and package closure

No PostgreSQL or full-registry test rerun is required because this package changes documentation only and starts from the accepted INV-R1 canonical merge. Retained predecessor evidence is:

| Evidence | Result |
|---|---|
| INV-R1 focused PostgreSQL | 9 classes; 156 tests; 502 assertions; 0 failures; 0 errors; exit 0 |
| Inherited reversal baseline | 8 tests; 72 assertions; 0 failures; 2 accepted errors |
| INV-C1 full active registry predecessor | 14 targets; 1,351 tests; 13,108 assertions; 0 failures; 2 accepted errors; 0 skipped; exit 0 |

Package closure declarations:

- `INV-G1 = GOVERNANCE_FREEZE_COMPLETE`
- Production changes: `NONE`
- Test changes: `NONE`
- Migration changes: `NONE`
- ADR changes: `NONE`
- Regression metadata: `UNCHANGED`
- Contract: `1.22 UNCHANGED`
- Runtime authorization: `NONE`
- `CC-P01 = NOT ACTIVATED`
- `NO_PACKAGE_21_ACTIVATED`
