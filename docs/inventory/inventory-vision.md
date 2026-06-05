# Inventory Module — Vision

## Purpose

The Inventory module is the central stock management system for IVORQ. It provides a single source of truth for all physical consumables, supplies, spare parts, and materials used across every operational department of a property.

Every department that moves physical stock routes through Inventory:

| Department | Example Usage |
|---|---|
| Housekeeping | Cleaning chemicals, linen, amenities |
| Engineering | Spare parts, tools, lubricants, consumables |
| PMS / Front Desk | Minibar items, welcome amenity kits |
| Procurement (future) | Purchase orders, supplier receipts |
| Accounting (future) | Cost of goods consumed, stock valuation |

---

## Design Principles

**Single Ledger**
Every stock movement — receipt, issue, transfer, adjustment — creates an immutable stock card entry. The current balance is always the sum of all stock card entries for that item at that location. The stock card cannot be edited or deleted.

**Multi-Location**
Stock is tracked per item per location. A property can have multiple locations (Main Store, Engineering Store, Housekeeping Store, Minibar Store, Laundry Store). Transfers between locations are tracked as a pair of movements (Transfer Out, Transfer In).

**Property Isolation**
Every record is scoped to a property. The `BelongsToProperty` trait and `CurrentPropertyService` enforce this identically to how PMS, Housekeeping, and Engineering do it.

**No Negative Stock**
The system does not allow a stock balance to go negative. Any operation that would result in a negative balance is rejected before execution.

**Audit First**
Every stock movement carries: who did it, when, from what balance, to what balance, and why. Nothing is silent.

**Decoupled Integration**
In V1, Inventory is a standalone module. Other modules (Engineering Work Orders, Housekeeping Tasks, PMS Folios) integrate in V2 via a reference_type / reference_id pattern on the stock card — the same pattern used by Engineering WorkOrder location tracking.

---

## Scope

### V1 (This Sprint)

- Category master data management
- Unit of measure master data management
- Item master data management
- Location management
- Opening balance initialization
- Manual stock receipts
- Manual stock issues
- Stock transfers between locations
- Stock adjustments (stock take, damaged, lost, found, correction)
- Immutable stock card ledger
- Stock balance dashboard per location
- Low-stock and out-of-stock reporting
- Permission-based access control

### V2 (Future)

- Engineering Work Order → auto-consume spare parts
- Housekeeping Task → auto-consume cleaning chemicals
- PMS Folio → auto-post minibar consumption charges
- Procurement → supplier purchase orders
- Accounting → cost of goods consumed
- Barcode scanning
- Supplier master data
- Purchase price tracking
- Valuation methods (FIFO, Weighted Average)

---

## Placement in Module Hierarchy

```
Modules/
├── Foundation/           (authentication, users, roles, properties)
└── Operations/
    ├── Zoning/
    ├── Housekeeping/
    ├── Engineering/
    ├── PMS/
    └── Inventory/        ← new module
```

The module registers as `InventoryServiceProvider` in `config/app.php`, alongside `PMSServiceProvider`, `HousekeepingServiceProvider`, and `EngineeringServiceProvider`.

---

## Sidebar Placement

Inventory is an Operations module. In the AppLayout sidebar it appears as a new section below PMS, following the same sidebar pattern:

```
Foundation
  Dashboard
  Properties
  ...

Housekeeping
  Dashboard
  Rooms
  Tasks
  ...

PMS
  Dashboard
  Guests
  ...

Inventory             ← new section
  Dashboard
  Items
  Locations
  Transfers
  Adjustments
  Categories
  Units
```

Visibility gated by `inventory.view` permission using the same `can()` check pattern as PMS.
