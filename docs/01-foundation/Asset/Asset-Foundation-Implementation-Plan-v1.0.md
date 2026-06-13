# IVORQ Asset Foundation v1.0 Blueprint

## SECTION 1 — DOMAIN BOUNDARIES

### Inside Asset Foundation
The Asset Foundation is the absolute source of truth for all physical and virtual assets. It owns:
- Asset definitions, categories, and hierarchies.
- Asset lifecycle status and conditions.
- Asset location and custodianship.
- Serial numbers, QR codes, RFIDs, and tags.
- Base financial valuation data (book value, depreciation markers).

### Outside Asset Foundation
The Asset Foundation does not own operational processes:
- **Work Orders**: Owned by Engineering.
- **Incidents**: Owned by Incident Management.
- **Purchase Orders**: Owned by Procurement.
- **Stock Levels**: Owned by Inventory.
- **Ledgers**: Owned by Accounting.

**Core Rule**: The Asset Foundation owns the *object*. Other modules own the *process* interacting with the object.

---

## SECTION 2 — ASSET MASTER ARCHITECTURE

### Asset Aggregate
The core `Asset` entity represents a unique, trackable item.

### Support Entities & Attributes
- **Asset**: The physical/virtual item.
- **Asset Category**: High-level grouping (e.g., HVAC).
- **Asset Group**: Sub-grouping (e.g., Chillers).
- **Asset Type**: Specific type (e.g., Air-Cooled Chiller).
- **Asset Brand**: Manufacturer (e.g., Carrier).
- **Asset Model**: Specific model number.
- **Asset Status**: Operational state (e.g., Active, Out of Service).
- **Asset Condition**: Physical state (e.g., Excellent, Fair, Poor).
- **Asset Location**: Physical placement.
- **Asset Owner Department**: Responsible department.
- **Asset Custodian**: Specific user responsible for the asset.
- **Asset Tag**: Internal tracking number.
- **Asset QR Code / Barcode**: Unique scanning identifiers.
- **Asset RFID / NFC Ready**: Fields prepared for wireless scanning.

---

## SECTION 3 — ASSET CLASSIFICATION FRAMEWORK

### Supported Classifications
- Building
- Room
- Mechanical
- Electrical
- HVAC
- Kitchen Equipment
- Housekeeping Equipment
- IT Equipment
- Network Equipment
- Security Equipment
- Vehicle
- Furniture
- Fixture
- Tool
- Pool Equipment
- Spa Equipment
- Landscape Equipment
- Virtual Asset
- Software License
- Cloud Resource
- Subscription
- Other

---

## SECTION 4 — ASSET HIERARCHY ENGINE

### Support
The architecture supports infinitely nestable asset trees using adjacency lists or closure tables.
- **Parent Asset**: The main system.
- **Child Asset**: Sub-assemblies.
- **Component Asset**: Individual replaceable parts.
- **Assembly Asset**: Grouped components.
- **System Asset**: Top-level macro structures.

### Examples
- **Chiller**
  - Compressor
  - Condenser
  - Controller
- **Network Rack**
  - Switch
  - UPS
  - Patch Panel

**Rule**: Moving a Parent Asset must recursively update the location of all Child Assets.

---

## SECTION 5 — LOCATION ENGINE

### Support
Assets must be physically locatable across the enterprise.
- Property
- Building
- Floor
- Zone
- Area
- Room
- Rack
- Cabinet
- GPS Coordinate
- Indoor Position Ready (X, Y, Z coordinates for future mapping)

### Tracking
- **Location History**: Immutable log of every time the asset changes location.
- **Movement Tracking**: Audit trail of who moved the asset and when.

---

## SECTION 6 — ASSET LIFECYCLE

### Support Statuses
- Planned
- Ordered
- Received
- Installed
- Active
- Under Maintenance
- Out Of Service
- Retired
- Disposed
- Sold
- Lost
- Stolen
- Archived

### Tracking
- **Lifecycle History**: Immutable log of status changes.
- **Lifecycle Audit**: Records the User ID and rationale for the state transition.

---

## SECTION 7 — PROCUREMENT INTEGRATION READINESS

### Support & Integration Points
The Asset Foundation must expose APIs for the future Procurement module.
- **Purchase Request & Purchase Order**: Linking planned assets to POs.
- **Vendor**: Tying assets to their supplying vendor.
- **Receiving**: Automated asset creation upon GRN (Goods Receipt Note).
- **Warranty Registration**: Capturing warranty terms upon receiving.
- **Asset Creation From PO**: Direct mapping of PO line items to new Assets.
- **Bulk Asset Creation**: Excel/CSV imports for mass deployment.
- **Asset Capitalization**: Marking when an asset enters the financial ledger.

---

## SECTION 8 — INVENTORY INTEGRATION READINESS

### Support & Integration Points
Assets (capital items) vs. Inventory (consumables/spare parts).
- **Spare Parts**: Defining which inventory items are compatible with which Assets.
- **Consumables**: Tracking items consumed during asset operation (e.g., fuel, filters).
- **Stock Deduction**: Triggering inventory reductions when parts are fitted to an asset.
- **Asset Consumption**: How much inventory an asset consumes over its lifecycle.
- **Inventory Reservation / Allocation**: Reserving parts for a specific asset's upcoming maintenance.
- **Inventory Usage History**: Audit trail of parts consumed per asset.

---

## SECTION 9 — ENGINEERING INTEGRATION READINESS

### Support & Integration Points
Engineering is the primary consumer of the Asset Foundation.
- **Work Orders**: Every Work Order must reference a target Asset.
- **Preventive Maintenance**: Linking PM schedules to Asset Categories or specific Assets.
- **Corrective Maintenance**: Linking breakdown Work Orders to Assets.
- **Inspection & Calibration**: Tracking safety and compliance checks on assets.
- **Maintenance History**: Aggregated view of all work performed on the asset.
- **Downtime Tracking**: Recording exactly how long an asset is in the 'Out Of Service' state.
- **MTTR (Mean Time To Repair)**: Calculated metrics readiness.
- **MTBF (Mean Time Between Failures)**: Calculated metrics readiness.

---

## SECTION 10 — FINANCE INTEGRATION READINESS

### Support & Integration Points
Bridging physical operations with accounting.
- **Asset Cost & Acquisition Cost**: Initial purchase value.
- **Depreciation**: Placeholder for standard depreciation schedules (Straight-line, Declining balance).
- **Residual Value & Book Value**: Current financial worth.
- **Asset Transfer**: Financial tracking when moving assets between Properties (inter-company transfer).
- **Asset Disposal**: Financial write-offs.
- **CapEx vs OpEx**: Classifying the asset for budgetary reporting.
- **Accounting Mapping Ready**: Linking the Asset Category to a specific General Ledger (GL) account.

---

## SECTION 11 — WARRANTY MANAGEMENT

### Support
- **Warranty Start & Warranty End**: Exact date tracking.
- **Vendor Warranty vs Extended Warranty**: Tracking multiple overlapping coverage tiers.
- **Warranty Claim**: Reference links to claims filed against the vendor.
- **Warranty History**: Audit trail of warranty usage.
- **Warranty Alerts**: Automated triggers 30/60/90 days before expiration.

---

## SECTION 12 — DOCUMENT MANAGEMENT

### Integrate with Media Foundation
All files must use the Media Foundation via polymorphic links.
- Manuals
- Drawings (CAD/PDF)
- Certificates (Safety/Compliance)
- Photos (Condition at check-in)
- Videos
- Invoices
- Warranty Documents
- Inspection Reports
- Audit Evidence

---

## SECTION 13 — QR / BARCODE / RFID ARCHITECTURE

### Support
- **QR Code & Barcode**: Standard optical scanning matrices.
- **RFID & NFC**: Ready fields for wireless/proximity scanning hardware.
- **Asset Scanning**: Core UI capability.
- **Mobile Scanning**: Deep integration with the PWA device camera.
- **Bulk Scanning**: Rapid scanning for stock-takes and audits.
- **Offline Scanning**: Queuing scans while deep in the basement.
- **Scan History**: Tracking who scanned what asset, when, and where.

---

## SECTION 14 — MOBILE OFFLINE ARCHITECTURE

### Support
- **Offline Asset Lookup**: Caching the property's asset manifest in IndexedDB.
- **Offline Inspection**: Filling out condition reports without a network connection.
- **Offline Asset Movement**: Updating location fields locally.
- **Offline Photo Capture**: Storing evidence photos locally.
- **Offline Scan**: Logging audit scans locally.
- **Sync Queue**: Background worker pushing data to the server upon reconnection.
- **Conflict Resolution**: Last-write-wins based on exact timestamp of the offline action.
- **PWA Ready**: Progressive Web App standard compliance.

---

## SECTION 15 — AUDIT FOUNDATION READINESS

### Support
All actions are strictly immutable and tracked.
- Created
- Updated
- Moved
- Assigned (Custodian change)
- Retired
- Disposed
- Scanned
- Inspected

---

## SECTION 16 — REPORTING FOUNDATION INTEGRATION

### Support
Exposing Asset Data Providers to the Reporting Foundation.
- Asset Register
- Asset Valuation
- Asset Movement
- Warranty Report
- Maintenance Report
- Depreciation Report
- Disposal Report
- Executive Asset Report
- **Capabilities**: PDF, Excel, CSV, Print, Scheduled Reports.

---

## SECTION 17 — NOTIFICATION FOUNDATION INTEGRATION

### Support
Triggering the Notification Foundation for critical events.
- Warranty Expiry (30/60/90 day warnings)
- Inspection Due
- Maintenance Due
- Asset Offline / Broken Down
- Asset Moved (Unauthorized movement detection)
- Asset Missing (Failed audit scan)
- Critical Asset Failure (e.g., Main Chiller failure)
- Executive Alerts (CapEx asset failures)

---

## SECTION 18 — SEARCH ENGINE

### Support
- Asset Number & Serial Number
- QR, Barcode, RFID
- Category, Location, Department, Status, Vendor, Warranty
- **Full Text Search**: Using Postgres tsvector or ElasticSearch across notes and descriptions.
- **Saved Search**: User-specific saved queries.
- **Advanced Search**: Complex AND/OR filtering.

---

## SECTION 19 — DASHBOARD FRAMEWORK

### Support
UI Widgets provided to the Dashboard Foundation.
- Asset Count & Asset Value
- Asset Condition breakdown
- Warranty Expiry timeline
- Maintenance Due list
- Critical Assets health
- Asset Distribution (by Category/Location)
- Department Assets (Custodian view)
- Executive Dashboard summary

---

## SECTION 20 — MULTI PROPERTY ARCHITECTURE

### Support
Strict Tenant Isolation via `property_id`.
- Single Property
- Hotel Group
- Regional Group
- Corporate Group
- **Property Isolation**: Staff cannot see neighboring hotel assets.
- **Cross Property Visibility**: Group/Regional engineers can query across properties.
- **Corporate Oversight**: HQ can pull global asset registers.

---

## SECTION 21 — COMPLIANCE READINESS

### Support
Ensuring the foundation meets legal requirements.
- ISO 9001 (Quality Management)
- ISO 45001 (Occupational Health & Safety)
- Insurance Investigation (Proving the asset was maintained)
- Legal Investigation
- Corporate Audit
- Government Audit
- **Evidence Package Ready**: Exporting the asset history, work orders, and photos via the Media Foundation.

---

## SECTION 22 — AI FOUNDATION READINESS

### Future Support
Architecture must support future AI/ML pipelines without schema rewrites.
- Predictive Maintenance
- Failure Prediction
- Asset Health Score
- Asset Risk Score
- Anomaly Detection (e.g., "This asset breaks 400% more often than identical models")
- Asset Intelligence
- Lifecycle Prediction
- Replacement Recommendation (Repair vs. Replace AI analysis)

---

## SECTION 23 — ENTERPRISE PERFORMANCE STRATEGY

### Support
Designed for massive scale.
- **100,000+ Assets**: Utilizing DB indexing, partitioning by property.
- **Multi Property Scale**: Efficient tenant scoping.
- **Queue Ready**: Offloading heavy tree-traversals and bulk updates to Redis queues.
- **Caching**: Aggressive caching of asset hierarchies and categories.
- **Search Optimization**: Dedicated indexing tables.
- **Bulk Processing**: Chunked DB writes for mass-imports.

---

## SECTION 24 — OPEN CTO QUESTIONS

1. **Finance - Depreciation Engine**: Should the Asset Foundation natively calculate complex depreciation (MACRS, double-declining), or strictly pass acquisition costs to the external Finance module?
2. **Procurement - Asset Capitalization Threshold**: Should there be a system-wide financial threshold (e.g., >$500) where an inventory item is automatically classified as a Capital Asset upon receipt?
3. **Engineering - Parent/Child Downtime Logic**: If a Parent Asset (Chiller) goes 'Out Of Service', should the system cascade the 'Out Of Service' status down to all Child Assets recursively?
4. **Inventory - Spare Parts Linking**: Will spare parts be strictly tied by Manufacturer Part Number (MPN), or will the system support internal universal SKU mapping for generic parts?
5. **AI - Sensor Data Ingestion**: Does the Asset Foundation need to prepare TimescaleDB or InfluxDB schemas now to accept real-time IoT sensor telemetry (temperature/vibration) for future Predictive Maintenance?
6. **Compliance - Disposal Workflows**: Must Asset Disposal trigger a mandatory multi-signature approval workflow before the asset is removed from the active register?
7. **Scaling - Graph Database vs Relational**: Given potentially infinite asset hierarchies, should we utilize Postgres Recursive CTEs or introduce a Graph database (e.g., Neo4j) for deep hierarchy queries?
8. **Multi Property - Inter-Company Transfers**: When transferring an asset between Hotel A and Hotel B, does the Asset ULID remain identical, or is a new Asset created to cleanly sever the financial ledger history?
9. **Asset Lifecycle - Re-Capitalization**: If a major overhaul extends an asset's life by 10 years, how does the foundation handle the subsequent update to the asset's Book Value?
10. **Asset Ownership - Custodian Accountability**: If an asset is lost or stolen, does the system automatically log a penalty or flag against the assigned Asset Custodian's HRIS profile?
11. **RFID Strategy**: Will the system support active RFID (real-time tracking beacons) or only passive RFID (scanned manually during audits)?
12. **Naming Conventions**: Should Asset Tags be globally unique across the entire enterprise, or only unique within a specific Property?
13. **Offline Sync - Asset Movement Conflicts**: If User A moves an asset offline to Room 101, and User B moves it offline to Room 102, does the system simply accept the latest `captured_at` timestamp upon sync?
14. **Media Retention on Disposal**: When an asset is disposed of, should its associated photos/manuals be purged, or retained indefinitely for audit purposes via the Media Foundation?
15. **Virtual Assets vs Physical Assets**: Should software licenses and cloud resources be stored in the exact same table as physical HVAC units, or segregated into a `virtual_assets` table to optimize indexing?

---

## SECTION 25 — CTO RECOMMENDATIONS

### Recommended Asset Numbering Standard
Standardize on a smart-prefix taxonomy: `[PROPERTY_CODE]-[CATEGORY_CODE]-[SEQUENTIAL_ID]`. Example: `BALI-HVAC-00104`. This ensures human readability while the backend strictly relies on the hidden ULID for relational integrity.

### Recommended QR Strategy
Generate generic, secure QR codes containing only the Asset ULID (e.g., `https://ivorq.app/a/01H...`). Do not encode physical details into the QR code itself, ensuring the QR code remains valid even if the asset is relocated or recategorized.

### Recommended RFID Strategy
Begin with Passive UHF RFID tags. Active RFID requires massive capital expenditure for gateway installation across hotels. Passive UHF allows engineering and security to conduct rapid room-by-room stock takes using handheld wand scanners.

### Recommended Warranty Strategy
Implement a strict "Parent-Child Warranty Split". The Parent assembly (e.g., Chiller) has a master warranty, but child components (e.g., Compressor) must support independent manufacturer warranties, as they are often replaced under different terms.

### Recommended Depreciation Strategy
The Asset Foundation should only store "Acquisition Value" and "Residual Value". Complex depreciation calculations should be offloaded to the Finance/Accounting module or a third-party ERP integration to avoid duplicating tax-law logic inside the operational platform.

### Recommended Predictive Maintenance Strategy
Prepare the database schema with an `asset_telemetry` polymorphic link, but delay implementation. Focus Phase 1 strictly on calendar-based and meter-based (e.g., running hours) Preventive Maintenance before attempting AI-driven predictive modeling.

### Recommended Asset Governance Model
Enforce strict Role-Based Access Control (RBAC) on Asset creation. Only Procurement, Finance, or Chief Engineers should have permission to create or retire Capital Assets, preventing data pollution by junior technicians.

---

STATUS: READY FOR CTO REVIEW
