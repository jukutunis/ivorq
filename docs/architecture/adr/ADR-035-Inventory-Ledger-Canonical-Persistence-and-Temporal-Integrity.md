# ADR-035: Inventory Ledger Canonical Persistence and Temporal Integrity

**Status:** Draft — Owner Review Required
**Date:** 2026-06-22

## Context and Current Repository Evidence

The current IVORQ repository tracks inventory movements in the `inventory_transactions` table (`Modules/Operations/Inventory/database/migrations/2026_06_05_000046_create_inventory_transactions_table.php`).
Evidence from `2026_06_14_194423_alter_inventory_transactions_add_immutable_triggers.php` proves that PostgreSQL triggers already strictly block `UPDATE` and `DELETE` operations on this table, providing a foundation for immutable ledger semantics.
Additionally, `InventoryStock` is updated mutably via `InventoryStockRepository::updateBalance()` (Lines 68-71) to reflect current physical quantities.
Currently, `inventory_transactions` tracks `quantity_before`, `quantity_change`, `quantity_after`, `unit_cost`, and `total_cost`, but lacks explicit temporal controls for the business operation itself (`business_date` and `occurred_at`).

## Decision

### D-INV-01
We will **promote `inventory_transactions` as the physical persistence table for the canonical Inventory Ledger.**
We will not introduce a second `inventory_ledgers` table. `InventoryStock` remains a mutable current-balance projection strictly updated alongside ledger appends.

### D-INV-02
For every new Inventory Ledger entry, we will introduce immutable temporal fields:
- `business_date`
- `occurred_at`
- `created_at` (retained as recorded/system persistence time)

`business_date` and `occurred_at` are trusted server-side values resolved at the moment of ledger append. They are never arbitrary caller inputs and cannot be changed after posting.

## Rationale

### Why promote `inventory_transactions` rather than creating `inventory_ledgers`?
`inventory_transactions` already possesses the correct core schema (`property_id`, `item_id`, `location_id`, `transaction_type`, quantities) and benefits from active PostgreSQL immutability triggers. Introducing a separate `inventory_ledgers` table would duplicate persistence logic and require complex bidirectional synchronization without providing additional structural guarantees.

### InventoryStock Projection Boundary
`InventoryStock` strictly serves as a materialized read-model for current on-hand balances. It must never be mutated outside the boundaries of a corresponding `inventory_transactions` ledger append.

### Immutable Temporal Model
By introducing `business_date` and `occurred_at`, the ledger strictly separates the operational reality (when the event happened) from the system persistence reality (`created_at`). This guarantees that reporting can accurately reflect inventory positions for a closed Business Date, regardless of system latency or retry mechanisms.

### Legacy Historical-Row Compatibility Principles
Historical ledger rows must not receive fabricated business_date or occurred_at
values.

Existing historical rows remain legacy records with null temporal provenance
until a separately approved evidence-based provenance remediation decision exists.

Historical reporting must distinguish legacy rows with unknown temporal
provenance from controlled ledger rows created after the new temporal model.

New controlled ledger entries must always persist business_date and occurred_at.
Legacy compatibility never permits newly posted controlled entries to use null
business_date or null occurred_at.

## Explicit Non-Goals
- We are **not** implementing the Cost Ledger.
- We are **not** migrating to a new table structure.

## Consequences and Migration Implications
- **Schema Updates:** Requires a new migration to add `business_date` and `occurred_at` to `inventory_transactions`.
- **Service Updates:** `StockMovementService` must explicitly resolve the current Business Date inside its transaction boundary.
