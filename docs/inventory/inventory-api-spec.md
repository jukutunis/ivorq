# Inventory Module — API Specification

All routes use `web` middleware with `auth`. All responses follow Inertia.js patterns: page routes return `Inertia::render()`, action routes return `response()->json()`. Form requests handle validation and authorization.

---

## Route Prefix and Naming

```
prefix:     operations/inventory
name:       operations.inventory.
```

---

## Routes

### Dashboard

```
GET  /operations/inventory
     → InventoryDashboardController@index
     → name: operations.inventory.dashboard
     → Inertia: Operations/Inventory/Dashboard/Index
     → Props: stats, low_stock_items, recent_movements, pending_transfers
```

---

### Categories

```
GET    /operations/inventory/categories
       → CategoryController@index
       → name: operations.inventory.categories.index
       → Inertia: Operations/Inventory/Categories/Index
       → Props: categories (paginated), filters

GET    /operations/inventory/categories/create
       → CategoryController@create
       → name: operations.inventory.categories.create
       → Inertia: Operations/Inventory/Categories/Create

POST   /operations/inventory/categories
       → CategoryController@store
       → name: operations.inventory.categories.store
       → Request: StoreCategoryRequest
       → Redirect: categories.show

GET    /operations/inventory/categories/{category}
       → CategoryController@show
       → name: operations.inventory.categories.show
       → Inertia: Operations/Inventory/Categories/Show

GET    /operations/inventory/categories/{category}/edit
       → CategoryController@edit
       → name: operations.inventory.categories.edit
       → Inertia: Operations/Inventory/Categories/Edit

PUT    /operations/inventory/categories/{category}
       → CategoryController@update
       → name: operations.inventory.categories.update
       → Request: UpdateCategoryRequest
       → Redirect: categories.show

DELETE /operations/inventory/categories/{category}
       → CategoryController@destroy
       → name: operations.inventory.categories.destroy
       → Redirect: categories.index
```

---

### Units

```
GET    /operations/inventory/units
       → UnitController@index
       → Inertia: Operations/Inventory/Units/Index

GET    /operations/inventory/units/create
GET    /operations/inventory/units/{unit}
GET    /operations/inventory/units/{unit}/edit
POST   /operations/inventory/units
PUT    /operations/inventory/units/{unit}
DELETE /operations/inventory/units/{unit}
```

---

### Items (Item Master)

```
GET    /operations/inventory/items
       → ItemController@index
       → name: operations.inventory.items.index
       → Inertia: Operations/Inventory/Items/Index
       → Props: items (paginated), categories, units, filters

GET    /operations/inventory/items/create
       → ItemController@create
       → Props: categories (active), units (active)

POST   /operations/inventory/items
       → ItemController@store
       → Request: StoreItemRequest

GET    /operations/inventory/items/{item}
       → ItemController@show
       → Props: item (with category, unit, balances per location, recent_stock_cards)

GET    /operations/inventory/items/{item}/edit
PUT    /operations/inventory/items/{item}
       → Request: UpdateItemRequest

DELETE /operations/inventory/items/{item}
```

---

### Locations

```
GET    /operations/inventory/locations
       → LocationController@index
       → Props: locations (paginated), location_types, filters

GET    /operations/inventory/locations/create
POST   /operations/inventory/locations
GET    /operations/inventory/locations/{location}
       → Props: location, balances (items at this location, paginated)
GET    /operations/inventory/locations/{location}/edit
PUT    /operations/inventory/locations/{location}
DELETE /operations/inventory/locations/{location}
```

---

### Stock Card (Ledger)

Read-only. No create/edit/delete.

```
GET  /operations/inventory/stock-cards
     → StockCardController@index
     → name: operations.inventory.stock-cards.index
     → Props: stock_cards (paginated), items, locations, movement_types, filters
     → Filters: item_id, location_id, movement_type, date_from, date_to

GET  /operations/inventory/items/{item}/stock-cards
     → StockCardController@forItem
     → Props: item, stock_cards (paginated by item, across all locations)
```

---

### Receipts

```
GET    /operations/inventory/receipts
       → ReceiptController@index
       → Props: receipts (paginated), locations, filters

GET    /operations/inventory/receipts/create
       → ReceiptController@create
       → Props: locations (active), items (active, with balances)

POST   /operations/inventory/receipts
       → ReceiptController@store
       → Request: StoreReceiptRequest
       → Posts stock card entries, updates balances
       → Redirect: receipts.show

GET    /operations/inventory/receipts/{receipt}
       → ReceiptController@show
       → Props: receipt (with lines, stock_card_entries)
```

Note: Receipts are the header record; no edit or delete after posting. Corrections via adjustment.

---

### Issues

```
GET    /operations/inventory/issues
       → IssueController@index

GET    /operations/inventory/issues/create
       → Props: locations (active), items (active, with balances)

POST   /operations/inventory/issues
       → IssueController@store
       → Request: StoreIssueRequest
       → Validates no negative stock (BR-001)
       → Redirect: issues.show

GET    /operations/inventory/issues/{issue}
```

---

### Transfers

```
GET    /operations/inventory/transfers
       → TransferController@index
       → Props: transfers (paginated), locations, statuses, filters

GET    /operations/inventory/transfers/create
       → Props: locations (active), items (active, with balances)

POST   /operations/inventory/transfers
       → TransferController@store
       → Request: StoreTransferRequest
       → Creates Draft transfer with lines
       → Redirect: transfers.show

GET    /operations/inventory/transfers/{transfer}
       → Props: transfer (with lines, from_location, to_location, events)

GET    /operations/inventory/transfers/{transfer}/edit
       → Only allowed in Draft status

PUT    /operations/inventory/transfers/{transfer}
       → Request: UpdateTransferRequest
       → Only allowed in Draft status

DELETE /operations/inventory/transfers/{transfer}
       → Only allowed in Draft status

POST   /operations/inventory/transfers/{transfer}/submit
       → TransferController@submit
       → Request: SubmitTransferRequest (authorize: inventory.transfer)
       → Transitions: Draft → Submitted
       → Returns JSON

POST   /operations/inventory/transfers/{transfer}/complete
       → TransferController@complete
       → Request: CompleteTransferRequest (authorize: inventory.approve)
       → Validates stock availability (BR-023)
       → Wraps in DB::transaction
       → Creates transfer_out and transfer_in stock card entries
       → Returns JSON

POST   /operations/inventory/transfers/{transfer}/cancel
       → TransferController@cancel
       → Request: CancelTransferRequest (authorize: inventory.transfer)
       → Allowed from Draft or Submitted
       → Returns JSON
```

---

### Adjustments

```
GET    /operations/inventory/adjustments
       → AdjustmentController@index
       → Props: adjustments (paginated), locations, types, statuses, filters

GET    /operations/inventory/adjustments/create
       → Props: locations (active), adjustment_types, items (active, with balances)

POST   /operations/inventory/adjustments
       → AdjustmentController@store
       → Request: StoreAdjustmentRequest
       → Captures quantity_system snapshot from current balances
       → Creates Draft adjustment
       → Redirect: adjustments.show

GET    /operations/inventory/adjustments/{adjustment}
       → Props: adjustment (with lines, location, events)

GET    /operations/inventory/adjustments/{adjustment}/edit
PUT    /operations/inventory/adjustments/{adjustment}
       → Only Draft status

POST   /operations/inventory/adjustments/{adjustment}/submit
       → AdjustmentController@submit
       → Transitions: Draft → Submitted
       → Returns JSON

POST   /operations/inventory/adjustments/{adjustment}/approve
       → AdjustmentController@approve
       → Request: ApproveAdjustmentRequest (authorize: inventory.approve)
       → Validates negative variance lines against current balance (BR-035)
       → Wraps in DB::transaction
       → Creates adjustment_in / adjustment_out stock card entries
       → Returns JSON

POST   /operations/inventory/adjustments/{adjustment}/reject
       → AdjustmentController@reject
       → Request: RejectAdjustmentRequest (authorize: inventory.approve)
       → Returns JSON
```

---

## Form Requests

### StoreCategoryRequest
```
authorize: can('create', InventoryCategory::class)
rules:
  name:        required, string, max:100
  description: nullable, string
  is_active:   nullable, boolean
prohibited: category_code, property_id
```

### StoreUnitRequest
```
authorize: can('create', InventoryUnit::class)
rules:
  abbreviation: required, string, max:20
  name:         required, string, max:100
  is_active:    nullable, boolean
prohibited: unit_code, property_id
```

### StoreItemRequest
```
authorize: can('create', InventoryItem::class)
rules:
  name:             required, string, max:255
  description:      nullable, string
  category_id:      required, size:26, exists:inventory_categories,id (scoped to property)
  unit_id:          required, size:26, exists:inventory_units,id (scoped to property)
  sku:              nullable, string, max:100
  barcode:          nullable, string, max:100
  min_stock:        nullable, numeric, min:0
  max_stock:        nullable, numeric, min:0
  reorder_point:    nullable, numeric, min:0
  reorder_quantity: nullable, numeric, min:0
  is_active:        nullable, boolean
  notes:            nullable, string
prohibited: item_code, property_id
```

### StoreReceiptRequest
```
authorize: can('receive', InventoryLocation::class) → requires inventory.receive
rules:
  location_id:      required, size:26, exists:inventory_locations,id (property scoped, active)
  notes:            nullable, string
  lines:            required, array, min:1
  lines.*.item_id:  required, size:26, exists:inventory_items,id (property scoped, active)
  lines.*.quantity: required, numeric, min:0.001
  lines.*.notes:    nullable, string
```

### StoreIssueRequest
```
authorize: requires inventory.issue
rules: same structure as StoreReceiptRequest but for issuing
```

### StoreTransferRequest
```
authorize: can('create', InventoryTransfer::class) → requires inventory.transfer
rules:
  from_location_id: required, size:26, exists:inventory_locations,id (active, property scoped)
  to_location_id:   required, size:26, exists:inventory_locations,id (active, property scoped)
                    → different rule: must differ from from_location_id
  notes:            nullable, string
  lines:            required, array, min:1
  lines.*.item_id:  required, size:26, exists:inventory_items,id (active, property scoped)
  lines.*.quantity: required, numeric, min:0.001
  lines.*.notes:    nullable, string
```

### StoreAdjustmentRequest
```
authorize: can('create', InventoryAdjustment::class) → requires inventory.adjust
rules:
  location_id:              required, size:26, active
  adjustment_type:          required, Rule::enum(AdjustmentTypeEnum::class)
  reason:                   required, string
  lines:                    required, array, min:1
  lines.*.item_id:          required, size:26, active
  lines.*.quantity_actual:  required, numeric, min:0
  lines.*.notes:            nullable, string
```

---

## API Resources

### InventoryItemResource
```json
{
  "id": "01J...",
  "property_id": "01J...",
  "item_code": "ITM-00001",
  "name": "Toilet Roll 2-Ply",
  "description": null,
  "category": { "id": "...", "name": "Housekeeping" },
  "unit": { "id": "...", "abbreviation": "ROLL", "name": "Roll" },
  "sku": "TR2PLY-100",
  "barcode": null,
  "min_stock": 50,
  "max_stock": 500,
  "reorder_point": 100,
  "reorder_quantity": 200,
  "is_active": true,
  "notes": null,
  "created_at": "2026-06-05T...",
  "updated_at": "2026-06-05T...",
  "balances": [InventoryStockBalanceResource, ...],  // whenLoaded
  "recent_stock_cards": [StockCardResource, ...]      // whenLoaded
}
```

### InventoryStockBalanceResource
```json
{
  "id": "01J...",
  "item_id": "01J...",
  "location": { "id": "...", "name": "Main Store", "location_type": { "value": "main_store", "label": "Main Store" } },
  "quantity": 145.000,
  "status": { "value": "in_stock", "label": "In Stock" },
  "last_movement_at": "2026-06-05T..."
}
```

### StockCardResource
```json
{
  "id": "01J...",
  "item": { "id": "...", "name": "Toilet Roll 2-Ply", "item_code": "ITM-00001" },
  "location": { "id": "...", "name": "Main Store" },
  "movement_type": { "value": "issue", "label": "Issue" },
  "quantity_before": 150.000,
  "quantity_change": -5.000,
  "quantity_after": 145.000,
  "reference_type": "issue",
  "reference_id": "01J...",
  "remarks": "Issued for room 305 cleaning",
  "posted_by": { "id": "...", "name": "Jane Smith" },
  "posted_at": "2026-06-05T..."
}
```

### TransferResource
```json
{
  "id": "01J...",
  "transfer_number": "TRN-00001",
  "from_location": { "id": "...", "name": "Main Store" },
  "to_location": { "id": "...", "name": "Engineering Store" },
  "status": { "value": "submitted", "label": "Submitted" },
  "notes": null,
  "lines_count": 3,
  "lines": [TransferLineResource, ...],
  "requested_by": { "id": "...", "name": "John Doe" },
  "approved_by": null,
  "approved_at": null,
  "completed_by": null,
  "completed_at": null,
  "created_at": "...",
  "updated_at": "..."
}
```

### AdjustmentResource
```json
{
  "id": "01J...",
  "adjustment_number": "ADJ-00001",
  "location": { "id": "...", "name": "Main Store" },
  "adjustment_type": { "value": "stock_take", "label": "Stock Take" },
  "status": { "value": "submitted", "label": "Submitted" },
  "reason": "Monthly stock take",
  "lines": [AdjustmentLineResource, ...],
  "submitted_by": { ... },
  "submitted_at": "...",
  "approved_by": null,
  "approved_at": null,
  "created_at": "...",
  "updated_at": "..."
}
```

---

## Controller Structure

```
Modules/Operations/Inventory/Http/Controllers/
├── InventoryDashboardController.php   (index only)
├── CategoryController.php             (resource)
├── UnitController.php                 (resource)
├── ItemController.php                 (resource)
├── LocationController.php             (resource)
├── StockCardController.php            (index, forItem — read only)
├── ReceiptController.php              (index, create, store, show)
├── IssueController.php                (index, create, store, show)
├── TransferController.php             (resource + submit, complete, cancel)
└── AdjustmentController.php           (resource + submit, approve, reject)
```

Each controller:
- Injects a Service (not a Repository) via constructor DI
- Calls `$this->authorize()` before every action
- Uses `app(CurrentPropertyService::class)->getId()` for property context
- Delegates all business logic to the service
- Returns `Inertia::render()` for page views or `response()->json()` for actions
