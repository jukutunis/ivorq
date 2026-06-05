# Inventory Module — Database Design

## Overview

10 tables. All use ULID primary keys (char 26), soft deletes, and audit columns (`created_by`, `updated_by`) consistent with all other Operations modules. All tables have `property_id` with `restrictOnDelete()`.

The single exception is `inventory_stock_cards` which is immutable: no `updated_by`, no soft deletes, no `updated_at`.

---

## Tables

### 1. `inventory_categories`

Groups items by operational domain.

```
inventory_categories
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── category_code   varchar(20)     — CAT-00001, unique per property
├── name            varchar(100)
├── description     text nullable
├── is_active       boolean default true
├── created_by      char(26) nullable FK → users.id
├── updated_by      char(26) nullable FK → users.id
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Indexes:
  UNIQUE (property_id, category_code)
  INDEX  (property_id, is_active)
```

---

### 2. `inventory_units`

Units of measure.

```
inventory_units
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── unit_code       varchar(20)     — UNT-00001, unique per property
├── abbreviation    varchar(20)     — PCS, KG, L
├── name            varchar(100)    — Pieces, Kilogram, Litre
├── is_active       boolean default true
├── created_by      char(26) nullable
├── updated_by      char(26) nullable
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Indexes:
  UNIQUE (property_id, unit_code)
  INDEX  (property_id, is_active)
```

---

### 3. `inventory_items`

Item master. The central reference for all stock movements.

```
inventory_items
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── item_code       varchar(20)     — ITM-00001, unique per property
├── name            varchar(255)
├── description     text nullable
├── category_id     char(26) FK → inventory_categories.id (restrictOnDelete)
├── unit_id         char(26) FK → inventory_units.id (restrictOnDelete)
├── sku             varchar(100) nullable   — unique per property if set
├── barcode         varchar(100) nullable
├── min_stock       decimal(10,3) default 0     — minimum safe level
├── max_stock       decimal(10,3) nullable       — optional storage cap
├── reorder_point   decimal(10,3) default 0     — trigger reorder when balance drops below
├── reorder_quantity decimal(10,3) default 0    — suggested order qty
├── is_active       boolean default true
├── notes           text nullable
├── created_by      char(26) nullable
├── updated_by      char(26) nullable
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Indexes:
  UNIQUE (property_id, item_code)
  UNIQUE (property_id, sku) — partial, nullable excluded
  INDEX  (property_id, category_id)
  INDEX  (property_id, unit_id)
  INDEX  (property_id, is_active)
  INDEX  (property_id, name)

Constraints:
  CHECK (min_stock >= 0)
  CHECK (reorder_point >= 0)
  CHECK (reorder_quantity >= 0)
  CHECK (max_stock IS NULL OR max_stock >= 0)
```

Note: `min_stock <= reorder_point` is enforced at the service/request layer, not as a DB constraint, consistent with the project's existing approach (no complex CHECK constraints).

---

### 4. `inventory_locations`

Physical storage locations within a property.

```
inventory_locations
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── location_code   varchar(20)     — LOC-00001, unique per property
├── name            varchar(100)
├── description     text nullable
├── location_type   varchar(30)     — LocationTypeEnum value
├── is_active       boolean default true
├── created_by      char(26) nullable
├── updated_by      char(26) nullable
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Indexes:
  UNIQUE (property_id, location_code)
  INDEX  (property_id, location_type)
  INDEX  (property_id, is_active)
```

---

### 5. `inventory_stock_balances`

Current on-hand quantity per item per location. Denormalized for query performance.

```
inventory_stock_balances
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── item_id         char(26) FK → inventory_items.id (restrictOnDelete)
├── location_id     char(26) FK → inventory_locations.id (restrictOnDelete)
├── quantity        decimal(10,3) default 0
├── status          varchar(30) default 'out_of_stock'  — StockBalanceStatusEnum
├── last_movement_at datetime nullable
├── created_at      timestamp nullable
└── updated_at      timestamp nullable

Note: No deleted_at — balance records are never deleted.
Note: No created_by/updated_by — updated programmatically by StockMovementService only.

Indexes:
  UNIQUE (property_id, item_id, location_id)
  INDEX  (property_id, status)
  INDEX  (property_id, item_id)
  INDEX  (property_id, location_id)
```

---

### 6. `inventory_stock_cards`

Immutable inventory ledger. One entry per stock event.

```
inventory_stock_cards
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── item_id         char(26) FK → inventory_items.id (restrictOnDelete)
├── location_id     char(26) FK → inventory_locations.id (restrictOnDelete)
├── movement_type   varchar(30)     — StockMovementTypeEnum value
├── quantity_before decimal(10,3)   — balance before this movement
├── quantity_change decimal(10,3)   — positive = in, negative = out
├── quantity_after  decimal(10,3)   — balance after this movement
├── reference_type  varchar(50) nullable   — 'transfer', 'adjustment', 'receipt', 'issue', 'return'
├── reference_id    char(26) nullable      — ULID of the source document
├── remarks         text nullable
├── posted_by       char(26) FK → users.id (restrictOnDelete)
└── posted_at       datetime               — when this movement was posted

Note: created_at = posted_at effectively. No updated_at, no deleted_at, no updated_by.
The HasAuditColumns trait is NOT applied to this model.
SoftDeletes is NOT applied to this model.

Indexes:
  INDEX (property_id, item_id, posted_at)          — stock card history per item
  INDEX (property_id, location_id, posted_at)       — stock card history per location
  INDEX (property_id, item_id, location_id, posted_at) — combined for balance verification
  INDEX (property_id, movement_type, posted_at)     — reporting by type
  INDEX (property_id, reference_type, reference_id) — trace back to source document
  INDEX (property_id, posted_at)                    — recent activity feed
```

---

### 7. `inventory_transfers`

Transfer transaction header. Moves stock between locations.

```
inventory_transfers
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── transfer_number varchar(20)     — TRN-00001, unique per property
├── from_location_id char(26) FK → inventory_locations.id (restrictOnDelete)
├── to_location_id   char(26) FK → inventory_locations.id (restrictOnDelete)
├── status          varchar(30) default 'draft'   — TransferStatusEnum
├── notes           text nullable
├── requested_by    char(26) FK → users.id (restrictOnDelete)
├── approved_by     char(26) nullable FK → users.id
├── approved_at     datetime nullable
├── completed_by    char(26) nullable FK → users.id
├── completed_at    datetime nullable
├── cancelled_by    char(26) nullable FK → users.id
├── cancelled_at    datetime nullable
├── created_by      char(26) nullable
├── updated_by      char(26) nullable
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Constraint: from_location_id ≠ to_location_id (enforced at service layer)

Indexes:
  UNIQUE (property_id, transfer_number)
  INDEX  (property_id, status)
  INDEX  (property_id, from_location_id, status)
  INDEX  (property_id, to_location_id, status)
  INDEX  (property_id, requested_by)
  INDEX  (property_id, created_at)
```

---

### 8. `inventory_transfer_lines`

Line items for each transfer.

```
inventory_transfer_lines
├── id                 char(26) PK
├── property_id        char(26) FK → properties.id (restrictOnDelete)
├── transfer_id        char(26) FK → inventory_transfers.id (cascadeOnDelete)
├── item_id            char(26) FK → inventory_items.id (restrictOnDelete)
├── quantity_requested decimal(10,3)
├── notes              text nullable
├── created_at         timestamp nullable
└── updated_at         timestamp nullable

Note: No soft deletes. Lines deleted with header via cascade.
Note: No created_by/updated_by — managed as part of the transfer header.

Indexes:
  INDEX (transfer_id)
  INDEX (property_id, item_id)
```

---

### 9. `inventory_adjustments`

Adjustment transaction header. Corrects stock discrepancies.

```
inventory_adjustments
├── id               char(26) PK
├── property_id      char(26) FK → properties.id (restrictOnDelete)
├── adjustment_number varchar(20)   — ADJ-00001, unique per property
├── location_id       char(26) FK → inventory_locations.id (restrictOnDelete)
├── adjustment_type   varchar(30)   — AdjustmentTypeEnum
├── status            varchar(30) default 'draft'  — AdjustmentStatusEnum
├── reason            text          — required for all adjustments
├── submitted_by      char(26) nullable FK → users.id
├── submitted_at      datetime nullable
├── approved_by       char(26) nullable FK → users.id
├── approved_at       datetime nullable
├── rejected_by       char(26) nullable FK → users.id
├── rejected_at       datetime nullable
├── rejection_reason  text nullable
├── created_by        char(26) nullable
├── updated_by        char(26) nullable
├── created_at        timestamp nullable
├── updated_at        timestamp nullable
└── deleted_at        timestamp nullable

Indexes:
  UNIQUE (property_id, adjustment_number)
  INDEX  (property_id, status)
  INDEX  (property_id, adjustment_type)
  INDEX  (property_id, location_id, status)
  INDEX  (property_id, created_at)
```

---

### 10. `inventory_adjustment_lines`

Line items for each adjustment. Captures system vs actual quantities.

```
inventory_adjustment_lines
├── id                 char(26) PK
├── property_id        char(26) FK → properties.id (restrictOnDelete)
├── adjustment_id      char(26) FK → inventory_adjustments.id (cascadeOnDelete)
├── item_id            char(26) FK → inventory_items.id (restrictOnDelete)
├── quantity_system    decimal(10,3)  — snapshot of balance at draft creation
├── quantity_actual    decimal(10,3)  — entered by the user
├── quantity_variance  decimal(10,3)  — quantity_actual - quantity_system (stored, not computed)
├── notes              text nullable
├── created_at         timestamp nullable
└── updated_at         timestamp nullable

Indexes:
  INDEX (adjustment_id)
  INDEX (property_id, item_id)
```

---

## Entity Relationships

```
properties
└── inventory_categories (many)
└── inventory_units      (many)
└── inventory_items      (many)
    ├── FK → inventory_categories
    └── FK → inventory_units
└── inventory_locations  (many)
└── inventory_stock_balances (many)
    ├── FK → inventory_items
    └── FK → inventory_locations
└── inventory_stock_cards (many, immutable)
    ├── FK → inventory_items
    └── FK → inventory_locations
└── inventory_transfers  (many)
    ├── FK → inventory_locations (from)
    ├── FK → inventory_locations (to)
    └── inventory_transfer_lines (many)
        └── FK → inventory_items
└── inventory_adjustments (many)
    ├── FK → inventory_locations
    └── inventory_adjustment_lines (many)
        └── FK → inventory_items
```

---

## Migration Ordering

Migrations must run in dependency order:

1. `create_inventory_categories_table`
2. `create_inventory_units_table`
3. `create_inventory_locations_table`
4. `create_inventory_items_table` (depends on categories, units)
5. `create_inventory_stock_balances_table` (depends on items, locations)
6. `create_inventory_stock_cards_table` (depends on items, locations)
7. `create_inventory_transfers_table` (depends on locations)
8. `create_inventory_transfer_lines_table` (depends on transfers, items)
9. `create_inventory_adjustments_table` (depends on locations)
10. `create_inventory_adjustment_lines_table` (depends on adjustments, items)

Timestamp prefix example: `2026_06_05_000060` through `2026_06_05_000069`, continuing the existing migration numbering sequence.

---

## Enum Columns Summary

| Table | Column | Enum Class |
|---|---|---|
| inventory_locations | location_type | LocationTypeEnum |
| inventory_items | (status computed from balance) | — |
| inventory_stock_balances | status | StockBalanceStatusEnum |
| inventory_stock_cards | movement_type | StockMovementTypeEnum |
| inventory_transfers | status | TransferStatusEnum |
| inventory_adjustments | adjustment_type | AdjustmentTypeEnum |
| inventory_adjustments | status | AdjustmentStatusEnum |
