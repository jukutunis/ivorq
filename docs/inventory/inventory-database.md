# Inventory Module — Database Design
# Version 1.1

## Overview

14 tables. All use ULID primary keys (char 26), soft deletes, and audit columns (`created_by`, `updated_by`) consistent with all other Operations modules. All tables have `property_id` with `restrictOnDelete()`.

**Exceptions to standard column set:**
- `inventory_stock_cards` — immutable ledger: no `updated_by`, no soft deletes, no `updated_at`
- `inventory_stock_balances` — system-managed: no `created_by`, no `updated_by`, no soft deletes
- `inventory_transfer_lines`, `inventory_receipt_lines`, `inventory_issue_lines`, `inventory_adjustment_lines` — child lines: no `created_by`, no `updated_by`, no soft deletes (cascade with header)

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

Item master. Central reference for all stock movements and costing.

```
inventory_items
├── id               char(26) PK
├── property_id      char(26) FK → properties.id (restrictOnDelete)
├── item_code        varchar(20)      — ITM-00001, unique per property
├── name             varchar(255)
├── description      text nullable
├── category_id      char(26) FK → inventory_categories.id (restrictOnDelete)
├── unit_id          char(26) FK → inventory_units.id (restrictOnDelete)
├── sku              varchar(100) nullable    — unique per property if set
├── barcode          varchar(100) nullable
├── min_stock        decimal(10,3) default 0      — minimum safe level
├── max_stock        decimal(10,3) nullable        — optional storage cap
├── reorder_point    decimal(10,3) default 0      — trigger reorder below this
├── reorder_quantity decimal(10,3) default 0      — suggested order qty
├── average_cost     decimal(14,4) default 0      — v1.1: weighted average unit cost
├── is_active        boolean default true
├── notes            text nullable
├── created_by       char(26) nullable
├── updated_by       char(26) nullable
├── created_at       timestamp nullable
├── updated_at       timestamp nullable
└── deleted_at       timestamp nullable

Indexes:
  UNIQUE (property_id, item_code)
  UNIQUE (property_id, sku)   — partial; excludes NULLs (DB-level via filtered index or service enforcement)
  INDEX  (property_id, category_id)
  INDEX  (property_id, unit_id)
  INDEX  (property_id, is_active)
  INDEX  (property_id, name)
```

**v1.1 addition:** `average_cost decimal(14,4) default 0`
Updated by `ReceiptService` on every receipt posting via the weighted average cost formula. Read by `IssueService` at post time to stamp `unit_cost` on the stock card.

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

Current on-hand quantity per item per location. Denormalized for performance.

```
inventory_stock_balances
├── id               char(26) PK
├── property_id      char(26) FK → properties.id (restrictOnDelete)
├── item_id          char(26) FK → inventory_items.id (restrictOnDelete)
├── location_id      char(26) FK → inventory_locations.id (restrictOnDelete)
├── quantity         decimal(10,3) default 0
├── status           varchar(30) default 'out_of_stock'   — StockBalanceStatusEnum
├── last_movement_at datetime nullable
├── created_at       timestamp nullable
└── updated_at       timestamp nullable

Note: No deleted_at, no created_by, no updated_by.
      Updated only by StockMovementService.

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
├── movement_type   varchar(30)      — StockMovementTypeEnum value
├── quantity_before decimal(10,3)    — balance before this movement
├── quantity_change decimal(10,3)    — positive = in, negative = out
├── quantity_after  decimal(10,3)    — balance after this movement
├── unit_cost       decimal(14,4) nullable    — v1.1: cost per unit at time of movement
├── total_value     decimal(14,4) nullable    — v1.1: |quantity_change| * unit_cost
├── reference_type  varchar(50) nullable      — 'receipt', 'issue', 'transfer', 'adjustment'
├── reference_id    char(26) nullable         — ULID of the source document
├── remarks         text nullable
├── posted_by       char(26) FK → users.id (restrictOnDelete)
└── posted_at       datetime

Note: No updated_at, no deleted_at, no updated_by. HasAuditColumns NOT applied.
      SoftDeletes NOT applied. This table is append-only.

Indexes:
  INDEX (property_id, item_id, posted_at)
  INDEX (property_id, location_id, posted_at)
  INDEX (property_id, item_id, location_id, posted_at)
  INDEX (property_id, movement_type, posted_at)
  INDEX (property_id, reference_type, reference_id)
  INDEX (property_id, posted_at)
```

**v1.1 additions:**
- `unit_cost decimal(14,4) nullable` — populated for costed movements (receipts, issues, adjustments). Null for opening balances and transfers where cost is not tracked per movement.
- `total_value decimal(14,4) nullable` — computed as `|quantity_change| * unit_cost`. Stored for immutability; not recomputed after insert.

---

### 7. `inventory_receipts`

**v1.1 new table.** Receipt transaction header. Records incoming stock from a supplier or purchase.

```
inventory_receipts
├── id                   char(26) PK
├── property_id          char(26) FK → properties.id (restrictOnDelete)
├── receipt_number       varchar(20)      — RCT-00001, unique per property
├── supplier_name        varchar(255) nullable   — free-text supplier name
├── external_reference   varchar(100) nullable   — PO number, delivery note, invoice ref
├── status               varchar(30) default 'draft'   — ReceiptStatusEnum
├── received_at          datetime nullable       — actual date/time of physical receipt
├── remarks              text nullable
├── posted_by            char(26) nullable FK → users.id   — who posted the receipt
├── posted_at            datetime nullable       — when the receipt was posted
├── cancelled_by         char(26) nullable FK → users.id
├── cancelled_at         datetime nullable
├── created_by           char(26) nullable
├── updated_by           char(26) nullable
├── created_at           timestamp nullable
├── updated_at           timestamp nullable
└── deleted_at           timestamp nullable

Indexes:
  UNIQUE (property_id, receipt_number)
  INDEX  (property_id, status)
  INDEX  (property_id, supplier_name)
  INDEX  (property_id, received_at)
  INDEX  (property_id, created_at)
```

Notes:
- `received_at` — the physical date goods arrived; can be set by the user at creation or defaults to `posted_at` if left null when posting.
- `external_reference` — for cross-referencing with purchase orders or supplier documents.
- No `location_id` on the header — location is specified per line (a single receipt can receive into multiple locations).

---

### 8. `inventory_receipt_lines`

**v1.1 new table.** Line items for each receipt.

```
inventory_receipt_lines
├── id           char(26) PK
├── property_id  char(26) FK → properties.id (restrictOnDelete)
├── receipt_id   char(26) FK → inventory_receipts.id (cascadeOnDelete)
├── item_id      char(26) FK → inventory_items.id (restrictOnDelete)
├── location_id  char(26) FK → inventory_locations.id (restrictOnDelete)
├── quantity     decimal(10,3)
├── unit_cost    decimal(14,4)      — cost per unit for this line
├── total_value  decimal(14,4)      — quantity * unit_cost (stored, not computed)
├── notes        text nullable
├── created_at   timestamp nullable
└── updated_at   timestamp nullable

Note: No deleted_at, no created_by, no updated_by. Cascade-deletes with header.

Indexes:
  INDEX (receipt_id)
  INDEX (property_id, item_id)
  INDEX (property_id, location_id)
```

Notes:
- `location_id` is on the line — different items in one receipt can go to different locations.
- `total_value` is stored denormalized for immutability after posting.
- `unit_cost` must be ≥ 0; zero is permitted for free/donated items.

---

### 9. `inventory_issues`

**v1.1 new table.** Issue transaction header. Records outgoing stock consumed by operations.

```
inventory_issues
├── id              char(26) PK
├── property_id     char(26) FK → properties.id (restrictOnDelete)
├── issue_number    varchar(20)       — ISS-00001, unique per property
├── issued_to_type  varchar(50) nullable    — polymorphic: 'work_order', 'cleaning_task', 'reservation', 'department', 'general'
├── issued_to_id    char(26) nullable       — ULID of the referenced record (nullable in V1 for manual issues)
├── department_id   char(26) nullable FK → departments.id   — issuing department (direct reference)
├── status          varchar(30) default 'draft'   — IssueStatusEnum
├── issued_at       datetime nullable       — actual date/time stock was physically issued
├── remarks         text nullable
├── posted_by       char(26) nullable FK → users.id
├── posted_at       datetime nullable
├── cancelled_by    char(26) nullable FK → users.id
├── cancelled_at    datetime nullable
├── created_by      char(26) nullable
├── updated_by      char(26) nullable
├── created_at      timestamp nullable
├── updated_at      timestamp nullable
└── deleted_at      timestamp nullable

Indexes:
  UNIQUE (property_id, issue_number)
  INDEX  (property_id, status)
  INDEX  (property_id, department_id)
  INDEX  (property_id, issued_to_type, issued_to_id)
  INDEX  (property_id, issued_at)
  INDEX  (property_id, created_at)
```

Notes:
- `issued_to_type / issued_to_id` — polymorphic pair for future V2 integration with Engineering Work Orders, Housekeeping Tasks, and PMS Folios. In V1, both are nullable for manual/general issues.
- `department_id` — direct FK for tracking which department consumed the stock. Nullable; resolved from `issued_to_type` in V2.
- No `location_id` on header — location is per line (a single issue can pull from multiple locations).

---

### 10. `inventory_issue_lines`

**v1.1 new table.** Line items for each issue.

```
inventory_issue_lines
├── id           char(26) PK
├── property_id  char(26) FK → properties.id (restrictOnDelete)
├── issue_id     char(26) FK → inventory_issues.id (cascadeOnDelete)
├── item_id      char(26) FK → inventory_items.id (restrictOnDelete)
├── location_id  char(26) FK → inventory_locations.id (restrictOnDelete)
├── quantity     decimal(10,3)
├── remarks      text nullable
├── created_at   timestamp nullable
└── updated_at   timestamp nullable

Note: No deleted_at, no created_by, no updated_by. Cascade-deletes with header.

Indexes:
  INDEX (issue_id)
  INDEX (property_id, item_id)
  INDEX (property_id, location_id)
```

Notes:
- `location_id` is on the line — different items can be pulled from different locations in one issue.
- No `unit_cost` or `total_value` on the line — costs are on the stock card (service stamps `item.average_cost` at post time).

---

### 11. `inventory_transfers`

Transfer transaction header. Moves stock between locations.

```
inventory_transfers
├── id               char(26) PK
├── property_id      char(26) FK → properties.id (restrictOnDelete)
├── transfer_number  varchar(20)      — TRN-00001, unique per property
├── from_location_id char(26) FK → inventory_locations.id (restrictOnDelete)
├── to_location_id   char(26) FK → inventory_locations.id (restrictOnDelete)
├── status           varchar(30) default 'draft'   — TransferStatusEnum
├── notes            text nullable
├── requested_by     char(26) FK → users.id (restrictOnDelete)
├── approved_by      char(26) nullable FK → users.id
├── approved_at      datetime nullable
├── completed_by     char(26) nullable FK → users.id
├── completed_at     datetime nullable
├── cancelled_by     char(26) nullable FK → users.id
├── cancelled_at     datetime nullable
├── created_by       char(26) nullable
├── updated_by       char(26) nullable
├── created_at       timestamp nullable
├── updated_at       timestamp nullable
└── deleted_at       timestamp nullable

Indexes:
  UNIQUE (property_id, transfer_number)
  INDEX  (property_id, status)
  INDEX  (property_id, from_location_id, status)
  INDEX  (property_id, to_location_id, status)
  INDEX  (property_id, requested_by)
  INDEX  (property_id, created_at)
```

---

### 12. `inventory_transfer_lines`

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

Indexes:
  INDEX (transfer_id)
  INDEX (property_id, item_id)
```

---

### 13. `inventory_adjustments`

Adjustment transaction header. Corrects stock discrepancies.

```
inventory_adjustments
├── id                 char(26) PK
├── property_id        char(26) FK → properties.id (restrictOnDelete)
├── adjustment_number  varchar(20)    — ADJ-00001, unique per property
├── location_id        char(26) FK → inventory_locations.id (restrictOnDelete)
├── adjustment_type    varchar(30)    — AdjustmentTypeEnum
├── status             varchar(30) default 'draft'  — AdjustmentStatusEnum
├── reason             text           — required
├── submitted_by       char(26) nullable FK → users.id
├── submitted_at       datetime nullable
├── approved_by        char(26) nullable FK → users.id
├── approved_at        datetime nullable
├── rejected_by        char(26) nullable FK → users.id
├── rejected_at        datetime nullable
├── rejection_reason   text nullable
├── created_by         char(26) nullable
├── updated_by         char(26) nullable
├── created_at         timestamp nullable
├── updated_at         timestamp nullable
└── deleted_at         timestamp nullable

Indexes:
  UNIQUE (property_id, adjustment_number)
  INDEX  (property_id, status)
  INDEX  (property_id, adjustment_type)
  INDEX  (property_id, location_id, status)
  INDEX  (property_id, created_at)
```

---

### 14. `inventory_adjustment_lines`

Line items for each adjustment. Captures system vs actual quantities.

```
inventory_adjustment_lines
├── id                char(26) PK
├── property_id       char(26) FK → properties.id (restrictOnDelete)
├── adjustment_id     char(26) FK → inventory_adjustments.id (cascadeOnDelete)
├── item_id           char(26) FK → inventory_items.id (restrictOnDelete)
├── quantity_system   decimal(10,3)   — snapshot of balance at draft creation
├── quantity_actual   decimal(10,3)   — entered by the user
├── quantity_variance decimal(10,3)   — quantity_actual - quantity_system (stored)
├── notes             text nullable
├── created_at        timestamp nullable
└── updated_at        timestamp nullable

Indexes:
  INDEX (adjustment_id)
  INDEX (property_id, item_id)
```

---

## Entity Relationships

```
properties
├── inventory_categories (many)
├── inventory_units      (many)
├── inventory_items      (many)
│   ├── FK → inventory_categories
│   └── FK → inventory_units
├── inventory_locations  (many)
├── inventory_stock_balances (many)
│   ├── FK → inventory_items
│   └── FK → inventory_locations
├── inventory_stock_cards (many, immutable)
│   ├── FK → inventory_items
│   └── FK → inventory_locations
├── inventory_receipts   (many)                          ← v1.1
│   └── inventory_receipt_lines (many)                  ← v1.1
│       ├── FK → inventory_items
│       └── FK → inventory_locations
├── inventory_issues     (many)                          ← v1.1
│   └── inventory_issue_lines (many)                    ← v1.1
│       ├── FK → inventory_items
│       └── FK → inventory_locations
├── inventory_transfers  (many)
│   ├── FK → inventory_locations (from)
│   ├── FK → inventory_locations (to)
│   └── inventory_transfer_lines (many)
│       └── FK → inventory_items
└── inventory_adjustments (many)
    ├── FK → inventory_locations
    └── inventory_adjustment_lines (many)
        └── FK → inventory_items
```

---

## Migration Ordering

14 migrations, ordered by dependency:

```
1.  create_inventory_categories_table           (prefix: _000060)
2.  create_inventory_units_table                (prefix: _000061)
3.  create_inventory_locations_table            (prefix: _000062)
4.  create_inventory_items_table                (prefix: _000063)  — depends: categories, units
5.  create_inventory_stock_balances_table       (prefix: _000064)  — depends: items, locations
6.  create_inventory_stock_cards_table          (prefix: _000065)  — depends: items, locations
7.  create_inventory_receipts_table             (prefix: _000066)  ← v1.1
8.  create_inventory_receipt_lines_table        (prefix: _000067)  ← v1.1, depends: receipts, items, locations
9.  create_inventory_issues_table               (prefix: _000068)  ← v1.1
10. create_inventory_issue_lines_table          (prefix: _000069)  ← v1.1, depends: issues, items, locations
11. create_inventory_transfers_table            (prefix: _000070)  — depends: locations
12. create_inventory_transfer_lines_table       (prefix: _000071)  — depends: transfers, items
13. create_inventory_adjustments_table          (prefix: _000072)  — depends: locations
14. create_inventory_adjustment_lines_table     (prefix: _000073)  — depends: adjustments, items
```

---

## Complete Table List (v1.1)

| # | Table | New in v1.1 | Notes |
|---|---|---|---|
| 1 | inventory_categories | — | Master data |
| 2 | inventory_units | — | Master data |
| 3 | inventory_items | `average_cost` column added | Master data + costing |
| 4 | inventory_locations | — | Storage locations |
| 5 | inventory_stock_balances | — | Live balance per item/location |
| 6 | inventory_stock_cards | `unit_cost`, `total_value` columns added | Immutable ledger |
| 7 | inventory_receipts | **New** | Receipt header |
| 8 | inventory_receipt_lines | **New** | Receipt lines with unit_cost |
| 9 | inventory_issues | **New** | Issue header |
| 10 | inventory_issue_lines | **New** | Issue lines |
| 11 | inventory_transfers | — | Transfer header |
| 12 | inventory_transfer_lines | — | Transfer lines |
| 13 | inventory_adjustments | — | Adjustment header |
| 14 | inventory_adjustment_lines | — | Adjustment lines |

---

## Enum Columns Summary (v1.1)

| Table | Column | Enum Class |
|---|---|---|
| inventory_locations | location_type | LocationTypeEnum |
| inventory_stock_balances | status | StockBalanceStatusEnum |
| inventory_stock_cards | movement_type | StockMovementTypeEnum |
| inventory_receipts | status | ReceiptStatusEnum |
| inventory_issues | status | IssueStatusEnum |
| inventory_transfers | status | TransferStatusEnum |
| inventory_adjustments | adjustment_type | AdjustmentTypeEnum |
| inventory_adjustments | status | AdjustmentStatusEnum |
