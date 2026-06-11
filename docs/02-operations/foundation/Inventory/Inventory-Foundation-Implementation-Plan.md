# IVORQ Inventory Foundation v2.4 — Implementation Plan

**Role:** Enterprise Inventory Architect / Procurement Architect
**Module:** Inventory Foundation
**Status:** Ready For CTO Review

---

## 1. Architecture Review
The Inventory Foundation is the central operational stock backbone of IVORQ. It explicitly separates the *ownership* of physical goods from their *consumption* by other operational domains (Engineering, Housekeeping). In this architecture:
- **Inventory owns stock.** It tracks physical locations, quantities, valuations, and supplier metadata.
- **Work Orders / PMs consume stock.** They request reservations and log consumption events, but the Inventory module executes the deduction logic and triggers timeline events.

The module supports highly distributed stock environments, accommodating main storerooms, sub-storerooms, mobile carts, and technician vehicles.

---

## 2. Domain Relationships
The system relies on strict modular boundaries and interface contracts.

### Core Inventory Entities
- **InventoryItem:** Master data definition (SKU, Name, UoM).
- **InventoryCategory:** Taxonomy of items.
- **InventoryLocation:** The specific physical or virtual bin (Storeroom, Technician Vehicle).
- **InventoryStock:** The pivot representing Quantity of `InventoryItem` at `InventoryLocation`.
- **InventoryTransaction:** The immutable ledger of every stock movement.
- **InventorySupplierLink:** Links an item to preferred vendors with cost/lead time.

### Cross-Domain Contracts
- **WorkOrderMaterial:** Link between a WO and `InventoryConsumption` / `InventoryReservation`.
- **InventoryAvailabilityCheck:** Synchronous check preventing over-allocation.
- **Future Finance Contracts:** `InventoryValuation` and `ConsumptionCost` stubs mapped for upcoming General Ledger integration. DO NOT implement accounting logic in this module.

---

## 3. Business Rules & Stock Movements

### Inventory Structure
- Stock is globally scoped by `property_id` and subsequently partitioned by `Department`.
- Stock can exist in hierarchical bins: `Main Storeroom -> Shelf B -> Bin 4` or dynamically in `Vehicle: Van 02`.

### Stock Movements
Every mutation to `InventoryStock` MUST write an immutable record to `InventoryTransaction` denoting the movement type:
1. **Receive:** Increases stock (against a Supplier Delivery Note).
2. **Issue:** Decreases stock (to a Work Order or Department).
3. **Transfer:** Moves stock between `InventoryLocation`s (e.g., Storeroom to Van).
4. **Adjustment / Write Off:** Arbitrary correction of stock levels (requires approval).
5. **Consumption:** Execution of an `Issue` strictly tied to an operational action (e.g., PM Execution).
6. **Return:** Reversal of an Issue (returning unused WO material to the Storeroom).

### Reorder & Counting Engines
- **Reorder Engine:** Continuously evaluates `Stock Level` vs `Reorder Point`. Incorporates `Safety Stock` and `Lead Time`. Triggers `Auto Purchase Recommendation`.
- **Counting Engine:** Handles Cycle Counts, Annual Audits, and Blind Counts. Any variance between counted and systemic stock creates a locked `InventoryAdjustment` requiring approval.

---

## 4. Dependency Matrix
| Dependency | Type | Direction | Reason |
| :--- | :--- | :--- | :--- |
| **Location** | Hard | Upstream | Defines physical Storerooms/Bins. |
| **Department** | Hard | Upstream | Defines cost centers for issuance. |
| **Media** | Hard | Upstream | Delivery Notes, Invoices, Receiving Evidence photos. |
| **Timeline** | Hard | Upstream | Unified event tracking for (Receive, Issue, Adjust, Count). |
| **Work Order** | Contract | Downstream | Consumes items, reserves stock. |
| **Preventive Maint.** | Contract | Downstream | Requires PM Kits, Scheduled Parts. |
| **Finance (Future)** | Contract | Downstream | Inventory Valuation, Cost Tracking. |

---

## 5. Open Questions (CTO Review Required)

> [!CAUTION]
> **Open Question 1:** Should `InventoryTransaction` utilize PostgreSQL Table Partitioning by Year/Month from Day 1, identical to `Timeline`? Given large properties cycle thousands of items daily, an unpartitioned ledger will become a bottleneck.

> [!WARNING]
> **Open Question 2:** For Blind Counts (where the counter cannot see the expected systemic quantity), should the system permit the counter to save an intermediate "Draft Count" locally via IndexedDB, or must it be pushed synchronously to the server to prevent data loss?

> [!IMPORTANT]
> **Open Question 3:** When a Work Order reserves stock, does the reserved quantity physically leave the "Available" pool globally, or is it merely mathematically restricted until actual Consumption/Issuance?

---

## 6. CTO Recommendations
1. **Scalability:** Mandate PostgreSQL Table Partitioning for `InventoryTransaction` immediately. Use B-Tree indices on `(property_id, item_id, location_id)` for the `InventoryStock` table to ensure sub-millisecond availability checks.
2. **Mobile (PWA):** Enforce an "Offline First" receive/issue capability using IndexedDB for queued transactions. Implement native OS barcode/QR scanning using the standard `ivorq://inventory/{ulid}` URI structure.
3. **Performance:** Utilize eventual consistency (background jobs) for calculating `Reorder Recommendations` instead of computing them synchronously upon every `InventoryIssue`.
4. **Data Integrity:** Prevent direct updates to the `quantity` column on `InventoryStock`. Mutations must only occur via a DB trigger or a locked Service Class that simultaneously inserts the `InventoryTransaction`.

---

## 7. Future Expansion Strategy
- **Housekeeping Preparation:** Taxonomy is structured to support Amenities, Linen, Guest Supplies, and Cleaning Chemicals via distinct Categories.
- **Finance Preparation:** Every `InventoryTransaction` requires a `cost_basis` column. When the Finance module boots, it will hook into these transactions to map operational consumption directly to Subledger Cost Centers.
- **Procurement Preparation:** The `InventorySupplierLink` explicitly tracks `Last Purchase Date` and `Lead Time` to auto-populate future Purchase Requisitions seamlessly.

---

## 8. Risk Matrix
| Risk | Probability | Impact | Mitigation |
| :--- | :--- | :--- | :--- |
| Over-allocation due to race conditions | Low | High | Use atomic DB-level decrement operators or pessimistic locking during `InventoryIssue`. |
| Mobile Offline Count Conflicts | Medium | High | "Last Write Wins" logic for cycle counts, governed by strict timestamp comparisons. |
| Financial Valuation Drift | Low | Critical | Ensure `cost_basis` is frozen at the moment of Receiving, utilizing FIFO or Weighted Average calculations. |

---

## 9. Sprint Readiness
| Phase | Status |
| :--- | :--- |
| Governance Protocols Reviewed | **Yes** |
| Architecture Documented | **Yes** |
| Database Schemas Defined | **Pending Approval** |
| Cross-Module Contracts Defined | **Yes** |

**Status:** Ready For CTO Review
