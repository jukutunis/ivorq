# Inventory Module — Implementation Roadmap
# Version 1.1

## Phase Overview

12 phases, ordered by dependency. Each phase must pass `php artisan test` and `npm run build` before the next begins.

| Phase | Name | Complexity | Risk | v1.1 Changes |
|---|---|---|---|---|
| 1 | Enums | Low | Low | +2 enums (ReceiptStatus, IssueStatus) |
| 2 | Migrations | Medium | Low | 10 → 14 migrations; +2 columns on items/stock_cards |
| 3 | Models | Medium | Low | +4 models (Receipt, ReceiptLine, Issue, IssueLine) |
| 4 | Repositories | Medium | Low | +2 repositories; StockBalanceRepo gets WAC query |
| 5 | Services | High | **High** | +2 services; StockMovementService gets costing; AdjustmentService gets staleness check |
| 6 | Policies | Low | Low | +2 policies (Receipt, Issue) |
| 7 | Form Requests | Medium | Low | +6 requests (Store/Post/Cancel × 2) |
| 8 | API Resources | Low | Low | +4 resources (Receipt, ReceiptLine, Issue, IssueLine); StockCardResource updated |
| 9 | Controllers | Medium | Low | ReceiptController and IssueController fully expanded |
| 10 | Tests | High | **High** | +WAC tests; +receipt/issue lifecycle tests; +staleness check tests |
| 11 | Seeders | Low | Low | No change |
| 12 | UI | High | Low | +Receipt and Issue pages; Item show gains average_cost display |

---

## Phase 1 — Enums

**Path:** `Modules/Operations/Inventory/Enums/`

**Files to create:**

### `LocationTypeEnum.php`
```
Cases: MainStore, DepartmentStore, MinibarStore, LaundryStore, Other
Pattern: label() only — no state machine
```

### `StockBalanceStatusEnum.php`
```
Cases: InStock, LowStock, OutOfStock
Pattern: label() only — no state machine
Computed by service, stored on balance record
```

### `StockMovementTypeEnum.php`
```
Cases: OpeningBalance, PurchaseReceipt, Issue, TransferOut, TransferIn,
       AdjustmentIn, AdjustmentOut, Return
Pattern: label() + direction() → 'in' | 'out'
```

### `TransferStatusEnum.php`
```
Cases: Draft, Submitted, Completed, Cancelled
Pattern: label() + allowedTransitions() + canTransitionTo() + isTerminal()
Machine:
  Draft → [Submitted, Cancelled]
  Submitted → [Completed, Cancelled]
  Completed → []  (terminal)
  Cancelled → []  (terminal)
```

### `AdjustmentTypeEnum.php`
```
Cases: StockTake, Damaged, Lost, Found, Correction
Pattern: label() only
```

### `AdjustmentStatusEnum.php`
```
Cases: Draft, Submitted, Approved, Rejected, Cancelled
Pattern: label() + allowedTransitions() + canTransitionTo() + isTerminal()
Machine:
  Draft → [Submitted, Cancelled]
  Submitted → [Approved, Rejected]
  Approved → []  (terminal)
  Rejected → []  (terminal)
  Cancelled → []  (terminal)
```

### `ReceiptStatusEnum.php`  ← v1.1 new
```
Cases: Draft, Posted, Cancelled
Pattern: label() + allowedTransitions() + canTransitionTo() + isTerminal()
Machine:
  Draft → [Posted, Cancelled]
  Posted → []    (terminal)
  Cancelled → [] (terminal)
```

### `IssueStatusEnum.php`  ← v1.1 new
```
Cases: Draft, Posted, Cancelled
Pattern: label() + allowedTransitions() + canTransitionTo() + isTerminal()
Machine: identical to ReceiptStatusEnum
  Draft → [Posted, Cancelled]
  Posted → []    (terminal)
  Cancelled → [] (terminal)
```

**Total enums: 8** (was 6)

**Estimated effort:** 1-2 hours
**Risk:** Low — pure PHP, no dependencies

---

## Phase 2 — Migrations

**Path:** `Modules/Operations/Inventory/database/migrations/`

**Files to create (14 migrations):**  ← v1.1: was 10

```
YYYYMMDD_000060_create_inventory_categories_table.php
YYYYMMDD_000061_create_inventory_units_table.php
YYYYMMDD_000062_create_inventory_locations_table.php
YYYYMMDD_000063_create_inventory_items_table.php           — includes average_cost
YYYYMMDD_000064_create_inventory_stock_balances_table.php
YYYYMMDD_000065_create_inventory_stock_cards_table.php     — includes unit_cost, total_value
YYYYMMDD_000066_create_inventory_receipts_table.php        ← v1.1 new
YYYYMMDD_000067_create_inventory_receipt_lines_table.php   ← v1.1 new
YYYYMMDD_000068_create_inventory_issues_table.php          ← v1.1 new
YYYYMMDD_000069_create_inventory_issue_lines_table.php     ← v1.1 new
YYYYMMDD_000070_create_inventory_transfers_table.php
YYYYMMDD_000071_create_inventory_transfer_lines_table.php
YYYYMMDD_000072_create_inventory_adjustments_table.php
YYYYMMDD_000073_create_inventory_adjustment_lines_table.php
```

See `inventory-database.md` for exact column definitions.

**Key schema decisions:**
- All quantities: `decimal(10, 3)`
- All cost/value columns: `decimal(14, 4)` — higher precision for monetary values
- All IDs: `char(26)` ULID
- All FKs to properties: `restrictOnDelete()`
- Line tables cascade-delete with their header: `cascadeOnDelete()`
- `inventory_stock_cards`: no `deleted_at`, no `updated_by`, no `updated_at` (append-only)
- `inventory_stock_balances`: no `deleted_at`, no `created_by`, no `updated_by`
- `inventory_receipt_lines`, `inventory_issue_lines`: no soft deletes, no audit columns
- `inventory_issues.department_id` → FK to `departments.id` (restrictOnDelete) from Foundation module

**Estimated effort:** 3-4 hours  ← was 2-3
**Risk:** Low — established migration patterns

---

## Phase 3 — Models

**Path:** `Modules/Operations/Inventory/Models/`

**Files to create (14 models):**  ← v1.1: was 10

```
InventoryCategory.php       (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryUnit.php           (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryLocation.php       (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryItem.php           (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryStockBalance.php   (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryStockCard.php      (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryReceipt.php        (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes) ← v1.1
InventoryReceiptLine.php    (HasUlid, BelongsToProperty — no audit columns, no soft deletes) ← v1.1
InventoryIssue.php          (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes) ← v1.1
InventoryIssueLine.php      (HasUlid, BelongsToProperty — no audit columns, no soft deletes) ← v1.1
InventoryTransfer.php       (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryTransferLine.php   (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryAdjustment.php     (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryAdjustmentLine.php (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
```

**Key casts (v1.1 additions marked):**
- `InventoryItem`: `min_stock`, `max_stock`, `reorder_point`, `reorder_quantity` → `'decimal:3'`; `average_cost` → `'decimal:4'` ← v1.1
- `InventoryStockBalance`: `quantity` → `'decimal:3'`, `status` → `StockBalanceStatusEnum::class`
- `InventoryStockCard`: `quantity_before/change/after` → `'decimal:3'`; `unit_cost`, `total_value` → `'decimal:4'` ← v1.1; `movement_type` → `StockMovementTypeEnum::class`; `posted_at` → `'datetime'`
- `InventoryReceipt`: `status` → `ReceiptStatusEnum::class`; `received_at`, `posted_at`, `cancelled_at` → `'datetime'` ← v1.1
- `InventoryReceiptLine`: `quantity` → `'decimal:3'`; `unit_cost`, `total_value` → `'decimal:4'` ← v1.1
- `InventoryIssue`: `status` → `IssueStatusEnum::class`; `issued_at`, `posted_at`, `cancelled_at` → `'datetime'` ← v1.1
- `InventoryIssueLine`: `quantity` → `'decimal:3'` ← v1.1
- `InventoryTransfer`: `status` → `TransferStatusEnum::class`; datetime columns → `'datetime'`
- `InventoryAdjustment`: `adjustment_type` → `AdjustmentTypeEnum::class`, `status` → `AdjustmentStatusEnum::class`

**Key relationships (v1.1 additions marked):**
- `InventoryItem` hasMany `InventoryStockBalance`, `InventoryStockCard`, `InventoryReceiptLine`, `InventoryIssueLine` ← v1.1
- `InventoryItem` belongsTo `InventoryCategory`, `InventoryUnit`
- `InventoryLocation` hasMany `InventoryStockBalance`, `InventoryReceiptLine`, `InventoryIssueLine` ← v1.1
- `InventoryReceipt` hasMany `InventoryReceiptLine` ← v1.1
- `InventoryIssue` hasMany `InventoryIssueLine`; belongsTo `Department` (nullable) ← v1.1
- `InventoryTransfer` hasMany `InventoryTransferLine`; belongsTo `InventoryLocation` (from + to)
- `InventoryAdjustment` hasMany `InventoryAdjustmentLine`; belongsTo `InventoryLocation`

**Estimated effort:** 3-4 hours  ← was 2-3
**Risk:** Low — established model patterns

---

## Phase 4 — Repositories

**Path:** `Modules/Operations/Inventory/Repositories/`

**Files to create (8 repositories):**

```
CategoryRepository.php
UnitRepository.php
ItemRepository.php
  - paginate(filters: name, category_id, is_active, status)
  - find(id) → with(category, unit, balances.location)
  - create/update/delete
  - lowStock(): Collection  — items where any balance < reorder_point
  - outOfStock(): Collection — items where all balances = 0

LocationRepository.php
  - paginate(filters: location_type, is_active)
  - find(id) → with(balances.item.category, balances.item.unit)

StockBalanceRepository.php
  - forItem(itemId): Collection — all location balances for an item
  - forLocation(locationId): Collection — all item balances at a location
  - findOrCreate(itemId, locationId): InventoryStockBalance
  - updateBalance(id, quantity, status, lastMovementAt): void
  - totalQuantityForItem(itemId): decimal   ← v1.1: sum across all locations (used for WAC)
  - lockForUpdate(itemId, locationId): InventoryStockBalance  ← v1.1: for race-safe operations

StockCardRepository.php
  - paginate(filters: item_id, location_id, movement_type, date_from, date_to)
  - forItem(itemId, limit=20): Collection
  - create(array $data): InventoryStockCard  — ONLY creation, never update/delete
  - recent(limit=20): Collection

ReceiptRepository.php  ← v1.1 new
  - paginate(filters: status, supplier_name, date_from, date_to)
  - find(id) → with(lines.item.unit, lines.location)
  - create/update/delete
  - byStatus(ReceiptStatusEnum): Collection

IssueRepository.php  ← v1.1 new
  - paginate(filters: status, department_id, date_from, date_to)
  - find(id) → with(lines.item.unit, lines.location, department)
  - create/update/delete
  - byStatus(IssueStatusEnum): Collection

TransferRepository.php
  - paginate(filters: status, from_location_id, to_location_id)
  - find(id) → with(fromLocation, toLocation, lines.item.unit, requestedBy, completedBy)
  - pending(): Collection — Submitted status
  - create/update/delete

AdjustmentRepository.php
  - paginate(filters: status, adjustment_type, location_id)
  - find(id) → with(location, lines.item.unit, submittedBy, approvedBy)
  - create/update/delete
```

**Total repositories: 10** (was 8)

**Estimated effort:** 4-5 hours  ← was 3-4
**Risk:** Low — established repository patterns

---

## Phase 5 — Services

**Path:** `Modules/Operations/Inventory/Services/`

**Files to create (6 services):**  ← v1.1: was 4

### `InventoryItemService.php`
CRUD delegation + event dispatch on create. No changes from v1.0.

### `StockMovementService.php`
**Core service.** Handles all stock balance changes. **v1.1: now accepts optional costing parameters.**

```
postMovement(
  itemId, locationId, movementType, quantityChange,
  unitCost = null,           ← v1.1: cost per unit (nullable for non-costed movements)
  referenceType, referenceId, remarks, postedBy
): InventoryStockCard

  1. DB::transaction():
     a. Lock balance row: StockBalance::lockForUpdate(itemId, locationId)
     b. Validate: no negative balance (quantityChange + currentBalance >= 0) for out-movements
     c. Compute quantity_before, quantity_after
     d. Compute total_value = unitCost ? |quantityChange| * unitCost : null
     e. Create StockCard entry (with unit_cost, total_value)
     f. Update or create StockBalance record
     g. Recompute and save balance status
  2. Return StockCard

  Note: does NOT update item.average_cost — that is ReceiptService's responsibility.

postOpeningBalance(itemId, locationId, quantity, unitCost = null): InventoryStockCard
  → validates current balance = 0 (BR-071)
  → calls postMovement() with 'opening_balance'
  → If unitCost provided: item.average_cost = unitCost (sets initial cost)
```

### `ReceiptService.php`  ← v1.1 new
**Handles the full receipt lifecycle including WAC costing.**

```
create(data): InventoryReceipt
  → Creates Draft receipt with lines (total_value = qty * unit_cost stored on line)
  → No stock change

update(receiptId, data): InventoryReceipt
  → Only in Draft status; replaces header + lines

post(receiptId, postedBy): InventoryReceipt
  → Validates: status == draft, at least one line, all quantities > 0
  → DB::transaction():
    a. For each line:
       - Lock balance row
       - StockMovementService::postMovement(
           itemId=line.item_id,
           locationId=line.location_id,
           movementType=PurchaseReceipt,
           quantityChange=+line.quantity,
           unitCost=line.unit_cost,
           referenceType='receipt',
           referenceId=receipt.id
         )
    b. For each UNIQUE item in the receipt:
       - Read current total on-hand: StockBalanceRepository::totalQuantityForItem(itemId)
         (sum AFTER the movements above have been applied)
       - Compute new WAC:
         qty_received = Σ(line.quantity) for this item
         cost_received = Σ(line.quantity * line.unit_cost) for this item
         qty_before_receipt = total_on_hand - qty_received
         new_avg = (qty_before_receipt * current_avg + cost_received) / total_on_hand
       - item.average_cost = new_avg (saved within same transaction)
    c. Update receipt: status = Posted, posted_by, posted_at
       received_at = received_at ?? posted_at
  → event(ReceiptPosted)
  → Returns updated receipt

cancel(receiptId, cancelledBy): InventoryReceipt
  → Only Draft status; no stock change
  → status = Cancelled, cancelled_by, cancelled_at
```

### `IssueService.php`  ← v1.1 new
**Handles the full issue lifecycle, stamping average_cost at post time.**

```
create(data): InventoryIssue
  → Creates Draft issue with lines; no stock change

update(issueId, data): InventoryIssue
  → Only in Draft status

post(issueId, postedBy): InventoryIssue
  → Validates: status == draft, at least one line, all quantities > 0
  → Pre-validate ALL lines for sufficient stock (BR-001) before any writes:
    for each line: StockBalance(item_id, location_id).quantity >= line.quantity
  → If all pass, DB::transaction():
    a. For each line:
       - Read item.average_cost (within transaction for consistency)
       - StockMovementService::postMovement(
           itemId=line.item_id,
           locationId=line.location_id,
           movementType=Issue,
           quantityChange=-line.quantity,
           unitCost=item.average_cost,   ← stamps current avg cost
           referenceType='issue',
           referenceId=issue.id
         )
    b. Update issue: status = Posted, posted_by, posted_at
       issued_at = issued_at ?? posted_at
  → event(IssuePosted)
  → Returns updated issue

cancel(issueId, cancelledBy): InventoryIssue
  → Only Draft status; no stock change
```

### `TransferService.php`
No changes from v1.0 except `postMovement()` now called without `unitCost` (transfers are non-costed).

### `AdjustmentService.php`
**v1.1: approval now includes staleness check (BR-065).**

```
create(data): InventoryAdjustment
  → Snapshot quantity_system from current balances; compute variance

submit(adjustmentId): InventoryAdjustment

approve(adjustmentId, approvedBy): InventoryAdjustment
  → DB::transaction() with lockForUpdate on balances:
    a. status.canTransitionTo(Approved)
    b. *** v1.1: STALENESS CHECK ***
       For each line:
         current_balance = StockBalance::lockForUpdate(item_id, location_id).quantity
         if current_balance != line.quantity_system:
           collect item name + current vs snapshot
       If any mismatch: throw ValidationException listing affected items (BR-065)
       Adjustment stays Submitted — user must create new draft
    c. For each line where variance < 0:
       validate current_balance >= |variance| (BR-066 — redundant if passed BR-065, but explicit)
    d. For each line where variance > 0:
       StockMovementService::postMovement() → adjustment_in
       (unitCost = item.average_cost at approval time, does NOT change avg_cost)
    e. For each line where variance < 0:
       StockMovementService::postMovement() → adjustment_out
    f. Update: status = Approved, approved_by, approved_at
  → event(AdjustmentApproved)

reject(adjustmentId, rejectedBy, reason): InventoryAdjustment
  → No stock changes
```

**Total services: 6** (was 4)

**Estimated effort:** 8-10 hours  ← was 5-7
**Risk:** High — WAC calculation and staleness check require careful transaction design and comprehensive testing. Key risks:
1. WAC race condition: two receipts for the same item processed concurrently. Mitigated by `lockForUpdate` on the item row (or optimistic locking) when updating `average_cost`.
2. Staleness check strictness: a submitted adjustment can be permanently blocked if any movement occurs. This is by design (BR-065) — document clearly in UI.
3. WAC precision: floating-point rounding errors accumulate over many receipts. `decimal(14,4)` storage reduces but does not eliminate this. A small rounding tolerance (±0.0001) may be needed in reporting.

---

## Phase 6 — Policies

**Path:** `Modules/Operations/Inventory/Policies/`

**Files to create (4 policies):**

```
InventoryItemPolicy.php
  viewAny(user): hasPermissionTo('inventory.view')
  view(user, item): hasPermissionTo('inventory.view') + property isolation
  create(user): hasPermissionTo('inventory.item.create')
  update(user, item): hasPermissionTo('inventory.item.edit') + property isolation
  delete(user, item): hasPermissionTo('inventory.item.delete') + property isolation

InventoryLocationPolicy.php
  viewAny, view, create, update, delete
  Permission: inventory.location.manage

InventoryTransferPolicy.php
  viewAny, view: inventory.view
  create, update, delete: inventory.transfer
  complete: inventory.approve

InventoryAdjustmentPolicy.php
  viewAny, view: inventory.view
  create, update, delete: inventory.adjust
  approve, reject: inventory.approve

InventoryReceiptPolicy.php  ← v1.1 new
  viewAny, view: inventory.view
  create, update: inventory.receive
  post: inventory.receive (+ status == draft check)
  cancel: inventory.receive (+ status == draft check)

InventoryIssuePolicy.php  ← v1.1 new
  viewAny, view: inventory.view
  create, update: inventory.issue
  post: inventory.issue (+ status == draft check)
  cancel: inventory.issue (+ status == draft check)
```

**Total policies: 6** (was 4)

**Estimated effort:** 1-2 hours
**Risk:** Low

---

## Phase 7 — Form Requests

**Path:** `Modules/Operations/Inventory/Http/Requests/`

**Files to create (20 requests):**  ← v1.1: was 14

```
Category:   StoreCategoryRequest, UpdateCategoryRequest
Unit:       StoreUnitRequest, UpdateUnitRequest
Item:       StoreItemRequest, UpdateItemRequest
Location:   StoreLocationRequest, UpdateLocationRequest
Receipt:    StoreReceiptRequest, UpdateReceiptRequest,         ← v1.1 expanded
            PostReceiptRequest, CancelReceiptRequest
Issue:      StoreIssueRequest, UpdateIssueRequest,            ← v1.1 expanded
            PostIssueRequest, CancelIssueRequest
Transfer:   StoreTransferRequest, UpdateTransferRequest,
            SubmitTransferRequest, CompleteTransferRequest, CancelTransferRequest
Adjustment: StoreAdjustmentRequest, UpdateAdjustmentRequest,
            SubmitAdjustmentRequest, ApproveAdjustmentRequest, RejectAdjustmentRequest
```

Key validation additions (v1.1):
- `StoreReceiptRequest`: `lines.*.location_id` required; `lines.*.unit_cost` required numeric min:0
- `StoreIssueRequest`: `department_id` nullable + property-scoped exists; `lines.*.location_id` required
- `PostReceiptRequest` / `PostIssueRequest`: no body; authorization checks status == draft
- Line items validate `Rule::exists` scoped to `property_id` AND `is_active = true`

See `inventory-api-spec.md` for full rule details.

**Estimated effort:** 3-4 hours  ← was 2-3
**Risk:** Low

---

## Phase 8 — API Resources

**Path:** `Modules/Operations/Inventory/Http/Resources/`

**Files to create (12 resources):**  ← v1.1: was 8

```
InventoryCategoryResource.php
InventoryUnitResource.php
InventoryItemResource.php           — include average_cost field
InventoryLocationResource.php
InventoryStockBalanceResource.php
StockCardResource.php               — include unit_cost, total_value fields  ← v1.1
InventoryReceiptResource.php        ← v1.1 new
  └── InventoryReceiptLineResource.php  ← v1.1 new
InventoryIssueResource.php          ← v1.1 new
  └── InventoryIssueLineResource.php   ← v1.1 new
InventoryTransferResource.php
  └── InventoryTransferLineResource.php
InventoryAdjustmentResource.php
  └── InventoryAdjustmentLineResource.php
```

All enums as `['value' => $enum->value, 'label' => $enum->label()]`.
All cost/value fields formatted as strings or floats (not stripped of precision).
All relations via `$this->whenLoaded()`.

See `inventory-api-spec.md` for exact JSON shapes.

**Estimated effort:** 2-3 hours
**Risk:** Low

---

## Phase 9 — Controllers

**Path:** `Modules/Operations/Inventory/Http/Controllers/`

**Files to create (10 controllers, same count — ReceiptController and IssueController expanded):**

```
InventoryDashboardController.php
CategoryController.php           (resource)
UnitController.php               (resource)
ItemController.php               (resource)
LocationController.php           (resource)
StockCardController.php          (index, forItem — read only)
ReceiptController.php            (index, create, store, show, edit, update, post, cancel)  ← v1.1 expanded
IssueController.php              (index, create, store, show, edit, update, post, cancel)  ← v1.1 expanded
TransferController.php           (resource + submit, complete, cancel)
AdjustmentController.php         (resource + submit, approve, reject)
```

**Service Provider registration** (`InventoryServiceProvider.php`):
```php
Gate::policy(InventoryItem::class,       InventoryItemPolicy::class);
Gate::policy(InventoryLocation::class,   InventoryLocationPolicy::class);
Gate::policy(InventoryTransfer::class,   InventoryTransferPolicy::class);
Gate::policy(InventoryAdjustment::class, InventoryAdjustmentPolicy::class);

Event::listen(TransferCompleted::class, [LogInventoryActivity::class, 'handle']);
Event::listen(AdjustmentApproved::class, [LogInventoryActivity::class, 'handle']);
// ... etc.
```

**Estimated effort:** 4-5 hours
**Risk:** Low — established controller patterns

---

## Phase 10 — Tests

**Path:** `Modules/Operations/Inventory/Tests/`

**Test files to create:**

```
Unit/
  InventoryEnumTest.php
    - TransferStatusEnum transitions
    - AdjustmentStatusEnum transitions
    - StockMovementTypeEnum direction()
    - StockBalanceStatusEnum label()

Feature/
  CategoryControllerTest.php     (CRUD, policy enforcement)
  UnitControllerTest.php         (CRUD, policy enforcement)
  ItemControllerTest.php         (CRUD, deactivation, policy enforcement)
  LocationControllerTest.php     (CRUD, policy enforcement)
  StockMovementServiceTest.php   (core logic)
    - opening balance creates stock card with unit_cost when provided
    - opening balance fails if current balance > 0
    - postMovement creates stock card with correct unit_cost and total_value
    - postMovement with null unit_cost stores null (transfers, opening w/o cost)
    - balance status recomputed correctly after each movement
    - negative stock rejected (BR-001)
    - transaction rollback on partial failure (mock DB failure mid-transaction)

  ReceiptControllerTest.php  ← v1.1 new
    - create draft (status = draft, no stock change)
    - edit draft (header + line replacement)
    - post receipt (stock increments, stock cards created with unit_cost)
    - post receipt with multiple lines to different locations
    - post updates average_cost via WAC formula (single item, simple case)
    - post updates average_cost via WAC formula (multiple items in one receipt)
    - post WAC edge case: first receipt on zero balance (avg = unit_cost)
    - post WAC: multiple lines for same item in one receipt (batch aggregation)
    - cannot post empty receipt (no lines)
    - cannot post draft with zero quantity line
    - cancel draft → status = cancelled, no stock change
    - cannot post cancelled receipt
    - cannot cancel posted receipt
    - cannot edit posted receipt

  IssueControllerTest.php  ← v1.1 new
    - create draft (status = draft, no stock change)
    - edit draft
    - post issue (stock decrements, stock card created with unit_cost = avg_cost at post time)
    - post issue from multiple locations
    - post fails for insufficient stock (BR-001) — full rollback
    - post fails when ANY line insufficient (not just the first)
    - average_cost is NOT changed by posting an issue
    - unit_cost on stock card matches item.average_cost at post time
    - cancel draft → no stock change
    - cannot post cancelled issue

  TransferControllerTest.php
    - create draft with lines
    - submit draft
    - complete transfer (stock moves; unit_cost null on stock cards)
    - cancel from draft / submitted
    - cannot cancel completed
    - insufficient stock rejected at completion

  AdjustmentControllerTest.php
    - create draft (captures quantity_system snapshot)
    - submit draft
    - approve — stock changes for non-zero variance
    - approve — stamps unit_cost = item.average_cost on stock card
    - approve — does NOT change item.average_cost
    - reject — no stock changes
    - zero-variance line skips stock card
    - STALENESS CHECK: approve fails when any line balance changed since draft ← v1.1
    - staleness check: lists all affected items in error message ← v1.1
    - staleness check: passes when no movements since draft

  StockCardControllerTest.php
    - read-only (no create/edit/delete)
    - unit_cost and total_value included in resource response ← v1.1
```

**Estimated test count:** ~150-180 tests, ~400-480 assertions  ← was 100-130 / 280-340

**Estimated effort:** 8-10 hours  ← was 6-8
**Risk:** High — WAC correctness requires precision testing across multiple scenarios. Staleness check logic has edge cases (concurrent movements between draft and approval). Race condition testing remains a known gap (document in test file comments).

---

## Phase 11 — Seeders

**Path:** `Modules/Operations/Inventory/database/seeders/`

**Files to create (3 seeders):**

### `InventoryPermissionSeeder.php`
```php
$permissions = [
    'inventory.view',
    'inventory.item.create',
    'inventory.item.edit',
    'inventory.item.delete',
    'inventory.location.manage',
    'inventory.category.manage',
    'inventory.unit.manage',
    'inventory.receive',
    'inventory.issue',
    'inventory.transfer',
    'inventory.adjust',
    'inventory.approve',
    'inventory.stocktake',
];
```

Add all inventory permissions to `manager` and `property-admin` roles in `RoleSeeder.php` (update existing file).

### `InventoryMasterDataSeeder.php`
Seeds default categories, units, and one Main Store location per property. For development/demo only.

### Update `DatabaseSeeder.php`
Add `InventoryPermissionSeeder::class` call after existing permission seeders.

**Estimated effort:** 1-2 hours
**Risk:** Low

---

## Phase 12 — UI

**Path:** `resources/js/Pages/Operations/Inventory/`

**Files to create:**

```
Dashboard/Index.tsx
Categories/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
Units/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
Items/Index.tsx, Create.tsx, Edit.tsx, Show.tsx     — show page includes average_cost card
Locations/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
StockCards/Index.tsx                                — unit_cost, total_value columns added
Receipts/Index.tsx, Create.tsx, Edit.tsx, Show.tsx  ← v1.1: Edit added; Show has post/cancel actions
Issues/Index.tsx, Create.tsx, Edit.tsx, Show.tsx    ← v1.1: Edit added; Show has post/cancel actions
Transfers/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
Adjustments/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
```

Plus:
- Update `AppLayout.tsx` — add Inventory section to sidebar
- Update `Types/index.ts` — add all Inventory TypeScript interfaces (see `inventory-ui-guidelines.md`)

**v1.1 UI notes:**
- Receipt Show page: status badge + Post / Cancel action buttons (same pattern as Transfer Show)
- Issue Show page: same pattern; post button shows "insufficient stock" error from JSON response
- Item Show page: add "Average Cost" to the details grid
- Stock Cards index: add `unit_cost` and `total_value` columns (hidden on mobile, visible ≥ md)
- Adjustment Show page: approval section shows staleness error message clearly when approval is blocked

See `inventory-ui-guidelines.md` for complete UI patterns.

**Estimated effort:** 8-10 hours
**Risk:** Low for standard CRUD pages. Medium for multi-line form pages (Receipts, Issues, Transfers, Adjustments) due to dynamic line management.

---

## Total Estimates (v1.1)

| Phase | v1.0 Hours | v1.1 Hours | Delta |
|---|---|---|---|
| 1 Enums | 1-2 | 1-2 | +2 enum files |
| 2 Migrations | 2-3 | 3-4 | +4 migrations, +2 columns |
| 3 Models | 2-3 | 3-4 | +4 models |
| 4 Repositories | 3-4 | 4-5 | +2 repos, 1 method update |
| 5 Services | 5-7 | **8-10** | +2 services, WAC + staleness check |
| 6 Policies | 1-2 | 1-2 | +2 policies |
| 7 Form Requests | 2-3 | 3-4 | +6 requests |
| 8 Resources | 2-3 | 2-3 | +4 resources |
| 9 Controllers | 4-5 | 4-5 | Expanded (not new files) |
| 10 Tests | 6-8 | **8-10** | +receipt/issue/WAC/staleness tests |
| 11 Seeders | 1-2 | 1-2 | No change |
| 12 UI | 8-10 | 9-11 | +Edit pages for receipt/issue |
| **Total** | **37-52** | **47-62** | **+10 hours** |

---

## Risks (v1.1)

### High

**WAC Race Condition on average_cost** (Phase 5 — new in v1.1)
Two concurrent receipts for the same item could read the same `average_cost` and `on_hand_qty` and both compute the new WAC from the same base, resulting in the second receipt overwriting the first's calculation. Mitigation: lock the `inventory_items` row (or balance aggregate) with `lockForUpdate()` before reading `average_cost` during receipt posting. Must be explicitly documented and tested.

**Adjustment Staleness Check Usability** (Phase 5 — new in v1.1)
A submitted adjustment can be permanently blocked from approval if any other stock movement occurs between submission and approval (even an unrelated item in the same location). This is strict by design (BR-065) but can frustrate users in busy operations. Mitigation: document the error message clearly in the UI, showing exactly which items changed and by how much, so the submitter can create a targeted re-count rather than a full stock take.

**Stock Balance Race Condition** (Phase 5 — carried from v1.0)
Two concurrent issues for the same item+location could both read the same balance before either deducts, resulting in negative stock. Mitigated by `lockForUpdate()` on the balance row.

### Medium

**WAC Precision Drift** (Phase 5, 10 — new in v1.1)
Repeated WAC calculations accumulate rounding errors. `decimal(14,4)` storage reduces but does not eliminate this. After many small receipts, `average_cost` may drift by ±0.001. A periodic reconciliation report comparing `Σ(balance * average_cost)` against actual stock value is a V2 concern. Document as a known limitation.

**Multi-Line Transaction Complexity** (Phase 5, 9)
Receipt posting, issue posting, transfer completion, and adjustment approval each apply N movements in one transaction. All lines are validated before any writes. A mid-transaction failure rolls back entirely.

**Test Volume** (Phase 10)
Estimated 150-180 tests. The WAC formula, staleness check, and receipt/issue lifecycle tests are new complexity not present in previous modules.

### Low

**Decimal Precision Display** (Phase 12)
Quantities (`decimal(10,3)`) should display without trailing zeros for integer units. Costs (`decimal(14,4)`) should display to 2 decimal places in the UI. A `formatQty(qty, unit)` and `formatCost(cost, currency)` utility pair should be added.

**Migration Dependency Order** (Phase 2)
Receipts and issues depend on items and locations (prefix `_000066`-`_000069`). Transfers and adjustments come after (`_000070`-`_000073`). The timestamp prefix must preserve this order.

---

## Permission Matrix

| Action | Permission Required |
|---|---|
| View any inventory page | `inventory.view` |
| Create items | `inventory.item.create` |
| Edit items | `inventory.item.edit` |
| Delete items | `inventory.item.delete` |
| Manage categories (create/edit/delete) | `inventory.category.manage` |
| Manage units (create/edit/delete) | `inventory.unit.manage` |
| Manage locations (create/edit/delete) | `inventory.location.manage` |
| Post stock receipts | `inventory.receive` |
| Post stock issues | `inventory.issue` |
| Create/submit transfers | `inventory.transfer` |
| Complete/approve transfers | `inventory.approve` |
| Create/submit adjustments | `inventory.adjust` |
| Approve/reject adjustments | `inventory.approve` |
| Conduct stock takes | `inventory.stocktake` |

**Role mapping (proposed):**

| Role | Permissions |
|---|---|
| super-admin | all |
| property-admin | all |
| manager | view + item.create/edit + receive + issue + transfer + adjust |
| housekeeping-staff | view + issue (housekeeping items only — V2 enhancement) |
| engineering-staff | view + issue (engineering items only — V2 enhancement) |
