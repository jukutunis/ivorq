# Asset Management Foundation Implementation Plan (v2.2A)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Asset Management Foundation is the primary physical anchor for the entire IVORQ operations ecosystem. It acts as the definitive master registry for all capital and operational equipment across the enterprise.
**Why it must exist first:**
- **Preventive Maintenance (PM) & Work Orders (WO):** A technician cannot repair or maintain a "Chiller" if the Chiller does not exist as a tracked, unique entity in the database.
- **Incident Management:** If a boiler explodes, the Incident must target the specific Asset ULID to trigger RCA (Root Cause Analysis) against the manufacturer.
- **CAPEX & Finance:** Future modules will calculate depreciation, lifecycle cost, and replacement capital based directly on the birth/death dates registered in this foundation.

---

## 2. Architecture Design & Entity Relationships

**Core Entities:**
- **`Asset`**: The master aggregate root.
- **`AssetCategory` & `AssetType` & `AssetGroup`**: Taxonomy (e.g., HVAC -> Chiller -> Centrifugal).
- **`AssetStatus`**: Tracks the operational state (e.g., Active, Out Of Service).
- **`AssetHierarchy`**: Closure table supporting N-deep parent-child component mapping.
- **`AssetLocation`**: Polymorphic pivot bridging the Asset to the Location Foundation.
- **`AssetWarranty`**: Tracks OEM guarantees and expiry dates.
- **`AssetVendor`**: Tracks Manufacturers, Suppliers, and Maintenance Contractors.
- **`AssetTag` & `AssetCustomField`**: Supports flexible attributes (e.g., "BTU Output" for an AC).

---

## 3. Asset Hierarchy Strategy
Assets are rarely standalone. A massive property requires deep component tracking.
- **Relationship Strategy:** Handled via a robust Closure Table (`AssetHierarchy`).
- **Example:** `Chiller` (Parent) -> `Compressor` (Child) -> `Control Board` (Sub-Component).
- **Maintenance Impact:** If a PM is scheduled for the `Chiller`, the system can automatically flag linked components for inspection. If the `Control Board` is replaced, its specific lifecycle and warranty resets independently of the parent Chiller.

---

## 4. Lifecycle Strategy
An Asset traverses a strict, one-way state machine (with rare exceptions).
- **Flow:** `Planned` -> `Ordered` -> `Received` -> `Installed` -> `Commissioned` -> `Active`.
- **Degradation Flow:** `Active` <-> `Under Maintenance` <-> `Out Of Service`.
- **End of Life:** `Disposed`, `Retired`, `Lost`, `Transferred`.
- **Status Controls:** A `Retired` asset is hard-locked at the policy level. It cannot receive a new Work Order or PM schedule, preventing operational waste on dead equipment.

---

## 5. Warranty & Vendor Strategy

### 5.1 Warranty Strategy
- **Coverage:** Start Date, End Date, and explicit Terms.
- **Expiry Alerts:** Background queues evaluate warranties approaching 30, 60, and 90 days from expiration, automatically flagging the Asset to Engineering Directors to schedule final "end-of-warranty" deep inspections.

### 5.2 Vendor Strategy
Separates the entities responsible for the Asset's existence vs its upkeep.
- **Support:** Tracks `Manufacturer` (Trane), `Supplier` (Local Distributor), and `Service Vendor` (Contracted HVAC Repair).
- **Escalation Contacts:** Bound explicitly to the asset to enable 1-click dialing from the Mobile PWA when the equipment fails.

---

## 6. QR Strategy & Mobile PWA

### 6.1 QR Strategy (CTO Mandate)
- **Format:** Strict URI schema: `ivorq://asset/{ulid}`
- **Deep Linking:** When scanned by a native mobile device or the PWA, the URI instantly intercepts the payload, loading the Asset's digital twin without requiring manual search.

### 6.2 Mobile PWA Strategy
- **Field Technician Workflow:** An engineer scans the QR code on a pump. The PWA instantly pulls up the active Work Orders, historical Timeline, and associated PDF Manuals.
- **Offline Support:** High-priority assets assigned to the technician's shift are cached in IndexedDB. If the engineer is in a dead-zone basement, they can still view the PDF Manual and log a Work Order against the asset, queuing it for background sync.

---

## 7. Foundation Integration (Media, Timeline, Checklist, Incident, Logbook)

- **Media (v2.1C):** `AssetMedia` bridges to S3. Stores photos, PDF Manuals, and OEM Compliance Certificates.
- **Timeline (v2.1D):** Total historical narrative. Every WO, PM, Movement, and Status Change emits an event. Evaluates the 10-year lifespan of an asset chronologically.
- **Checklist (v2.1E):** `Asset` is the polymorphic target for Commissioning Checklists, Safety Verifications, and End-of-Warranty inspections.
- **Logbook (v2.1F):** Shift notes explicitly mentioning the asset are aggregated here.
- **Incident (v2.1G):** An equipment failure resulting in injury or severe damage targets the Asset, automatically logging the failure against its RCA metrics.

---

## 8. Security Model
- **Property Isolation:** Global scopes strictly bound to `property_id`.
- **Department Isolation:** IT Assets are isolated from Engineering Assets, unless cross-department visibility is explicitly granted.
- **Legal Hold:** If an `Asset` causes a severe `Incident` (e.g., elevator drop), Legal Hold freezes the Asset. Its Timeline, WO history, and Media cannot be purged or modified until litigation completes.

---

## 9. Scalability Review
**Enterprise Baseline:** 100 Properties, 500,000 Assets, 50,000,000 Timeline Events, 10 Years.
- **Partitioning:** While `assets` will hover around 500k rows (manageable via standard B-Tree), the `asset_hierarchy` closure table and polymorphic linking tables must be tightly indexed.
- **Search:** Meilisearch is **mandatory** to handle rapid wildcard lookups across 500k serial numbers, tags, and custom fields with sub-50ms latency.
- **Caching:** The structural hierarchy (Parent/Child trees) is heavily cached in Redis, invalidated only upon asset movement or restructuring.

---

## 10. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Ghost Assets** | Critical | Mandate annual physical QR-scanning audits via the Checklist Foundation to verify physical presence against digital records. |
| **Missing Warranties** | High | PWA strictly enforces `Warranty Document` uploads during the `Commissioning` lifecycle stage. |
| **Duplicate Assets** | High | Database-level unique constraints on `(property_id, serial_number, manufacturer_id)`. |
| **Data Corruption** | High | Prevent destructive schema changes. Use `AssetCustomField` JSON schemas to safely handle weird OEM-specific data points without altering SQL tables. |

---

## 11. Future Integrations
- **Preventive Maintenance & Work Orders:** Assets act as the primary foreign key for operational scheduling.
- **CAPEX / Finance:** Will pull purchase price, installation date, and expected lifespan to calculate Straight-Line Depreciation in future accounting modules.
- **AI Analytics:** Will eventually ingest the WO history to predict MTBF (Mean Time Between Failures) and prescribe predictive maintenance.

---

## 12. Implementation Plan

### Entities
`Asset`, `AssetCategory`, `AssetHierarchy`, `AssetStatus`, `AssetLocation`, `AssetWarranty`, `AssetVendor`, `AssetCustomField`.

### Services
- **`AssetLifecycleService`**: Governs state machine transitions and enforces rules (e.g., cannot Retire an asset with open WOs).
- **`AssetHierarchyService`**: Manages the Closure Table updates when a component is moved to a new parent.
- **`AssetWarrantyService`**: Drives the chron-jobs scanning for approaching 90-day expiry dates.

### Integrations
- Bound tightly to Media, Timeline, Location, Checklist, and Logbook Foundations via standard polymorphic traits.

---

## 13. Testing Strategy
- **Hierarchy Tests:** Move a `Sub-Component` to a different `Parent Asset`. Assert the Closure Table recalculates the depth correctly without creating orphan rings.
- **Lifecycle Tests:** Attempt to create a PM schedule for a `Retired` asset. Assert an exception is thrown.
- **QR Deep Link Tests:** Mock a PWA URI intent (`ivorq://asset/{ulid}`) and assert the correct JSON payload is fetched from the API.
- **Warranty Alert Tests:** Time-travel the server 90 days forward. Assert the `AssetWarrantyService` queues the correct notification to the Engineering Director.

---

## 14. Open Questions
1. **IoT Telemetry Payload:** Should the Asset Foundation natively provision a TSDB (Time-Series Database) schema now to catch incoming IoT temperature/vibration data, or will that be an entirely separate `IoT Foundation` later on the roadmap?
2. **Depreciation Calculations:** Do we track financial data (Purchase Price, Salvage Value) in the Operational `Asset` table now, or wait to create a parallel `FinancialAsset` table in the Finance module that maps to the Operational Asset ULID?

---

## 15. CTO Recommendations
1. **Mandate Closure Tables for Hierarchy:** Do not build the `AssetHierarchy` using standard recursive `parent_id` foreign keys. A complex HVAC system can go 6 levels deep; standard SQL recursion will cripple the API. Closure tables are a non-negotiable requirement.
2. **URI Schema Lock:** Lock the `ivorq://asset/{ulid}` schema today. Ensure operations teams immediately begin printing UV-resistant tags. Physical label placement takes months; decoupling it from the software deployment timeline is critical for ROI.
3. **Defer Financial Data:** Keep the Operational Asset lean. Do not pollute this table with Depreciation logic. Future Finance modules should maintain their own ledger that simply maps to this ULID.
