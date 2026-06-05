# Inventory Module — Implementation Roadmap

## Phase Overview

12 phases, ordered by dependency. Each phase must pass `php artisan test` and `npm run build` before the next begins.

| Phase | Name | Complexity | Risk |
|---|---|---|---|
| 1 | Enums | Low | Low |
| 2 | Migrations | Medium | Low |
| 3 | Models | Medium | Low |
| 4 | Repositories | Medium | Low |
| 5 | Services | High | Medium |
| 6 | Policies | Low | Low |
| 7 | Form Requests | Medium | Low |
| 8 | API Resources | Low | Low |
| 9 | Controllers | Medium | Low |
| 10 | Tests | High | Medium |
| 11 | Seeders | Low | Low |
| 12 | UI | High | Low |

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

**Estimated effort:** 1-2 hours
**Risk:** Low — pure PHP, no dependencies

---

## Phase 2 — Migrations

**Path:** `Modules/Operations/Inventory/database/migrations/`

**Files to create (10 migrations):**

```
YYYYMMDD_000060_create_inventory_categories_table.php
YYYYMMDD_000061_create_inventory_units_table.php
YYYYMMDD_000062_create_inventory_locations_table.php
YYYYMMDD_000063_create_inventory_items_table.php
YYYYMMDD_000064_create_inventory_stock_balances_table.php
YYYYMMDD_000065_create_inventory_stock_cards_table.php
YYYYMMDD_000066_create_inventory_transfers_table.php
YYYYMMDD_000067_create_inventory_transfer_lines_table.php
YYYYMMDD_000068_create_inventory_adjustments_table.php
YYYYMMDD_000069_create_inventory_adjustment_lines_table.php
```

See `inventory-database.md` for exact column definitions.

**Key schema decisions:**
- All quantities: `decimal(10, 3)`
- All IDs: `char(26)` ULID
- All FKs to properties: `restrictOnDelete()`
- Transfer/adjustment line cascade: `cascadeOnDelete()` (lines are part of the header)
- stock_cards: no `deleted_at`, no `updated_by`, no `updated_at`
- stock_balances: no `deleted_at`, no `created_by`, no `updated_by`

**Estimated effort:** 2-3 hours
**Risk:** Low — established migration patterns

---

## Phase 3 — Models

**Path:** `Modules/Operations/Inventory/Models/`

**Files to create (10 models):**

```
InventoryCategory.php    (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryUnit.php        (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryLocation.php    (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryItem.php        (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryStockBalance.php (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryStockCard.php   (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryTransfer.php    (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryTransferLine.php (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
InventoryAdjustment.php  (HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes)
InventoryAdjustmentLine.php (HasUlid, BelongsToProperty — no audit columns, no soft deletes)
```

**Key casts:**
- `InventoryItem`: `min_stock`, `max_stock`, `reorder_point`, `reorder_quantity` → `'decimal:3'`
- `InventoryStockBalance`: `quantity` → `'decimal:3'`, `status` → `StockBalanceStatusEnum::class`
- `InventoryStockCard`: `quantity_before`, `quantity_change`, `quantity_after` → `'decimal:3'`, `movement_type` → `StockMovementTypeEnum::class`, `posted_at` → `'datetime'`
- `InventoryTransfer`: `status` → `TransferStatusEnum::class`, timestamp columns → `'datetime'`
- `InventoryAdjustment`: `adjustment_type` → `AdjustmentTypeEnum::class`, `status` → `AdjustmentStatusEnum::class`

**Key relationships:**
- `InventoryItem` hasMany `InventoryStockBalance`, hasMany `InventoryStockCard`
- `InventoryItem` belongsTo `InventoryCategory`, belongsTo `InventoryUnit`
- `InventoryLocation` hasMany `InventoryStockBalance`
- `InventoryTransfer` hasMany `InventoryTransferLine`, belongsTo `InventoryLocation` (from + to)
- `InventoryAdjustment` hasMany `InventoryAdjustmentLine`, belongsTo `InventoryLocation`

**Estimated effort:** 2-3 hours
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
  - forItem(itemId): Collection — all locations for an item
  - forLocation(locationId): Collection — all items at a location
  - findOrCreate(itemId, locationId): InventoryStockBalance
  - updateBalance(id, quantity, status, lastMovementAt): void

StockCardRepository.php
  - paginate(filters: item_id, location_id, movement_type, date_from, date_to)
  - forItem(itemId, limit=20): Collection
  - create(array $data): InventoryStockCard  — ONLY creation, never update/delete
  - recent(limit=20): Collection

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

**Estimated effort:** 3-4 hours
**Risk:** Low — established repository patterns

---

## Phase 5 — Services

**Path:** `Modules/Operations/Inventory/Services/`

**Files to create (4 services):**

### `InventoryItemService.php`
Simple CRUD delegation to ItemRepository. Event dispatch on create.

### `StockMovementService.php`
**Core service.** Handles all stock balance changes.

```
postMovement(
  itemId, locationId, movementType, quantityChange,
  referenceType, referenceId, remarks, postedBy
): InventoryStockCard

  1. DB::transaction():
     a. Lock balance row with lockForUpdate() → prevents race conditions
     b. Validate: no negative balance (quantityChange + currentBalance >= 0)
     c. Compute quantity_before, quantity_after
     d. Create StockCard entry
     e. Update or create StockBalance record
     f. Recompute and save balance status
  2. Return StockCard

postReceipt(receipt): void
  → calls postMovement() for each line with 'purchase_receipt'

postIssue(issue): void
  → calls postMovement() for each line with 'issue'
  → validates BR-001 (no negative) per line before any writes

postOpeningBalance(itemId, locationId, quantity): InventoryStockCard
  → validates: current balance = 0 (BR-041)
  → calls postMovement() with 'opening_balance'
```

### `TransferService.php`

```
create(data): InventoryTransfer
submit(transferId): InventoryTransfer
  → status.canTransitionTo(Submitted)
  → validates at least one line exists

complete(transferId, completedBy): InventoryTransfer
  → DB::transaction():
    a. status.canTransitionTo(Completed)
    b. For each line:
       - Validate sufficient stock at from_location (BR-023)
    c. For each line (all passed):
       - StockMovementService::postMovement() → transfer_out at from_location
       - StockMovementService::postMovement() → transfer_in at to_location
    d. Update transfer: status = Completed, completed_by, completed_at
  → event(TransferCompleted)

cancel(transferId, cancelledBy): InventoryTransfer
  → Draft or Submitted only
  → No stock changes
```

### `AdjustmentService.php`

```
create(data): InventoryAdjustment
  → Capture quantity_system snapshot from current balances for each line
  → Compute and store quantity_variance for each line

submit(adjustmentId): InventoryAdjustment
  → status.canTransitionTo(Submitted)

approve(adjustmentId, approvedBy): InventoryAdjustment
  → DB::transaction():
    a. status.canTransitionTo(Approved)
    b. For each line where variance ≠ 0:
       - If variance < 0: validate balance >= |variance| (BR-035)
    c. For each line where variance > 0:
       - StockMovementService::postMovement() → adjustment_in
    d. For each line where variance < 0:
       - StockMovementService::postMovement() → adjustment_out
    e. Update adjustment: status = Approved, approved_by, approved_at
  → event(AdjustmentApproved)

reject(adjustmentId, rejectedBy, reason): InventoryAdjustment
  → No stock changes
```

**Estimated effort:** 5-7 hours
**Risk:** Medium — StockMovementService transaction logic requires careful testing. Use `lockForUpdate()` on the balance row to prevent race conditions.

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
```

**Estimated effort:** 1-2 hours
**Risk:** Low

---

## Phase 7 — Form Requests

**Path:** `Modules/Operations/Inventory/Http/Requests/`

**Files to create (14 requests):**

```
Category:   StoreCategoryRequest, UpdateCategoryRequest
Unit:       StoreUnitRequest, UpdateUnitRequest
Item:       StoreItemRequest, UpdateItemRequest
Location:   StoreLocationRequest, UpdateLocationRequest
Transfer:   StoreTransferRequest, UpdateTransferRequest,
            SubmitTransferRequest, CompleteTransferRequest, CancelTransferRequest
Adjustment: StoreAdjustmentRequest, UpdateAdjustmentRequest,
            SubmitAdjustmentRequest, ApproveAdjustmentRequest, RejectAdjustmentRequest

Receipt (optional, could be inline in controller):
  StoreReceiptRequest

Issue (optional):
  StoreIssueRequest
```

See `inventory-api-spec.md` for rule details per request.

**Estimated effort:** 2-3 hours
**Risk:** Low

---

## Phase 8 — API Resources

**Path:** `Modules/Operations/Inventory/Http/Resources/`

**Files to create (8 resources):**

```
InventoryCategoryResource.php
InventoryUnitResource.php
InventoryItemResource.php
InventoryLocationResource.php
InventoryStockBalanceResource.php
StockCardResource.php
InventoryTransferResource.php
  └── InventoryTransferLineResource.php
InventoryAdjustmentResource.php
  └── InventoryAdjustmentLineResource.php
```

All enums formatted as `['value' => $enum->value, 'label' => $enum->label()]`.
All relations loaded conditionally via `$this->whenLoaded()`.

**Estimated effort:** 2-3 hours
**Risk:** Low

---

## Phase 9 — Controllers

**Path:** `Modules/Operations/Inventory/Http/Controllers/`

**Files to create (10 controllers):**

```
InventoryDashboardController.php
CategoryController.php           (resource)
UnitController.php               (resource)
ItemController.php               (resource)
LocationController.php           (resource)
StockCardController.php          (index, forItem — read only)
ReceiptController.php            (index, create, store, show)
IssueController.php              (index, create, store, show)
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
    - opening balance creates stock card
    - receipt increases balance
    - issue decreases balance
    - negative stock rejected (BR-001)
    - transaction rollback on partial failure
    - balance status computed correctly
  TransferControllerTest.php
    - create draft with lines
    - submit draft
    - complete transfer (stock moves)
    - cancel from draft
    - cancel from submitted
    - cannot cancel completed
    - insufficient stock rejected at completion
  AdjustmentControllerTest.php
    - create draft (captures quantity_system snapshot)
    - submit draft
    - approve (stock changes for non-zero variance)
    - reject (no stock changes)
    - zero-variance line skips stock card
    - negative variance with insufficient stock rejected
  StockCardControllerTest.php
    - read-only (no create/edit/delete in API)
```

**Estimated test count:** ~100-130 tests, ~280-340 assertions

**Estimated effort:** 6-8 hours
**Risk:** Medium — StockMovementService has complex transaction logic that needs thorough coverage. Race condition testing is important but harder in unit tests; document as a known gap.

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
Items/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
Locations/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
StockCards/Index.tsx
Receipts/Index.tsx, Create.tsx, Show.tsx
Issues/Index.tsx, Create.tsx, Show.tsx
Transfers/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
Adjustments/Index.tsx, Create.tsx, Edit.tsx, Show.tsx
```

Plus:
- Update `AppLayout.tsx` — add Inventory section to sidebar
- Update `Types/index.ts` — add Inventory TypeScript interfaces

See `inventory-ui-guidelines.md` for complete UI patterns.

**Estimated effort:** 8-10 hours
**Risk:** Low for standard CRUD pages. Medium for multi-line form pages (Receipts, Issues, Transfers, Adjustments) due to dynamic line management.

---

## Total Estimates

| Phase | Hours |
|---|---|
| 1 Enums | 1-2 |
| 2 Migrations | 2-3 |
| 3 Models | 2-3 |
| 4 Repositories | 3-4 |
| 5 Services | 5-7 |
| 6 Policies | 1-2 |
| 7 Form Requests | 2-3 |
| 8 Resources | 2-3 |
| 9 Controllers | 4-5 |
| 10 Tests | 6-8 |
| 11 Seeders | 1-2 |
| 12 UI | 8-10 |
| **Total** | **37-52 hours** |

---

## Risks

### High

**Race Conditions on Stock Balance** (Phase 5)
Two concurrent requests that issue the same item from the same location could both read the same balance and both succeed, resulting in negative stock. Mitigated by `lockForUpdate()` on the balance row within the transaction. Must be tested explicitly.

### Medium

**Multi-Line Transaction Complexity** (Phase 5, 9)
Transfer completion and adjustment approval apply N stock movements in one transaction. If movement N fails (e.g., insufficient stock discovered mid-way), all preceding movements must roll back. The service validates all lines before any writes to prevent mid-transaction failures.

**Test Volume** (Phase 10)
Inventory has more complex business logic than PMS due to the double-entry stock card pattern. Test count will be higher than previous modules.

### Low

**Decimal Precision Display** (Phase 12)
Quantities stored as `decimal(10,3)` should display without trailing zeros for integer units (5 not 5.000) but with appropriate precision for metric units (2.500 kg). A utility function `formatQty(qty, unit)` should be added to the UI.

**Migration Dependency Order** (Phase 2)
Items depend on categories and units. Balances and cards depend on items and locations. The migration timestamp prefix must ensure correct ordering.

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
