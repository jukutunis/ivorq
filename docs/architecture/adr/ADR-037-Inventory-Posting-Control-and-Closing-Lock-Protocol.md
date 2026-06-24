# ADR-037: Inventory Posting Control and Closing Lock Protocol

**Status:** Draft — Owner Review Required
**Date:** 2026-06-22

## Context and Current Repository Evidence

The `PostingPeriodGuard` (`Modules/Finance/GeneralLedger/Services/PostingPeriodGuard.php`, line 32) successfully enforces zero-argument fail-closed validation, strictly resolving temporal contexts through `CurrentPropertyService` and `CurrentBusinessDateService`.
However, during period closure (`Modules/Finance/GeneralLedger/Services/PeriodControlService.php`, line 63), the target `FinancialPeriod` row is queried (`firstOrFail()`) without acquiring a database lock (`lockForUpdate()`).
Because `PostingPeriodGuard` performs pure reads, and `PeriodControlService` closes the period without acquiring a row-level lock, standard `READ COMMITTED` isolation allows race conditions where an inventory transaction might validate against an "Open" state in memory, only for the period to be concurrently closed before the inventory transaction commits.

## Decision

### D-INV-05
Future posting will use a shared posting-versus-closing consistency protocol enforced tightly within a single database transaction.

**Posting Transaction Sequence:**
1. Resolve trusted current Property.
2. Resolve active current Property Business Date.
3. Lock current `PropertyBusinessDate` row (`lockForUpdate()`).
4. Lock matching `FinancialPeriod` row (`lockForUpdate()`).
5. Revalidate that both control rows are Open.
6. Lock `InventoryStock` rows in deterministic item/location order.
7. Enforce ledger idempotency.
8. Append immutable `InventoryTransaction`.
9. Update `InventoryStock` projection.
10. Commit.

Future Financial Period closing and future Business Date closing must use compatible control-row locks.

A standalone Business Date close locks only PropertyBusinessDate. FinancialPeriod and InventoryStock are acquired only when the same approved transaction needs those resources, always preserving PropertyBusinessDate → FinancialPeriod → InventoryStock where multiple resources participate.

`PostingPeriodGuard` remains:
- zero-argument;
- fail-closed;
- pure read-only;
- not responsible for row locking;
- not responsible for transaction ownership.

## Rationale

### Why a Pure Read Guard is Insufficient
A pure read-only PostingPeriodGuard call inside a transaction is still
insufficient by itself.

The future coordinator must lock the current control rows and revalidate
Business Date and Financial Period status after locks are acquired.

### Shared Lock Protocol and Deterministic Lock Ordering
Resolve Property context first. This is context resolution, not a row lock.

Then acquire row locks in this order:
1. current PropertyBusinessDate
2. matching FinancialPeriod
3. InventoryStock rows sorted deterministically by item_id and location_id

## Separation Between Current Implementation and Future Work

**Current Evidence Supported:**
- Zero-argument `PostingPeriodGuard` and context resolution via `CurrentBusinessDateService`.
- Lock-for-update on stock (`InventoryStockRepository::findOrCreateLocked`).

**Required Future Work:**
- `PeriodControlService::close` must be refactored to use `lockForUpdate()`.
- Future locking and revalidation belong to a new transaction-level
  Inventory Posting Control Coordinator or equivalent future coordinator.
- A future `BusinessDateCloseService` must follow the identical lock protocol.

## PostgreSQL Validation Requirements
Extensive PostgreSQL-only concurrency tests must be written to explicitly trigger race conditions between closing events and inventory posting, asserting that the locks correctly reject out-of-bounds postings or serialize valid ones.

## Deadlock and Retry Principles
Deadlock retry may occur only for a posting request carrying a deterministic
idempotency_key.

Every retry must restart the full transaction and lock protocol.

Blind retries without idempotency identity are prohibited.

## Explicit Non-Goals
- We are **not** rewriting the entire Night Audit process.
- We are **not** implementing the full Financial Period closing logic beyond the lock modification in `PeriodControlService`.
