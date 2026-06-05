# Inventory Module — Features

## Master Data

### Categories

Categories group items by operational domain.

Seed data:
- Engineering
- Housekeeping
- Chemical
- Minibar
- Laundry
- Office Supplies
- Spare Parts
- Food & Beverage
- Linen & Bedding

Category fields:
- `category_code` — auto-generated (CAT-00001), unique per property
- `name` — human-readable label
- `description` — optional notes
- `is_active` — soft-enable/disable (inactive categories hidden from item creation)

Operations:
- Create / Edit / Deactivate
- Deactivation blocked if active items reference the category

---

### Units of Measure

Units define how quantities are counted.

Seed data:
- PCS (Pieces)
- BOX (Box)
- KG (Kilogram)
- LITER (Litre)
- ROLL (Roll)
- SET (Set)
- DOZEN (Dozen)
- PAIR (Pair)
- PACK (Pack)
- METER (Metre)
- GRAM (Gram)
- ML (Millilitre)

Unit fields:
- `unit_code` — auto-generated (UNT-00001), unique per property
- `abbreviation` — short display (PCS, KG, L)
- `name` — full name (Pieces, Kilogram, Litre)
- `is_active`

Operations:
- Create / Edit / Deactivate
- Deactivation blocked if active items use the unit

---

### Items (Item Master)

Items are the central entity. Each item has a unique SKU per property.

Item fields:
- `item_code` — auto-generated (ITM-00001), unique per property
- `name` — descriptive name (e.g., "Toilet Roll 2-Ply")
- `description` — optional extended description
- `category_id` — FK to `inventory_categories`
- `unit_id` — FK to `inventory_units`
- `sku` — optional external SKU (nullable, unique per property if set)
- `barcode` — optional barcode (nullable)
- `min_stock` — minimum acceptable quantity; below this triggers "Low Stock"
- `max_stock` — optional maximum storage capacity (nullable)
- `reorder_point` — quantity at which a reorder should be triggered (≤ min_stock)
- `reorder_quantity` — suggested order quantity
- `is_active` — deactivating hides from issue/receipt; still visible in reports
- `notes` — optional

Operations:
- Create / Edit / Deactivate / View
- Deactivation allowed even with stock on hand (stock remains; further issues blocked)

---

## Locations

Locations represent physical storage areas within a property.

Location fields:
- `location_code` — auto-generated (LOC-00001), unique per property
- `name` — (e.g., "Main Store", "Engineering Store")
- `description` — optional
- `location_type` — enum (main_store, department_store, minibar_store, laundry_store, other)
- `is_active`

Operations:
- Create / Edit / Deactivate
- Deactivation blocked if non-zero stock balances exist at the location

---

## Stock Balances

Stock balances are the live quantity of each item at each location.

- One record per (property, item, location) combination
- Created automatically when first movement is posted for that combination
- Never deleted; goes to zero when stock is exhausted
- Updated atomically by the StockMovementService after every movement

Dashboard shows a balance grid:
- Item name | Category | Unit | Qty | Status | Last Movement

Status values per balance record:
- `in_stock` — quantity > 0 AND quantity >= item.reorder_point
- `low_stock` — quantity > 0 AND quantity < item.reorder_point
- `out_of_stock` — quantity = 0

---

## Stock Card (Inventory Ledger)

Every stock movement creates one or more stock card entries. The stock card is immutable — no update or delete is permitted.

Stock card entry fields:
- `item_id`
- `location_id`
- `movement_type` — enum (see StockMovementTypeEnum)
- `quantity_before` — balance before this movement
- `quantity_change` — positive (in) or negative (out)
- `quantity_after` — balance after this movement
- `reference_type` — optional polymorphic type (transfer, adjustment, etc.)
- `reference_id` — optional polymorphic ID
- `remarks` — optional note
- `posted_by` — user who triggered the movement
- `posted_at` — timestamp

Movement types:
- `opening_balance` — initial stock loading for a location
- `purchase_receipt` — stock received from supplier
- `issue` — stock issued for use
- `transfer_out` — stock leaving a location
- `transfer_in` — stock arriving at a location
- `adjustment_in` — positive adjustment (found, correction +)
- `adjustment_out` — negative adjustment (damaged, lost, correction -)
- `return` — stock returned from use back to store

---

## Stock Receipts

A receipt records stock coming in from a supplier or purchase. It is a two-step document: create as a draft, then post to apply stock changes.

Receipt header fields:
- `receipt_number` — auto-generated (RCT-00001), unique per property
- `supplier_name` — optional free-text supplier name
- `external_reference` — optional PO number, delivery note, or invoice reference
- `received_at` — actual date/time goods were physically received (defaults to post time if not set)
- `status` — Draft → Posted | Cancelled
- `remarks` — optional notes

Receipt line fields (per item):
- `item_id` — item being received
- `location_id` — destination storage location (per line — different items can go to different locations)
- `quantity` — quantity received (must be > 0)
- `unit_cost` — cost per unit for this line (decimal(14,4), must be ≥ 0)
- `total_value` — stored as `quantity * unit_cost` at post time

Workflow:
1. **Draft** — create receipt with lines; editable; no stock change
2. **Post** — applies all movements atomically:
   - Creates `purchase_receipt` stock card entry per line (with unit_cost, total_value)
   - Increments balance at each `line.location_id`
   - Updates `item.average_cost` via WAC formula for each item in the receipt
3. **Cancel** — available from Draft only; terminal; no stock change

Costing on post:
```
new_avg_cost = (total_on_hand_qty * current_avg + Σ line_qty * line_unit_cost)
               ÷ (total_on_hand_qty + Σ line_qty)
```
Applied per item across all lines for that item within the receipt.

---

## Stock Issues

An issue records stock leaving a location for use. Two-step: create draft, then post.

Issue header fields:
- `issue_number` — auto-generated (ISS-00001), unique per property
- `issued_to_type` — optional polymorphic type (`work_order`, `cleaning_task`, `department`, `general` — V1: nullable)
- `issued_to_id` — optional ULID of the referenced record (V1: nullable)
- `department_id` — optional direct FK to departments for tracking consuming department
- `issued_at` — actual date/time stock was physically issued (defaults to post time if not set)
- `status` — Draft → Posted | Cancelled
- `remarks` — optional notes

Issue line fields (per item):
- `item_id`
- `location_id` — source storage location (per line — different items can come from different locations)
- `quantity` — quantity issued (must be > 0)
- `remarks` — optional per-line notes

Workflow:
1. **Draft** — create issue with lines; editable; no stock change
2. **Post** — applies all movements atomically:
   - Validates all lines pass BR-001 (no negative stock) before any writes
   - Creates `issue` stock card entry per line (unit_cost = `item.average_cost` at post time, total_value stored)
   - Decrements balance at each `line.location_id`
3. **Cancel** — available from Draft only; terminal; no stock change

---

## Transfers

A transfer moves stock from one location to another.

Transfer fields:
- `transfer_number` — auto-generated (TRN-00001)
- `from_location_id`
- `to_location_id` (must differ from from_location_id)
- `status` — enum (draft, submitted, completed, cancelled)
- `notes`
- Lines: item_id, quantity_requested, notes
- Approval fields: requested_by, approved_by, approved_at, completed_by, completed_at

Workflow:
1. **Draft** — created; lines can be added/edited; no stock change
2. **Submit** — locked; no stock change yet; visible in pending transfers dashboard
3. **Complete** — stock deducted from source, added to destination; stock card entries created
4. **Cancel** — available from Draft or Submitted; no stock change; terminal state

Completion creates two stock card entries per line:
- `transfer_out` at `from_location`
- `transfer_in` at `to_location`

---

## Adjustments

An adjustment corrects stock discrepancies or records shrinkage.

Adjustment fields:
- `adjustment_number` — auto-generated (ADJ-00001)
- `location_id`
- `adjustment_type` — enum (stock_take, damaged, lost, found, correction)
- `status` — enum (draft, submitted, approved, rejected)
- `reason` — mandatory text explanation
- Lines: item_id, quantity_system (read-only, from balance), quantity_actual (entered by user), quantity_variance (computed)
- Approval fields: submitted_by, approved_by, approved_at

Workflow:
1. **Draft** — created; system quantities populated from current balances; user enters actual quantities
2. **Submit** — locked for review; no stock change yet
3. **Approve** — stock balances updated; stock card entries created; terminal positive state
4. **Reject** — returned without stock change; terminal negative state

Approval creates stock card entries per line where variance ≠ 0:
- Positive variance (quantity_actual > quantity_system) → `adjustment_in`
- Negative variance (quantity_actual < quantity_system) → `adjustment_out`

---

## Dashboard

The Inventory Dashboard shows:

**Summary Stats (4 cards):**
- Total Items (count of active items)
- Low Stock Items (count where any balance is low_stock)
- Out of Stock Items (count where any balance is out_of_stock)
- Pending Transfers (count of Submitted transfers)

**Tables:**
- Low Stock Items — item name, category, location, current qty, reorder point
- Recent Movements — last 20 stock card entries (item, type, qty change, location, who, when)
- Pending Transfers — transfer number, from→to, lines count, submitted by, submitted at

**Quick Actions:**
- New Receipt
- New Issue
- New Transfer
- New Adjustment
