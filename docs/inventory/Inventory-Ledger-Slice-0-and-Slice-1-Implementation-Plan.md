# Inventory Ledger Slice 0 and Slice 1 Implementation Plan

**Status:** Draft — Owner Review Required
**Date:** 2026-06-22

## Executive Summary
This document outlines the phased implementation strategy for the canonical immutable Inventory Ledger, adhering to decisions D-INV-01 through D-INV-06 (ADR-035, ADR-036, ADR-037).

## Slice 0 — Control and Schema Readiness

Slice 0 establishes the database constraints, idempotency guarantees, and concurrency safety protocols required before any operational code is permitted to write to the ledger.

- No operational pilot cutover occurs in Slice 0.
- No existing Receipt, Issue, Transfer, Adjustment, Stock Opname, or Receiving
  posting behavior is migrated in Slice 0.

### Exact Schema Additions Required
Modify `inventory_transactions` via migration to add:
- `business_date` (date, nullable for legacy compatibility)
- `occurred_at` (timestamp, nullable for legacy)
- `source_document_type` (string, nullable)
- `source_document_id` (ulid, nullable)
- `source_line_type` (string, nullable)
- `source_line_id` (ulid, nullable)
- `movement_role` (string, nullable)
- `idempotency_key` (string, nullable)

### PostgreSQL Immutability and Unique Constraint Strategy
- Introduce: `UNIQUE(property_id, idempotency_key)`
- Existing trigger `trg_block_inventory_transaction_update/delete` remains active.

### Legacy-Row Compatibility and Safe Migration Strategy
- Make new columns nullable initially to ensure backward compatibility for historical rows.
- Legacy temporal and identity fields remain null for historical rows.
- Future controlled entries must later require non-null temporal and identity
  fields.
- PostgreSQL partial unique idempotency enforcement is required for non-null
  keys.

### Control-Row Locking Prerequisites (Finance/Foundation)
- Refactor `Modules/Finance/GeneralLedger/Services/PeriodControlService.php` `close()` method to acquire `lockForUpdate()` on the `FinancialPeriod`.
- Introduce locking mechanism for `PropertyBusinessDate`.
- Shared posting-versus-closing control-row protocol is a cross-domain
  prerequisite involving Inventory, Finance, and Foundation.
- PostingPeriodGuard remains pure read-only; a future coordinator owns
  transaction locks and revalidation.

### Test Plan
- Write explicit PostgreSQL-only tests asserting that concurrent processes attempting to insert identical `idempotency_key` payloads fail cleanly.
- Assert that race conditions between `PeriodControlService::close` and `StockMovementService` are prevented via row-level locks.

### Explicit Exit Criteria
- Slice 0 is complete when the migration runs successfully, schema constraints are active, `PeriodControlService` locking is merged, and all PostgreSQL concurrency tests pass in isolation.

---

## Slice 1 — Controlled Inventory Ledger Posting Pilot

Slice 1 activates the schema from Slice 0 strictly for a single, isolated operational flow to validate idempotency and accuracy without disrupting the entire module.

### Use One Limited Pilot Flow First
Pilot A — InventoryReceipt / InventoryReceiptLine internal posting only.

### Line-Level Identity & Idempotency
- source_line_type = InventoryReceiptLine
- source_line_id = InventoryReceiptLine primary key
- source_document_type = InventoryReceipt
- source_document_id = InventoryReceipt primary key
- movement_role = RECEIPT
- sequence = 1 unless a documented future multi-movement case exists
- trusted business_date
- trusted occurred_at
- deterministic idempotency_key

### Immutable Ledger Append & Stock Projection Update
- same-key/same-intent idempotent replay behavior
- same-key/different-intent collision failure
- immutable append before projection update

### Controlled Rollback Behavior
- PostgreSQL-only concurrency and retry validation

### Control Enforcement
- Real-time enforcement of the `PostingPeriodGuard` inside the execution transaction lock.

### PostgreSQL Concurrency and Retry Tests
- Validate that two rapid requests to `post()` the same `InventoryReceipt` strictly yield only one set of physical ledger movements.

### Explicit Exit Criteria and Containment Plan
- Slice 1 is complete when Receipt processing succeeds end-to-end, safely projecting physical balances and preventing duplicate inserts on retry.
Same-intent retry returns or recognizes the existing ledger entry without
creating another ledger append or another InventoryStock update.

Containment does not alter committed immutable ledger rows.

On a pilot failure, disable or pause the pilot entry path, preserve committed
ledger history, quarantine unresolved source lines, investigate under audit,
and resume only through governed idempotent replay using the same key.

The pilot must never silently fall back to a legacy posting path for the same
source line.

---

## Future Expansion — Not Authorized

The following items are explicitly **excluded** from Slice 0 and Slice 1:
- InventoryReceiptIntegrationService changes
- ReceivingDocumentLine-to-InventoryReceiptLine integration
- Receiving-to-Inventory cutover
- Issue
- Transfer
- Adjustment
- Stock Opname
- reversal API
- correction API
- Cost Ledger
- GL posting
- GRNI
- AP
- PPV
- landed cost
- tax allocation
- Cost Control
- UI redesign
