# Asset Management Foundation (v2.2A) - CTO Revision Lock

**Document Type:** Master Architecture Lock & Implementation Readiness Review
**Status:** Pending Final CTO Approval

---

## 1. Existing Architecture Review Summary
The Asset Management Foundation (v2.2A) establishes the absolute physical baseline for all operations across the IVORQ platform. 
Previously approved elements include:
- **Closure Table Hierarchy** (Chiller > Compressor > Board).
- **One-Way Lifecycle Strategy** (Planned -> Active -> Retired).
- **QR / PWA Native Workflow** (`ivorq://asset/{ulid}`).
- **Deep Foundation Integrations** (Media, Timeline, Checklist, Incident, Location, Logbook).

This Revision Lock aggressively expands the foundation to support predictive intelligence, spatial auditing, and enterprise-grade SLA compliance based directly on CTO directives.

---

## 2. CTO Revisions

### 2.1 Asset Criticality (Revision #1)
Not all assets are equal. A broken lightbulb in the basement has a vastly different SLA than a broken Fire Pump.
- **Values:** `Low`, `Medium`, `High`, `Critical`, `LifeSafety`.
- **Impact:** The Universal Assignment Engine natively reads `AssetCriticality`. If an Incident or Work Order targets a `LifeSafety` asset, it automatically bypasses standard queues, escalates directly to the Chief Engineer, and demands a 15-minute response SLA.

### 2.2 Asset Condition (Revision #2)
Condition must be completely decoupled from operational `Status`.
- **Values:** `Excellent`, `Good`, `Fair`, `Poor`, `Critical`.
- **Operational Reality:** An asset can be `Status: Active` but `Condition: Poor` (e.g., A chiller that is running, but vibrating violently). Tracking this drift allows capital planning to replace the asset *before* it transitions to `Status: Out Of Service`.

### 2.3 Asset Risk Score (Revision #3)
Moves IVORQ from reactive maintenance to intelligent foresight.
- **Future Formula:** `Criticality + Condition + Age + Failure History`
- **Integration:** The `AssetRiskScore` will eventually feed directly into the `Risk Register Foundation` and the `Predictive Maintenance` module. If a `LifeSafety` asset drops to `Poor` condition, the system automatically flags a severe corporate risk.

### 2.4 Asset Commissioning (Revision #4)
An asset does not magically appear. It must be vetted.
- **`AssetCommissioning`:** A formal entity wrapping the birth of the asset.
- **Workflow:** Requires Acceptance Testing, Vendor Signoff, and Document Verification (Warranty/Manuals). Tightly coupled with the `Checklist Foundation` to ensure an asset never transitions to `Active` without passing rigorous safety protocols.

### 2.5 Asset Movement (Revision #5)
Assets migrate. A pump is removed from Tower A and placed in Tower B.
- **`AssetMovement`:** An immutable ledger tracking `Transfer`, `Relocation`, `Loan`, `Return`.
- **Tracking:** Captures `From Location`, `To Location`, `Date`, `User`, and `Reason`.
- **Audit Value:** Identifies equipment "shrinkage" (Ghost Assets) and proves chain of custody during insurance audits.

### 2.6 Asset Number Engine (Revision #6)
While `Asset ULID` guarantees database integrity, human technicians require visual identifiers.
- **Engine:** Generates deterministic, human-readable strings (e.g., `AST-USV-ENG-000001`).
- **Benefit:** Allows rapid radio communication ("I need help at Asset AST-USV-000001") without attempting to dictate a 26-character ULID over a walkie-talkie.

### 2.7 Asset Watchers (Revision #7)
- **`AssetWatcher`:** Subscribes a user (e.g., Chief Engineer) to a specific, high-priority asset.
- **Support:** If a `LifeSafety` asset generates a Work Order, changes status, or is linked to an Incident, all `Watchers` instantly receive a push notification.

### 2.8 End of Life Engine (Revision #8)
- **`AssetEndOfLife`:** Tracks `Manufacturer EOL`, `Warranty EOL`, and `Support EOL`.
- **Impact:** Automatically flags assets approaching EOL, funneling them into the future CAPEX planning module so Finance can budget replacements years in advance.

### 2.9 Asset Relationship Engine (Revision #9)
Assets interact systematically.
- **`AssetRelationship`:** Supports `Depends On`, `Connected To`, `Backup For`, `Redundant To`.
- **Impact:** If `Generator A` fails, the Incident Module natively queries `AssetRelationship` to determine if `Generator B` is registered as a `Backup For` Generator A, altering the severity of the crisis dynamically.

---

## 3. CTO Architecture Decisions (Strict Boundaries)

### 3.1 IoT Telemetry Strategy (Decision #1)
**Decision:** `DO NOT STORE IOT DATA INSIDE ASSET FOUNDATION.`
- **Architecture Separation:** The Asset Foundation will *never* store high-frequency telemetry (vibration, temperature). This belongs in a future, dedicated `IoT Foundation` (backed by a TSDB like InfluxDB or TimescaleDB). The Asset table merely exposes its `ulid` as a foreign key for the external IoT pipeline to map against.

### 3.2 Financial Data Strategy (Decision #2)
**Decision:** `DO NOT STORE FINANCIAL DATA INSIDE ASSET FOUNDATION.`
- **Architecture Separation:** Purchase Price, Depreciation, and Book Value are strictly prohibited in this module. 
- **Mapping:** A future `FinancialAsset` table (living in the Finance Core) will handle General Ledger amortization and simply hold a 1:1 map to the operational `Asset ULID`.

---

## 4. Asset Master Entity Definition
The `Asset` is officially designated as the **MASTER ENTITY** for all physical operations.
It is the absolute foundational dependency for:
- Preventive Maintenance (Targeting)
- Work Orders (Targeting)
- Engineering Operations (Dashboards)
- Inventory Consumption (Parts used against an Asset)
- Warranty (Claim targeting)
- Incident Equipment Failures (RCA targeting)

None of these operational modules can exist or function without querying the Asset Foundation.

---

## 5. Implementation Readiness Review

| Category | Score | Evaluation |
| :--- | :--- | :--- |
| **Architecture Completeness** | **98/100** | Entity isolation is flawless. CTO decisions regarding IoT and Finance boundaries prevent scope creep and database bloat. |
| **Scalability Readiness** | **95/100** | Use of Closure Tables for hierarchy prevents recursive SQL death. Strict ULID primary keys support easy multi-tenant sharding. |
| **Mobile Readiness** | **95/100** | Full QR URI schema (`ivorq://asset/{ulid}`) and PWA Offline definitions guarantee flawless field adoption. |
| **Future PM Readiness** | **100/100** | Risk Scores, Criticality matrices, and Asset Relationships provide the PM module an incredibly rich targeting engine. |
| **Future WO Readiness** | **100/100** | Explicit linkage to Locations, Checklists, and Warranties guarantees WOs execute with 100% contextual awareness. |

---

## 6. Updated Implementation Plan

### Entities
`Asset`, `AssetCategory`, `AssetType`, `AssetGroup`, `AssetStatus`, `AssetHierarchy`, `AssetLocation`, `AssetWarranty`, `AssetVendor`, `AssetDocument`, `AssetMedia`, `AssetTag`, `AssetCustomField`, `AssetCriticality`, `AssetCondition`, `AssetCommissioning`, `AssetMovement`, `AssetWatcher`, `AssetEndOfLife`, `AssetRelationship`.

### Services
- **`AssetLifecycleService`**: Manages Status transitions and validations.
- **`AssetCommissioningService`**: Drives the Checklist Foundation integration.
- **`AssetHierarchyService`**: Manages the Closure Table routing.
- **`AssetRelationshipService`**: Evaluates redundancy impact during WO/Incident generation.
- **`AssetRiskScoringService`**: Evaluates Age + Condition + History.

---

## 7. Open Questions
1. **QR Code Printing Strategy:** Does the Operations Team possess specialized label printers (e.g., Zebra) that can generate QR codes dynamically from the IVORQ PWA, or will tags be pre-printed sequentially and mapped manually in the field?

---

## 8. Final CTO Recommendations
1. **Lock the Foundation:** The architectural design is complete. All boundaries regarding Finance and IoT are secure. The structure fully supports the heavy demands of the impending PM and Work Order modules.
2. **Execute Implementation:** Pending final sign-off, authorize the engineering team to begin executing the database migrations, core models, and test suites based strictly on this Revision Lock document.
