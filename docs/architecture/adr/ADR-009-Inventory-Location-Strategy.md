# ADR 009: Inventory Location Strategy

## 1. Title
ADR-009: Inventory Location Strategy

## 2. Status
Proposed

## 3. Context
Following the approval of ADR-008 (Inventory Ledger Architecture), IVORQ must define how physical and logical inventory spaces are modeled. Hospitality operations are complex, spanning central main stores, localized kitchen and bar outlets, distributed mini-bars, and specialized banquet storerooms. Furthermore, an enterprise-grade ERP must support multi-property scenarios including Central Warehouses, inter-property transfers, and transit states. A robust location strategy is paramount to ensure accurate stock balances, reliable cost valuations, and strict auditability.

## 4. Problem Statement
Without a unified location strategy, inventory balances become homogenized at the property level, blinding operators to the physical reality of stock distribution (e.g., "We have 50 bottles of wine, but are they in the Main Store or the Lobby Bar?"). Furthermore, poorly defined transfer rules between properties or outlets create untraceable variances, while calculating AVCO at the wrong hierarchical level can lead to extreme cost fluctuations during simple internal movements.

## 5. Decision
We will adopt a rigid hierarchical location model (Enterprise → Tenant → Property → Location) where every inventory transaction mandates a specific `location_id`. Stock *quantities* will be maintained precisely at the Location level, while financial *valuation* (AVCO) will be calculated and maintained at the Property level. Dedicated location types, including virtual and transit locations, will govern the behavior, status, and ownership of inventory.

## 6. Architecture Principles
1. **Explicit Location Identity:** No inventory exists in the ether. Every single unit must belong to a defined Location.
2. **Property-Level Valuation:** AVCO is calculated across the Property, not per Location. A bottle of vodka costs the Property the same regardless of whether it sits in the Main Store or the Bar.
3. **Location-Level Quantities:** Physical stock counts and ledger balances are strictly isolated per Location.
4. **Transit Enforcement:** Any physical movement crossing a geographical boundary (Inter-Property) must pass through an `IN_TRANSIT` state.
5. **Separation of Duties:** Location custodians (managers) must approve inbound transfers.

## 7. Location Hierarchy
The physical and logical structure follows:
- **Enterprise** (IVORQ Platform)
  - **Tenant** (Hotel Group)
    - **Property** (Specific Hotel / Legal Entity / Central Warehouse)
      - **Location** (Storerooms, Outlets, Transit Zones)

Every ledger entry mandates both `property_id` and `location_id`.

## 8. Location Types
Locations are strictly typed to enforce operational business rules:
- `MAIN_STORE`: Central receiving and holding.
- `OUTLET_STORE`: Point-of-sale areas (Kitchens, Bars, Retail).
- `BANQUET_STORE`: Event-specific staging areas.
- `MINI_BAR`: Distributed guest-room inventory (managed via par-level replenishment).
- `QUARANTINE`: Holding area for damaged, expired, or pending-return goods.
- `PRODUCTION`: Virtual/physical staging for recipe batching and yielding.
- `TRANSIT`: Virtual locations for moving goods across properties.
- `CONSIGNMENT`: Goods physically present but financially owned by a vendor.

## 9. Inventory Ownership Model
- **Intra-Property:** Inventory in any physical location within a Property is legally and financially owned by that Property's general ledger.
- **Inter-Property:** Inventory ownership shifts when goods cross legal entities.
- **Consignment:** Inventory resides in a Property's Location but remains financially owned by the Vendor until consumed.

## 10. Transfer Strategy
**Intra-Location (Property A → Property A):**
- Simple two-step ledger transaction: `TRANSFER_OUT` (Source) and `TRANSFER_IN` (Destination).
- Immediate financial neutrality (no AVCO impact).

**Inter-Property (Property A → Property B):**
- Requires a `TRANSIT` location.
- **Step 1:** Property A issues `TRANSFER_OUT` to Transit. (Stock status = `IN_TRANSIT`).
- **Step 2:** Property B issues `TRANSFER_IN` from Transit.
- **Financial Impact:** Triggers Intercompany AR/AP accounting if Property A and B are distinct legal entities. AVCO transfers at the sending Property's current cost.

## 11. Inventory Status Model
Rather than complex row-level lot statuses, IVORQ will utilize a combination of Location Types and Ledger Statuses:
- `AVAILABLE`: Normal operational stock.
- `IN_TRANSIT`: Stock locked in a transit location.
- `RESERVED`: Stock allocated to an approved BEO (Banquet Event Order) or Production Batch.
- `QUARANTINE`: Stock moved to a Quarantine Location, excluding it from standard issue/sale.

## 12. Hospitality Requirements
- **Banquet Operations:** BEOs can temporarily allocate stock (`RESERVED`) before the event and issue it to a `BANQUET_STORE` during execution.
- **Mini Bars:** Due to high volatility, Mini Bars operate on a "Refill = Issue" par-level model rather than perpetual unit tracking inside the room.
- **Central Warehouse:** A Property can be configured purely as a Central Warehouse, handling massive POs and executing Inter-Property transfers to satellite hotels.

## 13. Future Compatibility
- **Recipe Engine:** The `PRODUCTION` location type allows raw ingredients to be issued in, and finished goods (e.g., 10 liters of soup) to be received out.
- **Retail POS:** Real-time API endpoints will deplete `OUTLET_STORE` locations via `SALES_ISSUE` movements based on POS tickets.
- **FIFO/AVCO:** Property-level valuation simplifies FIFO layering, as layers belong to the Property rather than being fragmented and micro-managed across dozens of sub-stores.

## 14. Governance Rules
1. **Transfer Approvals:** Inter-location transfers above configured value thresholds require managerial approval via the Approval Engine (ADR-003).
2. **Receiving Custody:** The destination Location Custodian must explicitly "Receive" an internal transfer. (Transfers cannot be forcefully pushed into a Bar without the Bar Manager's system acknowledgment).
3. **Quarantine Lock:** Stock in a `QUARANTINE` location cannot be issued to a POS outlet or production batch.

## 15. Risks
- **Intercompany Accounting Complexity:** Inter-property transfers inherently require complex cross-ledger tax and AP/AR generation, increasing the burden on the Finance Module.
- **Transit Discrepancies:** Goods lost or damaged while `IN_TRANSIT` require specialized write-off workflows to determine which property bears the financial loss.

## 16. Advantages
- Accurate, granular stock visibility across massive hotel complexes.
- Property-level AVCO prevents mathematical rounding errors and wild cost fluctuations during internal movements.
- Clean modeling of hospitality-specific workflows (BEOs, Mini-bars, Central Kitchens).

## 17. Trade-Offs
- Enforcing location checks on every transaction adds UI overhead for users (they must always select "From Store").
- Two-step internal transfers (requiring destination acknowledgment) slow down fast-paced operational movements but ensure absolute accountability.

## 18. Consequences
- The Inventory Ledger (ADR-008) must explicitly index by `location_id`.
- The UI must support "Transfer Requests" alongside direct "Transfers."
- A robust Intercompany Accounting design must be sequenced in the Finance Module to handle Central Warehouse operations.
