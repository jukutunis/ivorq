# IVORQ Inventory Foundation v2.4 Revision 1.1 — Implementation Plan

**Role:** Enterprise Inventory Architect / Procurement Architect
**Module:** Inventory Foundation
**Status:** READY FOR FINAL CTO LOCK

---

## 1. Updated Architecture Review
The Inventory Foundation is the central operational stock backbone of IVORQ. It explicitly separates the *ownership* of physical goods from their *consumption* by other operational domains (Engineering, Housekeeping). 

**Key Principles:**
- **Inventory owns stock.** It tracks physical locations, quantities, valuations, tooling, conversions, and supplier metadata.
- **Work Orders / PMs consume stock.** They request reservations and log consumption events, but the Inventory module executes the mathematical deductions and triggers timeline events.

The architecture supports highly distributed environments: main storerooms, sub-storerooms, mobile carts, and technician vehicles.

---

## 2. Updated Domain Relationships
The system relies on strict modular boundaries.

### Core Inventory Entities
- **InventoryItem & InventoryType:** Defines the master item (Consumable, Spare Part, Tool, Chemical, Amenity, Linen, etc.) and criticality level (Critical, High, Medium, Low).
- **InventoryCategory:** Taxonomy of items.
- **InventoryLocation:** The specific physical or virtual bin (Storeroom, Technician Vehicle).
- **InventoryStock:** The pivot representing Quantity of `InventoryItem` at `InventoryLocation`.
- **InventoryTransaction:** The immutable, partitioned ledger of every stock movement.
- **InventorySupplierLink:** Links an item to preferred vendors with cost/lead time.
- **Multi-UOM Engine:** Manages `Purchase UOM`, `Storage UOM`, `Issue UOM`, and the Conversion Matrix.
- **Batch & Expiry Engine:** Tracks Batch Number, Lot Number, Mfg Date, Expiry Date, and handles proximity alerts.
- **Tool Management Engine:** Handles Checkout, Return, Assignment, Calibration, Inspection, and Damage/Loss tracking for reusable items.

### Cross-Domain Contracts
- **WorkOrderMaterial:** Link between a WO and `InventoryConsumption` / `InventoryReservation`.
- **InventoryAvailabilityCheck:** Synchronous check preventing over-allocation.

---

## 3. Updated Business Rules & Stock Movements

### Inventory Structure & Stock States
- **Available Stock:** Actual physical stock minus Reserved stock.
- **Reserved Stock:** Stock allocated to an open Work Order but not yet consumed. Reduces Available Stock immediately.
- **Physical Stock:** Total count of items on the shelf.

### Multi-UOM Conversion Matrix
- **Purchase UOM:** How it is bought (e.g., 20 Liter drum, 100 Meter Roll).
- **Storage UOM:** How it is stocked (e.g., Liters, Meters).
- **Issue UOM:** How it is consumed (e.g., 500 ml, 5 Meters).
- The conversion matrix handles rounding and validation natively during receive/issue events.

### Tool Management Workflow
- Tools (e.g., Multimeter, Drill) use a distinct lifecycle from consumables.
- Handled via `Tool Checkout` and `Tool Return` transactions.
- Integrates with PM for `Tool Calibration` and `Tool Inspection`.
- Supports `Tool Lost Report` and `Tool Damage Report`.

### Valuation Method (Finance Integration Prep)
- **Weighted Average** is the mandatory, locked default for cost valuation.
- FIFO logic is supported structurally but defaults to Weighted Average to drive operational consumption cost tracking without full accounting module dependencies.

### Batch & Expiry Management
- Items tagged with Expiry logic (Chemicals, Medicine, Food, Amenities) must log Batch/Lot info upon receipt.
- The system automatically triggers `Near Expiry Alert` and `Expiry Alert`.

### Stock Movements
Every mutation to `InventoryStock` MUST write an immutable record to `InventoryTransaction` denoting the movement type:
1. **Receive:** Increases stock.
2. **Issue:** Decreases stock.
3. **Transfer:** Moves stock.
4. **Adjustment / Write Off:** Arbitrary correction.
5. **Consumption:** Execution of an `Issue` tied to an operational action.
6. **Return / Tool Return:** Reversal of an Issue.
7. **Checkout:** Temporary assignment of a Tool.

---

## 4. Updated Dependency Matrix
| Dependency | Type | Direction | Reason |
| :--- | :--- | :--- | :--- |
| **Location** | Hard | Upstream | Defines physical Storerooms/Bins. |
| **Department** | Hard | Upstream | Defines cost centers for issuance. |
| **Media** | Hard | Upstream | Delivery Notes, Invoices, Receiving Evidence photos. |
| **Timeline** | Hard | Upstream | Unified event tracking for (Receive, Issue, Adjust, Count). |
| **Work Order** | Contract | Downstream | Consumes items, reserves stock. |
| **Preventive Maint.** | Contract | Downstream | Requires PM Kits, Scheduled Parts. |

---

## 5. CTO Decisions Applied

### Decision #1: Inventory Transaction Partitioning
- **MANDATORY:** `InventoryTransaction` will be partitioned natively in PostgreSQL by Year/Month from Day 1 to guarantee scale.

### Decision #2: Offline Blind Count
- **ALLOWED:** The system supports a 4-step workflow: `Draft Count` (saved locally in IndexedDB) -> `Sync` (push to server) -> `Submit` (finalize count) -> `Approval` (variance review).

### Decision #3: Inventory Reservation
- **RESERVED STOCK REDUCES AVAILABLE STOCK.** If Physical = 10 and Reserved = 4, the Available stock pool mathematically equals 6 for future checks.

---

## 6. Housekeeping Readiness
The foundation is structurally designed to support the future Housekeeping module:
- **InventoryType:** Pre-loaded with Amenity, Linen, Guest Supplies, Cleaning Chemicals, Uniforms, and Laundry Consumables.
- **Batch/Expiry:** Handles shelf-life tracking for cleaning chemicals and guest room perishables.
- **Multi-UOM:** Supports buying bulk liquid chemicals and issuing via pump/spray bottles.

---

## 7. Procurement Readiness
The system natively prepares the pipeline for a full Purchasing module:
- **Supplier Link:** Tracks preferred vendors, lead times, last purchase date, and last purchase cost.
- **Reorder Engine:** Continuously tracks Min Stock, Max Stock, Safety Stock, and Lead Time Calculations to generate an `Auto Purchase Recommendation`.
- **Workflows:** Ready for Purchase Requisition, Purchase Order, Vendor Comparison, and Receiving workflows.

---

## 8. Finance Readiness
**Strict Directive:** DO NOT IMPLEMENT ACCOUNTING LOGIC HERE.
- **Preparation:** The structure exposes strict integration contracts for Inventory Valuation, Consumption Cost, Stock Cost, Variance Cost, and the future Inventory Subledger.
- **Method:** Driven strictly by Weighted Average cost calculations frozen at the transaction timestamp.

---

## 9. Scalability & Performance Recommendations
- **Partitioning:** Partition `InventoryTransaction` by Year/Month.
- **Indexing:** B-Tree indices on `(property_id, item_id, location_id)` for sub-millisecond stock availability checks.
- **Async Processing:** Reorder point calculations and Expiry alert sweeps must be handled via queued background jobs, not synchronous HTTP requests.

---

## 10. Mobile Recommendations (PWA First)
- **Hardware Integration:** Barcode Scan and QR Scan (`ivorq://inventory/{ulid}`) are mandatory for receiving, issuing, and counting workflows.
- **Offline Mode:** The Blind Count Draft feature utilizes IndexedDB, enforcing a "First Sync Wins" conflict resolution strategy.

---

## 11. Risk Matrix
| Risk | Probability | Impact | Mitigation |
| :--- | :--- | :--- | :--- |
| UOM Conversion Drift | Low | High | Strict mathematical validation on the Conversion Matrix ensuring inverse relationships hold without floating-point errors. |
| Valuation Mismatch | Low | Critical | Lock `cost_basis` at the transaction layer. Never allow historical edits to past `InventoryTransaction` rows. |
| Offline Count Conflict | Medium | Medium | Implement Strict timestamp validation during the Draft Count -> Sync phase. |

---

## 12. Sprint Readiness
| Phase | Status |
| :--- | :--- |
| Governance Protocols Reviewed | **Yes** |
| Architecture Documented | **Yes** |
| CTO Decisions Applied | **Yes** |
| Cross-Module Contracts Defined | **Yes** |

**Status:** READY FOR FINAL CTO LOCK
