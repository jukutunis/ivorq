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

Receipts are draft-then-post documents. Creation makes a draft; a separate post action applies stock movements. No edit after posting. Corrections via Adjustment.

```
GET    /operations/inventory/receipts
       → ReceiptController@index
       → name: operations.inventory.receipts.index
       → Inertia: Operations/Inventory/Receipts/Index
       → Props: receipts (paginated), statuses, filters
       → Filters: status, supplier_name, date_from, date_to

GET    /operations/inventory/receipts/create
       → ReceiptController@create
       → name: operations.inventory.receipts.create
       → Inertia: Operations/Inventory/Receipts/Create
       → Props: locations (active), items (active, with balances)

POST   /operations/inventory/receipts
       → ReceiptController@store
       → name: operations.inventory.receipts.store
       → Request: StoreReceiptRequest
       → Creates Draft receipt with lines
       → Redirect: receipts.show

GET    /operations/inventory/receipts/{receipt}
       → ReceiptController@show
       → name: operations.inventory.receipts.show
       → Inertia: Operations/Inventory/Receipts/Show
       → Props: receipt (with lines, stock_card_entries when posted)

GET    /operations/inventory/receipts/{receipt}/edit
       → ReceiptController@edit
       → name: operations.inventory.receipts.edit
       → Only allowed when status = draft
       → Inertia: Operations/Inventory/Receipts/Edit

PUT    /operations/inventory/receipts/{receipt}
       → ReceiptController@update
       → name: operations.inventory.receipts.update
       → Request: UpdateReceiptRequest
       → Only allowed when status = draft

POST   /operations/inventory/receipts/{receipt}/post
       → ReceiptController@post
       → name: operations.inventory.receipts.post
       → Request: PostReceiptRequest (authorize: inventory.receive)
       → Validates: status = draft, at least one line, all quantities > 0
       → Applies stock movements, updates average_cost via WAC
       → Transitions: Draft → Posted
       → Returns JSON { message, receipt }

POST   /operations/inventory/receipts/{receipt}/cancel
       → ReceiptController@cancel
       → name: operations.inventory.receipts.cancel
       → Request: CancelReceiptRequest (authorize: inventory.receive)
       → Only allowed when status = draft
       → Transitions: Draft → Cancelled
       → Returns JSON { message, receipt }
```

---

### Issues

Issues are draft-then-post documents. Mirrors the receipt workflow. No edit after posting.

```
GET    /operations/inventory/issues
       → IssueController@index
       → name: operations.inventory.issues.index
       → Inertia: Operations/Inventory/Issues/Index
       → Props: issues (paginated), statuses, filters
       → Filters: status, department_id, date_from, date_to

GET    /operations/inventory/issues/create
       → IssueController@create
       → name: operations.inventory.issues.create
       → Inertia: Operations/Inventory/Issues/Create
       → Props: locations (active), items (active, with balances), departments

POST   /operations/inventory/issues
       → IssueController@store
       → name: operations.inventory.issues.store
       → Request: StoreIssueRequest
       → Creates Draft issue with lines
       → Redirect: issues.show

GET    /operations/inventory/issues/{issue}
       → IssueController@show
       → name: operations.inventory.issues.show
       → Inertia: Operations/Inventory/Issues/Show
       → Props: issue (with lines, stock_card_entries when posted)

GET    /operations/inventory/issues/{issue}/edit
       → IssueController@edit
       → name: operations.inventory.issues.edit
       → Only allowed when status = draft

PUT    /operations/inventory/issues/{issue}
       → IssueController@update
       → name: operations.inventory.issues.update
       → Request: UpdateIssueRequest
       → Only allowed when status = draft

POST   /operations/inventory/issues/{issue}/post
       → IssueController@post
       → name: operations.inventory.issues.post
       → Request: PostIssueRequest (authorize: inventory.issue)
       → Validates: status = draft, at least one line, sufficient stock per line (BR-001)
       → Applies stock movements with average_cost stamped as unit_cost
       → Transitions: Draft → Posted
       → Returns JSON { message, issue }

POST   /operations/inventory/issues/{issue}/cancel
       → IssueController@cancel
       → name: operations.inventory.issues.cancel
       → Request: CancelIssueRequest (authorize: inventory.issue)
       → Only allowed when status = draft
       → Transitions: Draft → Cancelled
       → Returns JSON { message, issue }
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
       → Validates ALL lines: current_balance == quantity_system snapshot (BR-065 staleness check)
       → Validates negative-variance lines: current_balance >= |variance| (BR-066)
       → Wraps in DB::transaction with lockForUpdate on balances
       → Creates adjustment_in / adjustment_out stock card entries (unit_cost = item.average_cost)
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
authorize: can('create', InventoryReceipt::class) → requires inventory.receive
rules:
  supplier_name:       nullable, string, max:255
  external_reference:  nullable, string, max:100
  received_at:         nullable, date
  remarks:             nullable, string
  lines:               required, array, min:1
  lines.*.item_id:     required, size:26, exists:inventory_items,id (property scoped, active)
  lines.*.location_id: required, size:26, exists:inventory_locations,id (property scoped, active)
  lines.*.quantity:    required, numeric, min:0.001
  lines.*.unit_cost:   required, numeric, min:0
  lines.*.notes:       nullable, string
prohibited: receipt_number, status, posted_by, posted_at
```

### PostReceiptRequest
```
authorize: can('post', InventoryReceipt::class) → requires inventory.receive
           + validates receipt.status == 'draft'
rules:     (no additional fields — post action takes no body)
```

### CancelReceiptRequest
```
authorize: can('cancel', InventoryReceipt::class) → requires inventory.receive
           + validates receipt.status == 'draft'
rules:     (no additional fields)
```

### UpdateReceiptRequest
```
authorize: can('update', InventoryReceipt::class) → requires inventory.receive
           + validates receipt.status == 'draft'
rules:     same as StoreReceiptRequest (full replacement of header + lines)
```

### StoreIssueRequest
```
authorize: can('create', InventoryIssue::class) → requires inventory.issue
rules:
  issued_to_type:      nullable, string, max:50
  issued_to_id:        nullable, size:26
  department_id:       nullable, size:26, exists:departments,id (property scoped)
  issued_at:           nullable, date
  remarks:             nullable, string
  lines:               required, array, min:1
  lines.*.item_id:     required, size:26, exists:inventory_items,id (property scoped, active)
  lines.*.location_id: required, size:26, exists:inventory_locations,id (property scoped, active)
  lines.*.quantity:    required, numeric, min:0.001
  lines.*.remarks:     nullable, string
prohibited: issue_number, status, posted_by, posted_at
```

### PostIssueRequest
```
authorize: can('post', InventoryIssue::class) → requires inventory.issue
           + validates issue.status == 'draft'
rules:     (no additional fields)
```

### CancelIssueRequest
```
authorize: can('cancel', InventoryIssue::class) → requires inventory.issue
           + validates issue.status == 'draft'
rules:     (no additional fields)
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
  "unit_cost": 2.5000,
  "total_value": 12.5000,
  "reference_type": "issue",
  "reference_id": "01J...",
  "remarks": "Issued for room 305 cleaning",
  "posted_by": { "id": "...", "name": "Jane Smith" },
  "posted_at": "2026-06-05T..."
}
```

### InventoryReceiptResource
```json
{
  "id": "01J...",
  "property_id": "01J...",
  "receipt_number": "RCT-00001",
  "supplier_name": "ABC Supplies Sdn Bhd",
  "external_reference": "PO-2026-0042",
  "status": { "value": "posted", "label": "Posted" },
  "received_at": "2026-06-05T09:00:00",
  "remarks": null,
  "posted_by": { "id": "...", "name": "John Doe" },
  "posted_at": "2026-06-05T09:15:00",
  "cancelled_by": null,
  "cancelled_at": null,
  "lines_count": 3,
  "lines": [InventoryReceiptLineResource, ...],   // whenLoaded
  "created_at": "...",
  "updated_at": "..."
}
```

### InventoryReceiptLineResource
```json
{
  "id": "01J...",
  "item": { "id": "...", "item_code": "ITM-00001", "name": "Toilet Roll 2-Ply" },
  "location": { "id": "...", "name": "Main Store" },
  "quantity": 200.000,
  "unit_cost": 1.2500,
  "total_value": 250.0000,
  "notes": null
}
```

### InventoryIssueResource
```json
{
  "id": "01J...",
  "property_id": "01J...",
  "issue_number": "ISS-00001",
  "issued_to_type": "department",
  "issued_to_id": null,
  "department": { "id": "...", "name": "Housekeeping" },
  "status": { "value": "posted", "label": "Posted" },
  "issued_at": "2026-06-05T10:00:00",
  "remarks": "Morning room replenishment",
  "posted_by": { "id": "...", "name": "Jane Smith" },
  "posted_at": "2026-06-05T10:05:00",
  "lines_count": 4,
  "lines": [InventoryIssueLineResource, ...],     // whenLoaded
  "created_at": "...",
  "updated_at": "..."
}
```

### InventoryIssueLineResource
```json
{
  "id": "01J...",
  "item": { "id": "...", "item_code": "ITM-00002", "name": "Shampoo 30ml" },
  "location": { "id": "...", "name": "Housekeeping Store" },
  "quantity": 50.000,
  "remarks": null
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

## Controller Structure (v1.1)

```
Modules/Operations/Inventory/Http/Controllers/
├── InventoryDashboardController.php    (index only)
├── CategoryController.php              (resource)
├── UnitController.php                  (resource)
├── ItemController.php                  (resource)
├── LocationController.php              (resource)
├── StockCardController.php             (index, forItem — read only)
├── ReceiptController.php               (index, create, store, show, edit, update, post, cancel)
├── IssueController.php                 (index, create, store, show, edit, update, post, cancel)
├── TransferController.php              (resource + submit, complete, cancel)
└── AdjustmentController.php            (resource + submit, approve, reject)
```

**ReceiptController and IssueController** now include:
- `edit()` — form to edit draft
- `update()` — save draft changes
- `post()` — apply stock movements (returns JSON)
- `cancel()` — cancel draft (returns JSON)

Each controller:
- Injects a Service (not a Repository) via constructor DI
- Calls `$this->authorize()` before every action
- Uses `app(CurrentPropertyService::class)->getId()` for property context
- Delegates all business logic to the service
- Returns `Inertia::render()` for page views or `response()->json()` for actions
