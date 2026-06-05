# Inventory Module — UI Guidelines

## Design Contract

All Inventory UI follows the exact same patterns as PMS and Housekeeping. No new libraries. No new design patterns. Every component mirrors existing implementations.

Core contracts:
- Use `AppLayout` for all authenticated pages
- Use `Link` and `router` from `@inertiajs/react`
- Use `useForm` from `@inertiajs/react` for forms
- Use `axios` + `router.reload()` for action buttons (confirm, approve, etc.)
- Tailwind only — no additional CSS

---

## Sidebar Integration

Add an Inventory section to `AppLayout.tsx` below PMS. The section is hidden if the user lacks `inventory.view`.

```tsx
{showInventory && (
  <>
    <SectionLabel label="Inventory" />
    <NavItem
      href="/operations/inventory"
      label="Dashboard"
      active={is('/operations/inventory')}
    />
    <SubNavItem href="/operations/inventory/items"       label="Items"       active={starts('/operations/inventory/items')} />
    <SubNavItem href="/operations/inventory/locations"   label="Locations"   active={starts('/operations/inventory/locations')} />
    <SubNavItem href="/operations/inventory/transfers"   label="Transfers"   active={starts('/operations/inventory/transfers')} />
    <SubNavItem href="/operations/inventory/adjustments" label="Adjustments" active={starts('/operations/inventory/adjustments')} />
    {can('inventory.category.manage') && (
      <SubNavItem href="/operations/inventory/categories" label="Categories" active={starts('/operations/inventory/categories')} />
    )}
    {can('inventory.unit.manage') && (
      <SubNavItem href="/operations/inventory/units" label="Units" active={starts('/operations/inventory/units')} />
    )}
  </>
)}
```

`showInventory` is true if user has `inventory.view`.

---

## Page Structure

### Dashboard — `Operations/Inventory/Dashboard/Index.tsx`

```
AppLayout
├── Header row (flex items-center justify-between)
│   ├── h1 "Inventory"  +  p "Stock management overview"
│   └── Action buttons (flex flex-wrap gap-2)
│       ├── "New Receipt"
│       ├── "New Issue"
│       ├── "New Transfer"
│       └── "New Adjustment"
│
├── Stats (grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8)
│   ├── StatCard "Total Items"      → /items
│   ├── StatCard "Low Stock"        → /items?status=low_stock   (colorClass: text-yellow-600)
│   ├── StatCard "Out of Stock"     → /items?status=out_of_stock (colorClass: text-red-600)
│   └── StatCard "Pending Transfers" → /transfers?status=submitted (colorClass: text-blue-600)
│
└── Tables (grid grid-cols-1 lg:grid-cols-2 gap-6)
    ├── Low Stock Items
    │   ├── Header: "Low Stock Items" | Link "View all →"
    │   └── Table: Item | Category | Location | Qty | Reorder Pt
    │
    ├── Recent Movements
    │   ├── Header: "Recent Movements" | Link "View all →"
    │   └── Table: Item | Type | Change | Location | Who | When
    │
    └── Pending Transfers (full width below)
        ├── Header: "Pending Transfers" | Link "View all →"
        └── Table: Number | From → To | Lines | Submitted By | Date
```

StatCard component is identical to PMS Dashboard.

---

### Index Pages

All index pages follow this structure (identical to PMS):

```
AppLayout
├── Header (flex items-center justify-between mb-6)
│   ├── h1 "Items" + p "N items total"
│   └── flex flex-wrap gap-2
│       ├── Link to Dashboard
│       └── Link "New Item" (primary button)
│
├── Filters (flex flex-wrap gap-3 mb-4)
│   └── select and/or input elements
│
└── div.bg-white.rounded-lg.shadow.overflow-hidden
    ├── Empty state (px-6 py-12 text-center text-gray-400 text-sm)
    ├── div.overflow-x-auto → table.w-full.text-sm (when data exists)
    └── Pagination row
```

#### Items Index columns:
Code | Name | Category | Unit | Low Stock | Out of Stock | Status | View

Status badge:
```tsx
const classes = {
  in_stock:     'bg-green-100 text-green-700',
  low_stock:    'bg-yellow-100 text-yellow-700',
  out_of_stock: 'bg-red-100 text-red-700',
};
```

Note: "Status" here is the worst status across all locations (if any location is out_of_stock, item shows out_of_stock). Computed at the controller level.

#### Locations Index columns:
Code | Name | Type | Items (count) | Status | View

#### Transfers Index columns:
Number | From | To | Status | Lines | Requested By | Date | View

#### Adjustments Index columns:
Number | Location | Type | Status | Lines | Submitted By | Date | View

#### Stock Cards Index columns:
Date | Item | Location | Type | Before | Change | After | Reference | Who

---

### Show Pages

Show pages follow the PMS Reservation Show pattern:

```
AppLayout
├── ← Back breadcrumb
├── Header (flex items-center justify-between mb-6)
│   ├── h1 (item code / transfer number / etc.) + status badge
│   └── Edit button (when editable)
│
├── Details Card (bg-white rounded-lg shadow p-6 mb-6)
│   ├── h2.text-sm.font-semibold.text-gray-500.uppercase.tracking-wider.mb-4
│   └── grid.grid-cols-2.gap-6.md:grid-cols-4
│       └── label + value pairs
│
├── Actions Card (when applicable)
│   └── flex flex-wrap gap-3 (same as PMS Front Desk Actions)
│
└── Lines/Movements Card
    ├── Header with count badge
    └── div.overflow-x-auto → table (same pattern)
```

#### Item Show page sections:
1. Item Details (name, code, category, unit, sku, barcode, min_stock, reorder_point)
2. Stock Balances by Location (table: location, qty, status, last_movement_at)
3. Recent Stock Cards (last 20 movements)

#### Transfer Show page sections:
1. Transfer Details (from, to, status, requested_by, notes)
2. Front Desk Actions (Submit / Complete / Cancel buttons — gated by status and permission)
3. Transfer Lines table (item, qty_requested, notes)
4. Stock Card Entries (after completion)

#### Adjustment Show page sections:
1. Adjustment Details (location, type, status, reason)
2. Actions (Submit / Approve / Reject — gated by status and permission)
3. Adjustment Lines table (item, qty_system, qty_actual, variance, notes)
4. Stock Card Entries (after approval)

---

### Create / Edit Pages

```
AppLayout
├── ← Back breadcrumb
├── h1 "New Item" / "Edit Item"
└── form.max-w-2xl (onSubmit → post / put)
    └── div.bg-white.rounded-lg.shadow.p-6.space-y-5
        ├── Form fields
        └── Submit button (disabled when processing)
```

Form fields use the Housekeeping standard:
- Labels: `text-sm font-medium text-gray-700 mb-1`
- Inputs: `border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500`
- Errors: `text-red-600 text-xs mt-1`
- Required asterisk: `<span className="text-red-500">*</span>`

---

### Multi-Line Form (Receipts, Issues, Transfers, Adjustments)

These forms have a header section plus a dynamic lines section.

```
AppLayout
├── ← Back
├── h1 "New Transfer"
└── form.max-w-4xl
    ├── Header Card (location selects, notes)
    └── Lines Card
        ├── h2 "Items"
        ├── div.overflow-x-auto → table (item select, qty input, notes, remove button)
        ├── "Add Item" button (appends a new empty line)
        └── Submit button
```

Line management pattern (add/remove rows):
```tsx
const [lines, setLines] = useState([{ item_id: '', quantity: '', notes: '' }]);

function addLine() {
  setLines([...lines, { item_id: '', quantity: '', notes: '' }]);
}

function removeLine(index: number) {
  setLines(lines.filter((_, i) => i !== index));
}
```

---

## Status Badge Colors

### StockBalanceStatusEnum
```tsx
const balanceStatusClasses = {
  in_stock:     'bg-green-100 text-green-700',
  low_stock:    'bg-yellow-100 text-yellow-700',
  out_of_stock: 'bg-red-100 text-red-700',
};
```

### TransferStatusEnum
```tsx
const transferStatusClasses = {
  draft:     'bg-gray-100 text-gray-600',
  submitted: 'bg-blue-100 text-blue-700',
  completed: 'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-700',
};
```

### AdjustmentStatusEnum
```tsx
const adjustmentStatusClasses = {
  draft:     'bg-gray-100 text-gray-600',
  submitted: 'bg-blue-100 text-blue-700',
  approved:  'bg-green-100 text-green-700',
  rejected:  'bg-red-100 text-red-700',
  cancelled: 'bg-gray-100 text-gray-500',
};
```

### StockMovementTypeEnum
```tsx
const movementTypeClasses = {
  opening_balance:  'bg-gray-100 text-gray-600',
  purchase_receipt: 'bg-green-100 text-green-700',
  issue:            'bg-red-100 text-red-700',
  transfer_out:     'bg-orange-100 text-orange-700',
  transfer_in:      'bg-blue-100 text-blue-700',
  adjustment_in:    'bg-teal-100 text-teal-700',
  adjustment_out:   'bg-yellow-100 text-yellow-700',
  return:           'bg-purple-100 text-purple-700',
};
```

Variance display (adjustment lines):
```tsx
const varianceColor = (variance: number) =>
  variance > 0 ? 'text-teal-700' : variance < 0 ? 'text-red-600' : 'text-gray-400';
```

---

## Mobile Considerations

- All index page filter bars: `flex flex-wrap gap-3`
- All header action areas: `flex flex-wrap gap-2`
- All tables in index pages: `div.overflow-x-auto` wrapper
- All inline tables in show pages: `div.overflow-x-auto` wrapper
- Multi-line forms: on mobile the line table scrolls horizontally
- Sidebar: hidden on screens below `md` breakpoint (same as current AppLayout)

---

## TypeScript Types to Add to `resources/js/Types/index.ts`

```ts
// ── Inventory ─────────────────────────────────────────────────────────────

export interface InventoryCategory {
  id: string;
  property_id: string;
  category_code: string;
  name: string;
  description: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface InventoryUnit {
  id: string;
  property_id: string;
  unit_code: string;
  abbreviation: string;
  name: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface InventoryItem {
  id: string;
  property_id: string;
  item_code: string;
  name: string;
  description: string | null;
  category: InventoryCategory;
  unit: InventoryUnit;
  sku: string | null;
  barcode: string | null;
  min_stock: number;
  max_stock: number | null;
  reorder_point: number;
  reorder_quantity: number;
  is_active: boolean;
  notes: string | null;
  created_at: string;
  updated_at: string;
  balances?: InventoryStockBalance[];
}

export interface InventoryLocation {
  id: string;
  property_id: string;
  location_code: string;
  name: string;
  description: string | null;
  location_type: EnumOption;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface InventoryStockBalance {
  id: string;
  item_id: string;
  location: InventoryLocation;
  quantity: number;
  status: EnumOption;
  last_movement_at: string | null;
  item?: InventoryItem;
}

export interface StockCard {
  id: string;
  property_id: string;
  item: { id: string; item_code: string; name: string };
  location: { id: string; name: string };
  movement_type: EnumOption;
  quantity_before: number;
  quantity_change: number;
  quantity_after: number;
  reference_type: string | null;
  reference_id: string | null;
  remarks: string | null;
  posted_by: { id: string; name: string };
  posted_at: string;
}

export interface InventoryTransfer {
  id: string;
  property_id: string;
  transfer_number: string;
  from_location: InventoryLocation;
  to_location: InventoryLocation;
  status: EnumOption;
  notes: string | null;
  lines?: InventoryTransferLine[];
  lines_count?: number;
  requested_by: { id: string; name: string } | null;
  approved_by: { id: string; name: string } | null;
  approved_at: string | null;
  completed_by: { id: string; name: string } | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface InventoryTransferLine {
  id: string;
  item: InventoryItem;
  quantity_requested: number;
  notes: string | null;
}

export interface InventoryAdjustment {
  id: string;
  property_id: string;
  adjustment_number: string;
  location: InventoryLocation;
  adjustment_type: EnumOption;
  status: EnumOption;
  reason: string;
  lines?: InventoryAdjustmentLine[];
  submitted_by: { id: string; name: string } | null;
  submitted_at: string | null;
  approved_by: { id: string; name: string } | null;
  approved_at: string | null;
  rejected_by: { id: string; name: string } | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface InventoryAdjustmentLine {
  id: string;
  item: InventoryItem;
  quantity_system: number;
  quantity_actual: number;
  quantity_variance: number;
  notes: string | null;
}
```
