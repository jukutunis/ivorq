# PROC-R1 Procurement Canonical Revalidation and Implementation Gap Audit

## Decision record

| Field | Frozen value |
|---|---|
| Contract | IVORQ Package Execution Contract v1.22 |
| Canonical predecessor | `552dd484c7f4843be59edb9e715aa2ead77620d9` |
| Predecessor tree | `e8944de216e710fc8731038f848bec33452a5e2a` |
| Domain | MPD-PROC — Procurement / Purchasing / Receiving / Vendor controls |
| Registry priority | P0 |
| Audit mode | Canonical source-backed, documentation only |
| Runtime / migration / dependency delta | `0 / 0 / 0` |
| Tests executed | None; current source and existing tests were read as evidence |

This record freezes current canonical source reality. It does not activate `PROC-P01`, change roles or permissions, consolidate Inventory ledgers, activate deferred CostControl delivery, or authorize live operations.

## Ownership boundary

- Procurement owns tenant vendor identity and eligibility, property-owned requisition and purchase-order facts, and their commercial lifecycle.
- Receiving owns `ReceivingDocument` evidence where that aggregate is used, but it is not currently exposed as a registered operational workspace or HTTP entry path.
- Inventory owns controlled Goods Receipt posting, physical quantity movements, source identity, idempotency, and the bounded ledger universes governed by current Inventory records.
- CostControl owns monetary valuation state and Cost Ledger effects. Existing enrolled delivery is `SYNCHRONOUS_TRANSITIONAL_ACTIVE`; deferred delivery is `INACTIVE_FUTURE_CANDIDATE`.
- Finance/Payables owns supplier-invoice intake, the three-way matching decision, exception handling, GRNI/AP interpretation, and any future clearing candidate. It may validate Procurement evidence but must not rewrite it.

## Canonical source map

```text
Purchasing PurchaseRequest / PurchaseRequestLine               PARTIAL
  -> Purchasing PurchaseOrder / PurchaseOrderLine              PARTIAL
      -> Inventory GoodsReceipt / GoodsReceiptLine              COMPLETE, operational ADR-080 path
          -> InventoryStockMovement                             COMPLETE in this bounded path
          -> immutable receipt commercial evidence             COMPLETE

Receiving ReceivingDocument / ReceivingLine                    PARTIAL, separate source universe
  -> InventoryReceiptIntegrationService
      -> InventoryTransaction + stock projection
      -> synchronous CostControl for enrolled scopes
  -> Finance SupplierInvoice intake / ThreeWayMatchingEngine    COMPLETE consumer boundary

Purchasing ReceivingService + purchasing goods-receipt route   LEGACY_ACTIVE and broken
```

The repository does not prove one end-to-end canonical receipt fact shared by controlled Inventory Goods Receipt and Finance invoice intake. The Inventory `GoodsReceipt` path is the registered operational ADR-080 receipt path. Finance currently consumes `ReceivingDocument` / `ReceivingLine` evidence. These are parallel bounded sources and must not be silently dual-written or merged.

## Capability classifications

| # | Material capability | Classification | Canonical finding |
|---:|---|---|---|
| 1 | Vendor canonical identity and ownership | `DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION` | The referenced `vendors` table is the one identity used by Purchasing and Finance, but the model, migration, global property scope, controller, policy, and uniqueness still permit hybrid Property/Company ownership. This contradicts accepted ADR-006, which requires Company-only ownership and preservation of one tenant vendor identity. Do not create a second master. |
| 2 | Vendor operational lifecycle and purchasing eligibility | `PARTIAL` | API, policy, contacts, active flag, approval flag, currency, terms, tax fields, and audit logging exist. Approval is a reversible toggle, tenant authority is not enforced, and no operational UI exists. |
| 3 | Requisition creation and line entry | `PARTIAL` | Models, migrations, API, repository, service, positive quantity checks, budget validation, item/UOM fields, and activity logging exist. Browser-supplied request number, requester, department, item/UOM, currency, and exchange rate are not fully server-resolved against active Property/Tenant authority. |
| 4 | Requisition submit/approve/reject/cancel lifecycle | `PARTIAL` | Approval Engine integration exists for submit/approve/reject, but approval policies lack a specific approval permission and explicit maker/checker enforcement. `cancel()` references enum cases that do not exist, so cancellation is not executable as written. No controlled rejected-to-new-draft revision exists. |
| 5 | Requisition workspace/list/detail/create/edit UX | `MISSING` | No Purchasing React/Inertia requisition workspace or registered operational web path was found. The API foundation alone is not operational completion. |
| 6 | Requisition idempotency and concurrency | `MISSING` | No source-backed request idempotency key or serialization proof exists for creation, submission, approval, or cancellation. |
| 7 | Approved requisition to purchase-order conversion | `PARTIAL` | One PR to one PO is database-unique and approved-PR gated, and lines are copied. Conversion does not lock the PR, the generated number scans the latest row without serialization, and same-property/same-tenant vendor and PR authority is not completely revalidated at the service boundary. |
| 8 | Purchase-order commercial model and lifecycle | `PARTIAL` | PO/vendor/lines/quantity/price/currency/status/API and audit source exist. Draft can be issued directly; there is no registered submit/approve/reject controller path; mutable commercial fields remain editable beyond the safe pre-commit boundary; amendment/revision semantics are absent. |
| 9 | Purchase-order approval and maker/checker | `PARTIAL` | The model implements `ApprovableContract` and existing tests/listeners show approval-shaped foundation, but no operational PO approval routes/actions enforce PO creator != approver. The PO approval permission is not composed into a registered controller path. |
| 10 | Purchase-order workspace/list/detail/create/edit/approval UX | `MISSING` | No Purchasing React/Inertia PO workspace or registered operational web path was found. |
| 11 | PO numbering, duplicate protection, idempotency, and concurrency | `PARTIAL` | Database uniqueness protects one PO per PR and Property + PO number. The latest-row number scan and conversion are not source-proven serialized; no request idempotency contract exists. |
| 12 | Controlled Inventory Goods Receipt | `COMPLETE` | Registered Inventory web routes, controller, workspace/detail UI, explicit receive permission, approved/issued PO eligibility, server-resolved line/item/UOM, same-property checks, positive and remaining quantity governors, partial/multiple receipts, sensitive confirmation, receiver != PO approver, idempotency, database concurrency protection, and immutable posting are present within ADR-080 scope. |
| 13 | Receipt commercial evidence | `COMPLETE` | ADR-083 immutable commercial snapshot and evidence-hash binding are captured atomically at posting. Evidence is source-only, not AP, GL, or a second valuation book. Foreign-currency valuation remains outside this completion claim. |
| 14 | ReceivingDocument aggregate, discrepancy, and inspection foundation | `PARTIAL` | Models, migrations, services, attachments/comments, approval integration, discrepancy and inspection source exist. Draft validation does not fully prove PO/vendor/property/line/location/UOM/over-receipt authority, cancellation lacks a state guard, and receiver/actor authority is not consistently resolved at an operational boundary. |
| 15 | ReceivingDocument registered operational path and UI | `MISSING` | The provider loads migrations and approval listeners, but no Receiving controllers, routes, or React/Inertia workspace were found. |
| 16 | ReceivingDocument to Inventory transaction handoff | `PARTIAL` | Approval can synchronously create controlled `InventoryTransaction` evidence, require an open Business Date and actual approver, fail closed on mixed enrollment, and invoke enrolled CostControl. The upstream ReceivingDocument validation/entry path is incomplete, so the handoff is not an operationally complete Procurement chain. |
| 17 | One canonical receipt source across Operations, Inventory, and Finance | `DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION` | Operational Inventory `GoodsReceipt` and Finance-facing `ReceivingDocument` are parallel source universes with different ledgers and consumers. Current Inventory governance prohibits automatic consolidation or dual-write. |
| 18 | Purchasing legacy receiving route/service | `LEGACY_ACTIVE` | Purchasing still registers a goods-receipt route to a missing controller. Its `ReceivingService` imports missing/dropped legacy GoodsReceipt types and attempts writes across legacy receipt, ReceivingDocument, Inventory receipt, PO, and posting paths. It is not a valid authority and must be retired only in an authorized runtime package. |
| 19 | Inventory ledger-universe convergence | `BLOCKED_BY_OTHER_DOMAIN` | Inventory governance freezes parallel bounded universes. Procurement may consume approved contracts but may not merge ledgers, promote movement evidence to monetary authority, or introduce dual-write. |
| 20 | Synchronous CostControl valuation for enrolled transaction paths | `COMPLETE` | Current `InventoryTransaction`-sourced enrolled Receipt/Receiving paths synchronously apply durable CostControl state and Cost Ledger effects inside the controlled transaction. This is the current transitional canonical path and must not be expanded by Procurement. |
| 21 | Deferred CostControl delivery | `DEFERRED` | Inventory outbox foundation exists, but no production deferred consumer is active. Consumer, queue, worker, replay, watermark cutover, and synchronous retirement remain unauthorized. |
| 22 | Finance supplier-invoice intake and three-way match consumption | `COMPLETE` | FIN-P01 source validates Property, vendor, PO, receipt and line provenance, cumulative receiving capacity, row locking, and claimed quantities. Finance owns the matching decision and exception interpretation; Procurement must not rebuild it. |
| 23 | GRNI clearing and AP liability runtime | `BLOCKED_BY_OTHER_DOMAIN` | Accepted ADR-047 freezes the Finance-owned future candidate boundary and explicitly states runtime clearing/AP liability is not yet authorized. Procurement supplies immutable source facts only. Proposed ADR-011 is not independent runtime authorization. |
| 24 | Posted receipt correction, reversal, and supplier return | `BLOCKED_BY_ADR` | ADR-080 explicitly excludes posted receipt reversal, edit, cancellation, and supplier return. Any future correction must coordinate Inventory quantity, CostControl valuation, Finance/GRNI, provenance, and Business Date/Financial Period rules. |
| 25 | Landed cost, tax apportionment, and PPV runtime | `BLOCKED_BY_ADR` | ADR-014 and ADR-015 remain Proposed. Their accounting and valuation semantics must not be introduced through Procurement source changes. |
| 26 | Procurement permission, role, approval, and audit composition | `PARTIAL` | Granular permissions and activity logging exist, but approval authorization is inconsistent and registered operational paths do not compose all intended permissions/maker-checker rules. `RoleSeeder` is outside PROC-R1 and must not be changed opportunistically. |
| 27 | Unified Procurement workspace/navigation and empty states | `MISSING` | Controlled Goods Receipt has Inventory UI; Vendor, PR, PO, and Receiving do not form a usable Procurement workspace with queues, actions, provenance, and empty/error states. |
| 28 | Receipt discrepancy/damage/rejection operational resolution | `PARTIAL` | Receiving discrepancy/inspection source exists, but no registered user path or accepted cross-domain correction semantics turns it into a controlled operational resolution. |
| 29 | External supplier integration, production activation, pilot, and cutover | `OUT_OF_SCOPE` | No external supplier transmission, AWS/live action, Business Date/Financial Period operational start, production activation, pilot, or cutover is authorized. |

### Classification totals

| Roll-up | Count |
|---|---:|
| `COMPLETE` | 4 |
| `PARTIAL` | 11 |
| `MISSING` | 5 |
| `LEGACY_ACTIVE` or `DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION` | 3 |
| `BLOCKED_BY_ADR`, `BLOCKED_BY_OTHER_DOMAIN`, or `DEFERRED` | 5 |
| `OUT_OF_SCOPE` | 1 |
| **Total material capabilities** | **29** |

## Boundary decisions

### Requisition and Purchase Order

The canonical models are `Modules/Operations/Purchasing/Models/PurchaseRequest.php` and `PurchaseOrder.php` with their line models. They are the only Procurement commercial-intent path to extend. They are foundation source, not an operationally complete control plane. The accepted ADR-080 target remains one approved requisition to one approved Purchase Order for one Property, one tenant-owned Vendor, and tracked Inventory items.

### Vendor

The canonical identity is the existing `vendors` table. Accepted ADR-006 requires Company ownership, Company-scoped uniqueness, and Property-owned transactional documents referencing that one tenant Vendor. Current hybrid source is a defect to correct without cloning, merging, or replacing vendor IDs silently. Historical references from Purchasing and Finance must remain stable.

### Receiving and Goods Receipt

The currently registered operational receipt path is Inventory-owned `GoodsReceipt` through `ControlledGoodsReceiptPostingService`. `ReceivingDocument` is a separate backend foundation and the current receipt identity consumed by Finance intake. Neither is declared a universal enterprise stock authority. A later package must choose an explicit read-only evidence adapter or governed source transition; it must never write both receipt/ledger universes for one fact.

### Inventory and CostControl

Controlled Goods Receipt creates one immutable `InventoryStockMovement` per posted line in its accepted universe. `ReceivingDocument` integration creates `InventoryTransaction` evidence in the separate transaction universe. `InventoryTransaction` remains the CostControl delivery authority where current governance defines it. `InventoryStockMovement` and its commercial snapshot must not be promoted to a second durable monetary book. Existing synchronous enrolled CostControl composition remains active; deferred delivery remains inactive.

### Finance, three-way match, and GRNI

Procurement owns Vendor/PO/receipt source facts. Finance owns invoice evidence, match decisions, exceptions, GRNI/AP candidate interpretation, and future clearing. FIN-P01 is not reopened. The material Procurement gap is the ambiguous receipt source contract feeding Finance, not the matching engine itself.

## Runtime package sequence

### PROC-P01 — Tenant Vendor and Operational Requisition-to-Approved-PO Control Plane

**Priority:** first and highest value. This is the one selected `PROC-P01`.

Deliver one coherent vertical slice from tenant Vendor eligibility through a Property requisition to an approved/issued PO that the accepted controlled Inventory Goods Receipt can consume.

Exact frozen scope:

- **Submodules:** Purchasing Vendor, Purchase Request, Purchase Order, their line models, Approval Engine integration, and a Procurement interaction-layer workspace. No Receiving, Inventory ledger, CostControl, Payables, or GL mutation.
- **Vendor authority:** migrate the existing Vendor master to accepted ADR-006 Company-only ownership; remove Property authority and Property-scoped uniqueness; preserve existing IDs; introduce a deterministic preflight that fails closed on ambiguous cross-Property duplicates rather than silently merging them; resolve eligible Vendors through active Property -> Company.
- **Backend:** server-resolve Property, Company, requester/creator, department membership, item, canonical UOM, Vendor eligibility, currency, numbering, status, and approval actors. Correct the invalid PR cancellation enum path. Enforce explicit state machines and prohibit post-approval commercial mutation. Do not add standalone PO creation unless an accepted architecture update authorizes it.
- **Schema impact:** only additive/controlled migration changes required for Company-only Vendor constraints, request/PO idempotency and serialization, and durable approval/provenance invariants. No new Vendor master and no destructive history rewrite.
- **UI:** one Procurement workspace with Vendor eligibility views, requisition list/detail/create/edit/submit/approve/reject/cancel, PO list/detail/convert/submit/approve/reject/issue/cancel, approval queues, provenance, empty/loading/error states, and links into the existing controlled Goods Receipt workspace only for eligible POs.
- **Permissions:** compose existing granular permissions at every route/action. Any permission or role-assignment change requires explicit package evidence and focused review; do not opportunistically broaden `RoleSeeder`.
- **Maker/checker:** requester != requisition approver; PO creator != PO approver; actor identity must be server-derived and approval evidence durable.
- **Audit:** preserve immutable approval/rejection evidence, actor, time, reason, prior state, source IDs, and commercial snapshot/version relevant to the action. Rejection returns through a controlled new-draft revision or another explicitly accepted append-only design; it must not erase history.
- **Integrations:** output only an approved/issued same-Property PO using the existing PO identity expected by `ControlledGoodsReceiptPostingService`. Do not change controlled receipt posting, Finance matching, GRNI, Inventory ledger authority, or CostControl delivery.
- **Idempotency/concurrency:** serialize number allocation and PR-to-PO conversion; lock and revalidate PR/Vendor/PO state; enforce one accepted outcome per idempotency key; retain database uniqueness as the final barrier; prove concurrent conversion, submit, approve/reject, and issue behavior with independent PostgreSQL contexts where races are material.
- **Focused validation:** targeted PostgreSQL Vendor migration/tenant-isolation tests; PR/PO state-machine, cross-Property, browser-authority, maker/checker, permission, idempotency/concurrency tests; focused interaction-layer tests; existing controlled Goods Receipt eligibility contract regression; production RoleSeeder diff check; migration and dependency review. No broad Finance rerun unless an unexpected consumed contract changes.
- **Definition of done:** a permitted user can complete Vendor selection -> requisition -> approval -> PO conversion -> PO approval/issue through registered UI; every ownership field is server-authoritative; cross-Property and cross-Tenant use fails closed; concurrent/replayed actions produce one result; no post-approval commercial edit is possible; the unchanged controlled Goods Receipt accepts only the intended PO; production/dependency scope remains reviewable and no live activation occurs.

### PROC-P02 — Receiving Source Authority and Finance Evidence Convergence

Freeze and implement one governed receipt-evidence contract without merging Inventory ledgers. Retire the broken Purchasing goods-receipt route/service. Decide whether Finance reads a read-only adapter over controlled Inventory Goods Receipt or whether a separately governed transition makes one source authoritative. Preserve source IDs, partial/multiple receipt capacity, immutable commercial evidence, wrong-PO/Property/vendor/UOM protections, and current synchronous CostControl ownership. Prove no dual-write and no duplicate receipt/movement/Finance capacity.

This package requires explicit Inventory and Finance boundary review before implementation. It must not activate deferred CostControl delivery or claim unified stock authority.

### PROC-P03 — Receiving Exception and Controlled Correction Lifecycle

After PROC-P02 source authority is accepted, deliver discrepancy, damage/short receipt, supplier rejection, commercial correction, and any authorized reversal/return lifecycle. New Inventory/CostControl/Finance semantics require accepted ADR or cross-domain authorization first. Use append-only compensating facts, not mutation of posted receipt, movement, valuation, or accounting evidence.

## Governing architecture status

| Record | Status and effect |
|---|---|
| ADR-006 | Accepted; existing Vendor implementation is not conformant and must be corrected in PROC-P01. |
| ADR-011 | Proposed; describes GRNI direction but does not authorize runtime. |
| ADR-014 | Proposed; PPV/variance runtime blocked. |
| ADR-015 | Proposed; landed-cost/tax runtime blocked. |
| ADR-032 | Proposed; informative domain model, not independent runtime authority. |
| ADR-047 | Accepted; Finance-owned GRNI/AP candidate boundary, with clearing runtime explicitly not yet authorized. |
| ADR-080 | Accepted; governs the narrow PR -> PO -> controlled Goods Receipt -> movement chain and its non-goals. |
| ADR-083 | Active; governs immutable receipt commercial evidence and bounded valuation eligibility; foreign-currency valuation remains deferred. |
| Current Inventory/Cost records | Parallel bounded ledger universes remain frozen; synchronous enrolled CostControl delivery remains active; deferred consumer remains inactive. |

## Closure guardrails

- Production delta: `0`
- Migration delta: `0`
- Dependency delta: `0`
- Tests executed: `0`
- AWS/live actions: `0/0`
- Operational First Trust: disabled
- Business Date operational start: not performed
- Financial Period operational start: not performed
- Pilot/cutover: `NO/NO`
- PROC-P01 implementation: not started

PROC-R1 is ready for independent review only. Its branch and Draft PR must not be merged without subsequent Owner authorization.
