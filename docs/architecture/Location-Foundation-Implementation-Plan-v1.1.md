# Location Foundation Implementation Plan (v1.1)

**Document Type:** Master Architecture Blueprint (Revision 1.1)
**Status:** Pending CTO Approval

---

## 1. Revised Architecture & New Entities
The Location Foundation serves as the enterprise spatial backbone, tracking every physical space within IVORQ's portfolio. It has been extensively expanded to support rich geofencing, IoT mapping, and operational availability hours.

**Core Entities:**
- **`Location`**: The universal aggregate root representing a spatial node.
- **`LocationClosure`**: A Closure Table managing the deeply nested spatial hierarchy (Property > Building > Floor > Room).
- **`LocationCategory`**: Categorization for risk and priority routing (e.g., GuestArea, BackOffice).
- **`LocationTag`**: Flexible traits applied to locations (e.g., VIP, HighRisk).
- **`LocationOperatingHours`**: Defines operational availability (e.g., Gym open 06:00-22:00).
- **`LocationContact`**: Designated routing points for emergencies or dispatches.
- **`LocationDocument`**: Versioned media specific to the space (Blueprints, Floor Plans).
- **`LocationExternalMapping`**: The gateway connecting IVORQ's spatial nodes to third-party integrations (PMS, BMS, IoT).

---

## 2. Advanced Location Attributes

### 2.1 Location Category & Tags
- **Categories:** GuestArea, PublicArea, BackOffice, ServiceArea, CriticalArea, RestrictedArea. 
  - *Impact:* A Work Order inside a `GuestArea` naturally escalates in priority compared to `BackOffice`.
- **Tags:** VIP, RevenueArea, Critical, Compliance.
  - *Impact:* Filtering PM dashboards to ensure all `HighRisk` tagged locations (e.g., Plant Rooms) achieve 100% compliance prior to government audits.

### 2.2 Geo Layer
Extends the location with precise physical tracking:
- **Attributes:** `latitude`, `longitude`, `polygon` (GeoJSON).
- **Support:** Ideal for expansive resorts handling Villas, Beach Clubs, or Parking structures.
- **Impact:** Powers future mobile PWA navigation allowing Housekeeping buggies to find specific detached locations via GPS tracking.

### 2.3 Location Operating Hours
- **Purpose:** Defines when a location is active.
- **Impact:** The Universal Assignment Engine leverages this data. If a PM is generated for the Restaurant (07:00-23:00), the engine can be configured to dynamically schedule the maintenance *outside* of these operating hours to prevent revenue disruption.

### 2.4 Location Contact
Defines specific notification routing endpoints for a given physical space.
- **Types:** Engineering Hotline, Housekeeping Extension, Emergency Number, Radio Channel.
- **Impact:** An incident logged at the "Main Pool" instantly routes alerts to the "Radio Channel" tied explicitly to the Pool Location, bypassing standard department queues for immediate dispatch.

### 2.5 Location Document
- **Purpose:** Associates critical spatial documents (Electrical Drawings, Evacuation Maps, Equipment Layouts).
- **Relationship:** Integrates deeply with the upcoming Media Foundation. Documents are securely versioned so engineers always view the most recent architectural blueprints via their mobile devices.

---

## 3. Integration Strategy

### 3.1 Location External Mapping
The Foundation drops hard-coded PMS logic in favor of a universal bridging table: `LocationExternalMapping`.
- **Structure:** `(location_id, external_system, external_code)`
- **Support:** 
  - **PMS:** `RM1201`
  - **BMS (Building Management System):** `BMS-ZONE-1201`
  - **Door Lock System:** `DL1201`
  - **IoT:** `SENSOR-1201`
- **Governance:** Allows unlimited external integrations to map to a single IVORQ location. When an IoT webhook reports a temperature failure for `SENSOR-1201`, IVORQ traces it back to the core Location to automatically generate a Work Order.

### 3.2 QR Strategy
**CTO Directive:** Transition entirely from JSON payloads to strict URI schema.
- **Format:** `ivorq://location/{ulid}`
- **Support:** Printing this URI on physical labels guarantees future-proofing. Currently, it can trigger the PWA via web intents; eventually, it will flawlessly trigger a native iOS/Android application. It natively supports offline caching as the ULID uniquely identifies the exact cached payload.

---

## 4. Multi-Property Review
**Enterprise Baseline:** 100 Properties, 500,000 Locations, 10 Years History.
- **Closure Tables:** The `LocationClosure` table handles hierarchical read operations. Retrieving all rooms inside a massive 50-story building executes in a single indexed join.
- **Caching:** The structural hierarchy per property must be strictly cached via Redis, invalidating only when a node is added/moved.
- **Partitioning:** While locations are relatively static, the `LocationExternalMapping` webhook events log should be natively partitioned in PostgreSQL to prevent table bloat.

---

## 5. Security Model
- **Isolation:** `property_id` strict enforcement remains absolute on all locations, documents, and external mappings.
- **Category Restrictions:** A location marked as `RestrictedArea` naturally hides its internal documents (Blueprints) from standard Operational views, requiring elevated security clearances to access.
- **Audit:** Any modifications to Geo polygons, Operating Hours, or External Mappings trigger an immutable write to the Activity Timeline.

---

## 6. Future Module Integration Review
- **Asset Management:** Assets bind to Locations. Moving a parent location (e.g., re-zoning a building wing) seamlessly moves all attached assets.
- **Preventive Maintenance (PM):** Generates routing lists grouped by `LocationHierarchy` to prevent technicians from wasting time walking between distant wings.
- **Housekeeping / PMS:** Driven natively by the `LocationExternalMapping` catching reservation changes.
- **Risk Register:** Hazards are flagged physically in the system, turning `Location` into a mapped hotspot for the General Manager.

---

## 7. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Mapping Collisions** | Critical | External systems might reuse codes across properties. The `LocationExternalMapping` table must enforce a unique composite constraint on `(property_id, external_system, external_code)`. |
| **Closure Tree Corruption** | High | Moving a Location node to a new parent requires rebuilding its descendants in the closure table. This must be handled via DB transactions to guarantee structural integrity. |
| **Geo Data Bloat** | Medium | Large GeoJSON polygons stored as text can bloat query payloads. Leverage PostgreSQL native `PostGIS` or standard `geometry` types for efficient spatial tracking. |

---

## 8. Updated Implementation Plan

### Entities
- `Location`, `LocationClosure`, `LocationCategory`, `LocationTag`, `LocationOperatingHours`, `LocationContact`, `LocationDocument`, `LocationExternalMapping`.

### Services
- **`LocationHierarchyService`**: Exclusively manages Closure Table inserts/moves.
- **`LocationIntegrationService`**: Handles dynamic mapping lookup from Webhooks to Internal ULIDs.
- **`LocationAvailabilityService`**: Calculates dispatch times against active Operating Hours.

### API Strategy
- REST API delivering localized nodes with pre-signed S3 links for attached Blueprints/Drawings via the `Media Foundation`.

---

## 9. Updated Testing Strategy
- **External Mapping Integrity:** Ensure `LocationExternalMapping` rejects duplicate `(property_id, system, code)` entries.
- **Availability Computation:** Test that assigning a Work Order inside a Location with active hours (08:00-17:00) calculates the SLA countdown correctly vs off-hours.
- **Closure Movement:** Move a "Floor" to a different "Building" and assert that all nested "Rooms" are seamlessly reparented in the `LocationClosure` table.

---

## 10. Open Questions
1. **IoT Rate Limiting:** If we open `LocationExternalMapping` to catch IoT temperature sensors, what is the strategy to prevent a broken sensor from spanning 10,000 WOs per minute?
2. **Geo Layer Maturity:** Should we immediately enforce `PostGIS` database extensions on the PostgreSQL server, or store simple GeoJSON strings for Sprint v2.1B?

---

## 11. CTO Recommendations
1. **URI QR Workflow:** Lock the `ivorq://location/{ulid}` schema today. Ensure the operations teams immediately begin printing UV-resistant tags, completely decoupling physical label placement from software deployment timelines.
2. **PostGIS Implementation:** Adopt PostGIS for the Geo Layer natively. Attempting to parse text-based polygons for "Distance from technician" calculations later will fail at enterprise scale.
3. **Webhook Quarantine:** Enforce strict quarantine rules on the `LocationExternalMapping` endpoint. Unrecognized `ExternalCode` payloads must be dropped into a quarantine table for manual administrative review rather than crashing the primary queue.
