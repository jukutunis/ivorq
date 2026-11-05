# ADR-091: Controlled Goods Receipt Pre-Posting Exception, Inspection, Correction, and Void Boundary

## ADR Metadata

* **ADR Number:** ADR-091
* **ADR Title:** Controlled Goods Receipt Pre-Posting Exception, Inspection, Correction, and Void Boundary
* **Date:** 2026-11-05
* **Status:** Approved
* **Related ADRs:** ADR-079, ADR-080, ADR-081, ADR-082, ADR-083
* **Architecture owner:** Operations / Inventory
* **Affected boundaries:** Inventory Goods Receipt, Procurement Purchase Order intent, Finance receipt evidence

## Context

Controlled `GoodsReceipt` uses `GoodsReceiptLine` and `InventoryStockMovement` as its quantity universe. The existing Inventory reversal runtime instead governs `InventoryTransaction` with CostControl, Business Date, Financial Period, valuation-sequence, and Cost Ledger evidence. Those universes must not be bridged by dual-write or fabricated shadow transactions.

Operational receiving still requires auditable shortage, overage, damage, wrong-item, expiry, quality, inspection, supplier-rejection, accepted-quantity correction, rejected-line, and void evidence before posting.

## Decision

1. Controlled receipt exception, inspection, correction, rejection, and void activity occurs only before controlled posting.
2. `GoodsReceipt` and `GoodsReceiptLine` remain Inventory-owned. Procurement supplies Purchase Order, vendor, and commercial intent. Finance consumes only accepted `POSTED` receipt-line evidence.
3. Posted `GoodsReceipt` and `GoodsReceiptLine` remain immutable. No posted receipt reversal or posted edit is authorized.
4. Exception evidence is a distinct controlled aggregate linked to Property, Goods Receipt, Goods Receipt line, Purchase Order, and Purchase Order line provenance. It never attaches to legacy `ReceivingDocument`, `ReceivingLine`, `ReceivingDiscrepancy`, or `ReceivingInspection` facts.
5. Canonical discrepancy and inspection vocabulary is reused from `DiscrepancyTypeEnum` and `InspectionResultEnum` only.
6. Exception evidence has a small `OPEN` to `RESOLVED` lifecycle and stable idempotency fingerprint. Identical replay returns the original evidence; conflicting replay fails closed.
7. Material resolution uses maker/checker separation. Resolution may accept as-is, reduce accepted Draft quantity, reject a line, or void the non-posted receipt.
8. A rejected line remains visible and auditable with `posting_disposition = REJECTED`. It creates no `InventoryStockMovement`, commercial evidence, Finance invoice capacity, GRNI evidence, or AP evidence.
9. At least one accepted line and no open exception are required for confirmation or posting. Only accepted lines mutate Purchase Order received quantity and create posting evidence.
10. A controlled receipt may become terminal `VOIDED` before posting. Void actor, reason, and time are durable. A voided receipt creates no movement, Finance fact, or CostControl fact and cannot submit, post, or return to a posting state.
11. No `ReceivingDocument` dual-write, `InventoryTransaction` shadow-write, Inventory ledger convergence, or CostControl write is permitted.
12. Business Date and Financial Period are not required for pre-post exception evidence because no inventory or accounting posting occurs.
13. Source authority remains `inventory_goods_receipt`; legacy or mixed authority fails closed for every exception, correction, resolution, void, confirmation, and posting action.

## Deferred Boundaries

* `POSTED_GOODS_RECEIPT_REVERSAL = DEFERRED_BY_ADR`
* `SUPPLIER_RETURN_AFTER_POSTING = DEFERRED_BY_ADR`
* `CONTROLLED_STOCK_MOVEMENT_REVERSAL = ARCHITECTURE_DECISION_REQUIRED`

The current `InventoryReversal*` services are not applicable to the `InventoryStockMovement`-only Goods Receipt universe. A future decision must define posted quantity reversal, commercial reversal, valuation, CostControl, GRNI/AP, supplier return, and credit/debit-note consequences together.

## Consequences

Pre-post physical observations remain auditable without weakening Purchase Order capacity, posted immutability, source authority, or Finance evidence. Rejected and voided observations remain operational evidence only. Supplier returns and posted corrections intentionally remain unavailable until a separate architecture is accepted.
