# Inventory Concurrency Audit

## Scope
Review of Inventory Stock Movement services for concurrency, race conditions, deadlocks, and data integrity.

- `StockMovementService`
- `ReceiptService`
- `IssueService`
- `TransferService`
- `AdjustmentService`

## Findings

### 1. StockMovementService
- **DB Transaction**: Expects to be called inside an active `DB::transaction()`.
- **Locking**: Uses `findOrCreateLocked()` to issue an upsert and lock the row, mitigating TOCTOU (Time-of-Check to Time-of-Use) race conditions (annotated as `C-02 fix`).
- **Integrity**: Correctly appends to `InventoryStockCard` (append-only ledger) ensuring stock card integrity. Recalculates stock statuses based on new balance cleanly.

### 2. ReceiptService
- **DB Transaction**: `post()` wraps the entire operation in a `DB::transaction()`.
- **WAC Calculation & Locking**: Aggregates all lines per item *before* touching balances or WAC. It correctly uses `totalQuantityForItemLocked($itemId)` (annotated as `C-01 + C-03 fix`) to lock all balance rows for an item so the SUM is consistent, preventing concurrent writes from altering the total during WAC calculation.
- **Race Condition Risk**: Effectively mitigated by the upfront aggregation and locking.

### 3. IssueService & TransferService
- **DB Transaction**: Both `post()` and `complete()` wrap the item iterations in `DB::transaction()`.
- **Integrity**: Relies on `StockMovementService`'s internal locking for individual balance updates. Stock outs correctly stamp negative changes with corresponding cost logic.

### 4. AdjustmentService
- **Staleness & Locking**: The `approve()` method wraps the logic in `DB::transaction()`. Crucially, it locks the balance row explicitly using `lockForUpdate()` and checks the `currentQty` against `quantity_system` to prevent processing stale adjustments (BR-065).
- **Deadlock Risk**: Lowest possible because rows are locked sequentially per adjustment line. However, if multiple adjustments touch the same items in different orders concurrently, there's a theoretical deadlock risk. Standard DB deadlock detection will rollback one transaction.

## Conclusion
The concurrency mechanisms are highly robust. The usage of explicit pessimistic locking (`lockForUpdate`, `findOrCreateLocked`, `totalQuantityForItemLocked`) inside DB transactions successfully guards against race conditions, ensuring Stock Balance and Stock Card integrity. WAC recalculation is protected by aggregate locking.

**Status**: Ready & Hardened.
