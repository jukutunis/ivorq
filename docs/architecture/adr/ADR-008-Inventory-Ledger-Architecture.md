# ADR 008: Inventory Ledger Architecture

## 1. Title
ADR-008: Inventory Ledger Architecture

## 2. Status
Proposed

## 3. Context
Following the stabilization of the Purchasing, Receiving, Accounts Payable, and General Ledger domains, IVORQ requires a dedicated subledger for Inventory. Currently, stock quantities and Average Cost (AVCO) valuations are calculated amorphously within operational services (like `ReceiptService`) without a persistent historical ledger to capture the temporal progression of stock levels. As IVORQ scales toward an enterprise PMS and ERP ecosystem capable of complex hospitality operations (e.g., Recipe Engines, Banquet allocations), a robust, immutable Inventory Ledger is mandatory.

## 4. Problem Statement
The absence of a centralized, immutable Inventory Ledger exposes the system to historical data loss, untraceable stock variances, and uncontrolled AVCO drift. Without a strict ledger, it is impossible to perform accurate month-end financial closings, reconcile Goods Received Not Invoiced (GRNI), or reliably track hospitality-specific stock movements (e.g., spoilage, inter-kitchen transfers, F&B production) across multiple properties and storerooms.

## 5. Decision
We will implement an immutable, event-sourced Inventory Ledger acting as the single source of truth for all quantitative stock movements. The ledger will utilize a double-entry-inspired posting model for transfers and rigorous contra-entry mechanisms for reversals. Stock balances will be derived from this ledger, with materialized views or snapshot tables utilized for high-performance reads.

## 6. Architecture Principles
1. **Source of Truth:** The Inventory Ledger is the absolute source of truth for stock quantities.
2. **Immutability:** Posted ledger entries are strictly read-only. `UPDATE` and `DELETE` operations are architecturally prohibited.
3. **Reversal Strategy:** Errors are corrected exclusively via contra-entries (reversals) referencing the original transaction.
4. **Multi-Property & Multi-Location:** Every transaction mandates a `property_id` and a `location_id` (store/warehouse).
5. **Hospitality Operations:** Movement types must support F&B production, breakage, spoilage, and event allocations.
6. **Auditability:** Total traceability to users, timestamps, and source operational documents.
7. **Month-End Closing Compatibility:** Transactions must respect financial period locks.
8. **Valuation Foundation:** Initially built for AVCO, but structurally decoupled enough to support FIFO layering in the future.
9. **Decoupled Ledgers:** The Inventory Ledger tracks *quantities*. A parallel, linked Cost Ledger will track *financial valuations*.

## 7. Inventory Ledger Design
The architecture separates the operational tracking from the read-performance model:
- **Ledger Entries (Immutable):** Granular, append-only records of every stock delta (`+` or `-`).
- **Stock Balances (Materialized Snapshot):** A mutable projection table (e.g., `inventory_balances`) that maintains the current `quantity_on_hand` and `current_avco` per SKU per Location. If this table corrupts, it can be 100% reconstructed by summing the Ledger Entries.

## 8. Movement Model
To accommodate the physical realities of hospitality, the following movement types are approved:
- `RECEIPT` (Incoming from Vendor)
- `RETURN_TO_VENDOR` (Outgoing to Vendor)
- `ISSUE` (Outgoing to Department/Requisition)
- `RETURN_FROM_DEPARTMENT` (Incoming from Department)
- `TRANSFER_IN` / `TRANSFER_OUT` (Inter-location movements)
- `ADJUSTMENT_IN` / `ADJUSTMENT_OUT` (General quantity corrections)
- `STOCK_COUNT_GAIN` / `STOCK_COUNT_LOSS` (Physical audit variances)
- `SPOILAGE` / `BREAKAGE` (Hospitality-specific operational write-offs)
- `PRODUCTION_IN` / `PRODUCTION_OUT` (Future Recipe Engine: Raw materials out, finished goods in)

## 9. Posting Model
Entries must contain:
- `property_id` and `location_id`
- `product_id` (SKU)
- `movement_type`
- `quantity` (Signed float/decimal: positive for IN, negative for OUT)
- `reference_type` and `reference_id` (Polymorphic link to PO, GRN, Requisition, etc.)
- `transaction_date` (Operational date)
- `posting_date` (System timestamp)

## 10. Audit Requirements
- Strict enforcement of `created_by` linking to the exact user ULID.
- Reason codes required for all manual adjustments, spoilages, and count variances.
- Digital footprint capture (linking to Shift or Reconciliation Session if applicable).

## 11. Reversal Strategy
To reverse a transaction (e.g., an erroneous Receipt):
1. Create a new ledger entry with the exact inverse `quantity` of the original.
2. Mark the `movement_type` as a reversal (e.g., `RECEIPT_REVERSAL` or using a `is_reversal` boolean flag).
3. Populate a `reversed_ledger_entry_id` pointing to the original record.
4. The system calculates the new balances moving forward.

## 12. Transaction Dating Strategy
- **`transaction_date`:** The date the physical movement occurred (can be backdated).
- **`posting_date`:** The exact system timestamp the ledger entry was written (`now()`).
- **Backdating Rules:** A transaction can be backdated *only* into an open GL/Inventory financial period. If a backdated transaction alters historical AVCO, the system will apply the current AVCO at the time of posting to prevent cascading retrospective recalculations that would invalidate already-issued closed period financial reports.

## 13. Integration Boundaries
- **Purchasing/Receiving:** Writing to the Inventory Ledger occurs precisely when a Goods Receipt is finalized.
- **Cost Ledger:** Every Inventory Ledger entry will emit a domain event (`InventoryMoved`). The Cost Ledger listens to this event to record the financial impact (Debits/Credits to inventory asset values).
- **General Ledger:** The Cost Ledger (not the Inventory Ledger) will interface with the General Ledger to post subledger journals.

## 14. Future Compatibility
- **Recipe Engine:** `PRODUCTION_IN` and `PRODUCTION_OUT` types exist specifically to support kitchen batching and yielding.
- **FIFO Support:** Because the Inventory Ledger records discrete events, a future FIFO engine can group `RECEIPT` quantities into "cost layers" and deplete them sequentially during `ISSUE` events.
- **Consignment/Retail:** Locations can be flagged as `Consignment` or `Retail Store`, bridging the gap to POS depletions via standard `ISSUE` or `SALES_DEPLETION` movements.

## 15. Advantages
- Absolute data integrity and auditability.
- Clear separation of concerns (Quantities vs. Valuations).
- Extensible to any future PMS/POS ecosystem requirement.
- High read performance via snapshot balances, with 100% reconstructive safety.

## 16. Trade-offs
- Storage volume will grow rapidly due to the append-only nature of the ledger.
- Calculating complex point-in-time historical balances requires scanning and summing large swaths of rows.

## 17. Risks
- **Concurrency:** High-volume concurrent transactions on the same SKU/Location could lead to race conditions when updating the materialized balance snapshot.
- **Backdating AVCO:** Allowing backdated physical transactions creates a divergence between chronological inventory value and financial posted value.

## 18. Governance Rules
1. No direct database updates or deletes on ledger tables.
2. Every ledger posting must execute within a strict database transaction encompassing the balance snapshot update.
3. Cost valuation logic must remain structurally isolated from quantity tracking.

## 19. Consequences
- Development effort is increased due to the requirement of building contra-entry reversal flows instead of simple CRUD edits.
- Substantial automated testing will be required to guarantee balance snapshot integrity under heavy concurrency.
- A subsequent ADR (ADR-009) is mandatory to define the Cost Ledger architecture that pairs with this Inventory Ledger.
