# ADR-082: Controlled AVCO Cost Evidence and Cost Control Boundary

## ADR Metadata
* **ADR Number:** ADR-082
* **ADR Title:** Controlled AVCO Cost Evidence and Cost Control Boundary
* **Boundary:** Deferred
* **Date:** 2026-07-08
* **Status:** Active (Runtime Deferred)
* **Related ADRs:** ADR-001 (Multi-Tenant Hierarchy), ADR-004 (Finance Module Boundary), ADR-035 (Inventory Ledger Canonical Persistence), ADR-040 (IVORQ Interaction Layer Standard), ADR-041 (Durable Derived Ledger Delivery via Transactional Outbox), ADR-042 (Deferred AVCO Cost Ledger Delivery Semantics), ADR-079 (Controlled Inventory Ledger and Goods Receipt Posting Architecture), ADR-080 (Controlled Purchasing Requisition Purchase Order and Goods Receipt Integration), ADR-081 (Controlled Inventory Movement Lifecycle and Quantity Protection)

## Runtime Activation

**DEFERRED**

The AVCO projection algorithm, cost eligibility service, and PostgreSQL test suite are complete (40 tests, 120 assertions passing on pgsql/ivorq_testing). However, runtime activation is deferred because the source commercial evidence is not temporally stable after Goods Receipt posting.

### Temporal Stability Evidence (PostgreSQL-Proven)

The following tests proved instability of AVCO input sources:

1. **Property base currency is mutable** (`test_property_currency_is_fillable_and_mutable_no_enforcement_exists`):
   - `Property.currency` is a fillable field with no immutability enforcement.
   - `test_cost_projection_blocks_when_property_base_currency_is_not_stable` proved that changing Property.currency after cost evidence exists changes the base-currency result of the projection.
   - No existing enforcement boundary prevents currency changes after controlled cost evidence exists.

2. **PurchaseOrderLine.unit_cost is mutable after receipt posting** (`test_post_receipt_unit_cost_mutation_produces_different_avco`):
   - `unit_cost` on `purchase_order_lines` can be changed via DB update after Goods Receipt posting, producing a different AVCO projection.
   - No database-level trigger, foreign key, or application-level immutability guard prevents mutation.

3. **PurchaseOrder.currency_code is mutable after receipt posting** (`test_cost_projection_blocks_when_post_receipt_purchase_order_commercial_evidence_is_mutable`):
   - `currency_code` on `purchase_orders` can be changed after receipt posting, altering cost eligibility.

4. **PurchaseOrder.exchange_rate is mutable after receipt posting** (`test_post_receipt_exchange_rate_mutation_produces_different_avco` and `test_fx_exchange_rate_is_mutable_after_receipt_posting`):
   - `exchange_rate` on `purchase_orders` can be changed after receipt posting, producing a different base-currency AVCO.
   - The rate direction convention (multiplication of unit_cost × exchange_rate for conversion to base currency) is implemented but the underlying column is mutable.

### Blockers to Activation

| Blocker | Source | Evidence |
|---------|--------|----------|
| Property base currency mutable | `Property.currency` fillable, no immutability guard | `test_property_currency_is_fillable_and_mutable_no_enforcement_exists` |
| PO unit_cost mutable after receipt | `purchase_order_lines.unit_cost` updatable | `test_post_receipt_unit_cost_mutation_produces_different_avco` |
| PO currency_code mutable after receipt | `purchase_orders.currency_code` updatable | `test_cost_projection_blocks_when_post_receipt_purchase_order_commercial_evidence_is_mutable` |
| PO exchange_rate mutable after receipt | `purchase_orders.exchange_rate` updatable | `test_post_receipt_exchange_rate_mutation_produces_different_avco` |

### Deferred Runtime Surface

The following runtime artifacts are deferred and inactive:
- Route `/operations/inventory/cost-control` — not registered
- `inventory.cost-control.view` permission — not seeded
- `InventoryCostControlWorkspaceController` — present but not routable
- `InventoryCostControlPolicy` — present but not enforced
- `InventoryCostControlWorkspace.tsx` — present but unreachable

The following artifacts are retained for future activation:
- `InventoryAvcoCostProjectionService` — algorithm complete and tested
- `InventoryCostEligibilityStatusEnum` — statuses defined
- `InventoryAvcoCostProjectionTest` — 40 tests, 120 assertions, all passing on pgsql/ivorq_testing

### Activation Prerequisites for Future Sprint

## Context
Sprints 36 through 38 established the controlled immutable inventory ledger based on `InventoryStockMovement` as the append-only source of truth. Goods Receipt, Transfer, Issue/Consumption, Stock Count, and Manual Adjustment movements are recorded with deterministic chronology and strict quantity protection. These movements are quantity-only; no cost, currency, or valuation data exists within `InventoryStockMovement`.

Operational users now require a read-only projection of AVCO (Average/Weighted Cost) to understand cost eligibility and derived cost evidence for inventory items. This projection must never be confused with financial valuation, general ledger posting, or authoritative cost records.

## Decision
Sprint 39 delivers a **read-only Inventory AVCO Cost Control Evidence projection**.
8. A repository-approved exact decimal primitive exists (`AvcoDecimal`, bcmath-backed, scale=4, in `Modules/Finance/CostControl`).
9. Movement chronology can be ordered deterministically using immutable server-generated evidence (`occurred_at ASC, created_at ASC, id ASC`).
10. The read-only projection operates without mutating Finance, GL, AP, Banking, Cash, Period, Business Date, or legacy inventory state.

### Projection Scope

1. Sprint 39 is a read-only Cost Control evidence projection.
2. Inventory domain owns the projection boundary.
3. Projection scope is property-wide and item-scoped.
4. It reads controlled immutable `InventoryStockMovement` evidence only.
5. It does not read or mutate legacy stock quantities or legacy weighted-average-cost fields.
6. It does not store cost in `InventoryStockMovement`.
7. It does not materialize or persist AVCO state.
8. It does not write InventoryItem, InventoryLocation, InventoryStock, Purchasing, Finance, GL, AP, Banking, Cashbook, Financial Period, Business Date, or Inventory Reversal state.

### Cost Eligibility Rules

9. Goods Receipt is cost-eligible only when linked to an immutable posted Goods Receipt line and immutable approved Purchase Order commercial evidence.
10. Transfer preserves property-item AVCO and produces no cost posting.
11. Issue / Consumption derives a read-only consumption cost only when the prior controlled chain remains cost-eligible.
12. Stock Count and Manual Adjustment block cost continuity when no separately authorized valuation source exists.

### Arithmetic and Currency Rules

13. Exact decimal arithmetic is mandatory; PHP float arithmetic is forbidden.
14. Currency conversion is permitted only when source-proven from immutable approved commercial evidence and property base-currency rules.
15. Unsupported or incomplete FX evidence must return a blocked status, not inferred cost.
16. No external FX lookup or manual FX input exists.
17. Deterministic chronology is mandatory.
18. No browser input controls cost, currency, exchange rate, chronology, projection output, property, or audit evidence.

### Workspace Rules

19. Cost Control is read-only and follows ADR-040 operational workspace standards.

### Explicit Non-Goals

The following are explicitly out of scope for Sprint 39:
- Enterprise inventory valuation
- Financial inventory valuation
- General-ledger valuation
- Cost ledger persistence
- AP subledger posting
- Inventory value book of record
- Cost posting engine
- Cost adjustment engine
- Cost write-back to any table
- Cost layer persistence
- Inventory value persistence
- General Ledger journal entries
- Accounts Payable invoice posting
- Supplier invoice integration
- Payment proposal or execution
- Cashbook mutation
- Banking mutation
- Financial Period mutation
- Business Date mutation
- Inventory Reversal modification
- Any reversal workflow
- Queue, job, worker, broker, or event bus infrastructure

### Cost Eligibility Statuses

The projection returns one of the following statuses:

- **COSTING_READY**: All relevant controlled movements in the chain are cost-eligible. All receipt commercial evidence is complete. Base-currency or supported source conversion is exact and source-proven.
- **COSTING_BLOCKED_FX_UNSUPPORTED**: A cost-eligible receipt is not in property base currency and no immutable source-proven exchange-rate or conversion rule exists.
- **COSTING_BLOCKED_UNVALUED_MOVEMENT**: A Stock Count variance or Manual Adjustment exists without a separately approved valuation source.
- **COSTING_BLOCKED_INSUFFICIENT_COST_EVIDENCE**: A Goods Receipt source has missing, zero, negative, non-canonical, or non-immutable commercial evidence. An outbound Issue cannot be valued because prior controlled cost quantity or cost evidence is insufficient.
- **COSTING_BLOCKED_INCONSISTENT_MOVEMENT_EVIDENCE**: A transfer pair is incomplete, mismatched, cross-property, cross-item, cross-quantity, or otherwise inconsistent. A controlled movement source cannot be resolved safely. A chronology invariant cannot be established.

### Projection Response Shape

The projection returns the following evidence fields:
- `property_id`
- `inventory_item_id`
- `controlled_ledger_quantity`
- `costed_controlled_quantity`
- `derived_avco_unit_cost` (null when blocked)
- `derived_controlled_cost_value` (null when blocked)
- `base_currency_code`
- `eligibility_status`
- `blocking_reason`
- `blocking_movement_id`
- `last_cost_eligible_movement_id`
- `last_cost_eligible_at`
- `consumption_cost_evidence`
- `projection_as_of`

### Deterministic Movement Processing

Movements are processed in this exact order:
1. `occurred_at` ascending
2. `created_at` ascending
3. `id` ascending (ULID, lexicographic = chronological)

#### Algorithm per Movement Type

**GOODS_RECEIPT / IN:**
1. Resolve immutable posted GoodsReceiptLine via `source_type = 'GoodsReceiptLine', source_id`.
2. Resolve exact linked approved PurchaseOrderLine via `purchase_order_line_id`.
3. Resolve source unit cost from `PurchaseOrderLine.unit_cost`.
4. Resolve source currency from `PurchaseOrder.currency_code`.
5. If source currency matches property base currency: receipt value = received quantity × base-currency unit cost.
6. If source currency differs from property base currency AND source-proven exchange rate exists: convert using exact bcmath arithmetic.
7. If source currency differs AND no source-proven exchange rate: return `COSTING_BLOCKED_FX_UNSUPPORTED`.
8. If any source evidence is missing/zero/negative/invalid: return `COSTING_BLOCKED_INSUFFICIENT_COST_EVIDENCE`.
9. Costed quantity += received quantity. Derived controlled value += receipt value. Derived AVCO = value ÷ quantity.

**ISSUE_CONSUMPTION / OUT:**
1. Require `COSTING_READY` immediately before the issue.
2. Require costed quantity >= issue quantity.
3. Issue cost evidence = issue quantity × current derived AVCO.
4. Costed quantity -= issue quantity. Derived controlled value -= issue cost evidence.
5. AVCO remains derived from exact remaining quantity/value.

**TRANSFER_OUT + TRANSFER_IN:**
1. Pair through correlation_id / server-owned transfer source identity.
2. When exactly one valid OUTBOUND and one valid INBOUND leg exist: costed quantity and derived value unchanged. AVCO unchanged.
3. When pairing is incomplete or inconsistent: return `COSTING_BLOCKED_INCONSISTENT_MOVEMENT_EVIDENCE`.

**COUNT_VARIANCE_IN / COUNT_VARIANCE_OUT / MANUAL_ADJUSTMENT_IN / MANUAL_ADJUSTMENT_OUT:**
1. Return `COSTING_BLOCKED_UNVALUED_MOVEMENT`.
2. Expose blocking movement identity, movement type, and last valid cost-eligible position.
3. Never fabricate cost, apply current AVCO, or use zero cost.

### Workspace Terminology

The Cost Control workspace must use these terms:
- Controlled AVCO Evidence
- Controlled Derived Cost
- Cost Eligibility
- Costing Blocked
- Controlled Ledger Quantity

It must never use:
- Financial Inventory Value
- Posted Inventory Valuation
- GL Inventory Balance
- Final Stock Value
- Authoritative Financial Cost

## Consequences
* **Positive Consequences:** Delivers a source-proven, auditable, read-only cost evidence projection without persisting any cost state. Maintains strict separation from financial valuation. Provides operations teams with cost visibility without bypassing financial controls.
* **Negative Consequences:** The projection is computed on every request (no cache). For items with extensive movement histories, this may impact response time. FX support is limited to source-proven exchange rates only.
* **Tradeoffs:** Choosing in-memory computation over persisted materialized views prioritizes correctness and auditability over query performance. This is acceptable for the operational Cost Control workspace where point-in-time queries are the primary use case.

## Future Expansion
Future sprints may introduce:
- Cost Ledger persistence (ADR-042)
- Cost Ledger outbox delivery (ADR-041)
- FX rate evidence from dedicated FX module
- Period-end AVCO snapshot for close purposes
- Authorized valuation overrides for count variance and adjustment
