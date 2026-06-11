# IVORQ Operations Core Blueprint v2.0

**Document Type:** Master Architecture Blueprint
**Version:** 2.0
**Status:** Pending CTO Approval

---

## 1. Domain Architecture & Map
The Operations Core functions as the nerve center for physical property management, personnel, and daily execution, acting entirely separately from, but highly integrated with, the Finance Suite. 

**Module Ownership & Boundaries:**
- **Foundation Modules:** (Department, Location, Media, Timeline, Checklist, Cross-Module)
  - Ownership: Core Platform. 
  - Dependency: Zero upward dependencies. Relied upon by all Operations and Finance modules.
- **Support Modules:** (HRIS, Logbook, Incident Management)
  - Ownership: Operations Management.
  - Dependency: Depends on Foundation Modules.
- **Execution Modules:** (Asset Management, PM, Work Order, Engineering, Housekeeping)
  - Ownership: Engineering & Housekeeping.
  - Dependency: Depends heavily on Assets, Locations, Checklists, HRIS, and Media.

---

## 2. Entity Relationships & Foundation Design

### 2.1 Department Foundation
- **Entities:** `Department`, `DepartmentHierarchy` (parent/child), `DepartmentManager`, `DepartmentRole`, `DepartmentCostCenter`.
- **Purpose:** Segregates employees, assets, and budgets.
- **Rules:** Must map exactly 1:1 to General Ledger Cost Centers for P&L tracking.

### 2.2 Location Foundation
- **Entities:** `Property` > `Building` > `Floor` > `Area` > `Room`.
- **Purpose:** Universal spatial tagging for Assets, PMs, Housekeeping, and Incidents.
- **Performance:** Enforce Closure Tables or Materialized Paths for extreme read speeds on hierarchical queries (e.g., "Find all Work Orders in Building A").

### 2.3 Media & Attachment Foundation
- **Entities:** `Media`, `Attachment` (Polymorphic pivot), `Folder`, `Tag`, `Version`.
- **Purpose:** Handles photos, PDFs, warranties, and videos.
- **Storage:** S3-backed. Stored with strict `property_id` path prefixes for tenant isolation. Supports thumbnail generation via queues.

### 2.4 Activity Timeline Foundation
- **Entities:** `TimelineEvent` (Polymorphic target: Asset, Work Order, etc.).
- **Purpose:** A universal audit trail for end-users (e.g., Jira-style comments and state changes).
- **Strategy:** Asynchronous generation via Laravel Events. Offloaded to Elasticsearch or NoSQL if RDBMS row-count exceeds 100M.

### 2.5 Universal Checklist Foundation
- **Entities:** `ChecklistTemplate`, `ChecklistVersion`, `ChecklistExecution`, `ChecklistResult`.
- **Purpose:** Supports Pass/Fail, N/A, Numeric, Text, Photo Required, Signature Required.
- **Rules:** Changing a Template spawns a new `ChecklistVersion` to ensure historical `ChecklistExecution` immutability.

### 2.6 Universal Logbook Foundation
- **Entities:** `ShiftLog`, `DailyLog`, `HandoverLog`, `IncidentLog`.
- **Purpose:** Replaces physical duty manager and engineering notebooks.
- **Search:** Requires robust full-text search indexing (Meilisearch/Elastic).

### 2.7 Incident Management Foundation
- **Entities:** `Incident`, `Category`, `Severity`, `RootCause`, `CorrectiveAction`.
- **Relationship:** Incidents can spawn reactive `WorkOrder`s and trigger `PreventiveAction` workflows.

### 2.8 Work Order Foundation
- **Entities:** `WorkOrder`, `WorkOrderType`, `Priority`, `Assignment`, `Labor`, `Parts`.
- **Relationship:** Polymorphically linked to Location, Asset, or Incident. Consumes Inventory Parts (linking to GL expense).

### 2.9 HRIS Foundation
- **Entities:** `Employee`, `Attendance`, `Leave`, `Training`, `Certification`, `Disciplinary`.
- **Purpose:** Tracks labor availability for Engineering/Housekeeping dispatching.

---

## 3. Engineering & Housekeeping Operations

### 3.1 Engineering Operations Workflow
- Consolidates Asset Management, Preventive Maintenance (PM), Reactive Work Orders (WO), Inventory depletion, Incidents, and Shift Handovers into a single unified Dashboard KPI view.
- Technicians receive assigned PMs and WOs via mobile.

### 3.2 Housekeeping Operations Foundation
- Drives Room Cleaning schedules, Deep Cleaning, Public Area rounds, Lost & Found tracking, and Maintenance Requests.
- Deeply integrates with PMS (Property Management System) for guest checkout triggers and Engineering for instant defect reporting.

---

## 4. Mobile First Strategy
Operations happen in the field, not at desks.
- **PWA First Architecture:** Installable via browser, bypassing app store approval delays.
- **QR Code Workflow:** Every Location and Asset generates a QR code. Scanning instantly opens the associated Work Order or PM checklist.
- **Offline Support:** Local IndexedDB caching for Checklists in basement plant rooms (syncs upon reconnect).
- **Device Native Features:** Photo uploads, digital signatures on glass, and Web Push Notifications for dispatching.

---

## 5. Security Model
- **Strict Tenant Isolation:** Every entity mandates a `property_id`.
- **Role Based Access:** Driven by HRIS designations (e.g., Technician vs. Chief Engineer).
- **Granular Permissions:** `wo.create`, `wo.assign`, `pm.execute`, `logbook.sign`.
- **Cross-Property Access:** Enterprise management can access aggregated dashboards without breaking isolation at the query level.

---

## 6. Performance & Scalability Model
**Enterprise Baseline:** 100 Properties, 10 Years, 5,000 Employees, 100,000 Assets, 1,000,000 Attachments.
- **Indexes:** BTREE on all `property_id` + `status` composite fields. ULIDs utilized globally to prevent auto-increment locking.
- **Storage:** S3 strictly for Media. PostgreSQL strictly for relations. 
- **Search Engine:** Meilisearch or Elasticsearch is *mandatory* to handle Logbook, Incident, and Timeline full-text search across 10 years of data.
- **Queue Strategy:** Media thumbnailing, Timeline Event generation, and PM schedule generation must be strictly asynchronous (`redis` queues).

---

## 7. Risk Matrix

| Risk Category | Severity | Mitigation Strategy |
| :--- | :--- | :--- |
| **Data/Storage Growth** | High | Media auto-compression. Purge or archive historical Timeline events older than 3 years to cold storage. |
| **Cross-Module Coupling** | Critical | Use Event-Driven architecture (Publish/Subscribe) instead of direct service injection between HRIS and WorkOrders. |
| **Attachment Explosion** | Medium | Implement strict max-filesize limits and force WEBP/JPEG compression on mobile devices before upload. |
| **Logbook/Audit Growth** | High | Partition large tables (`activity_timelines`, `logbooks`) natively in PostgreSQL by year/month. |

---

## 8. Final Roadmap

- **v2.1** Preventive Maintenance
  - **v2.1A** Department
  - **v2.1B** Location
  - **v2.1C** Media
  - **v2.1D** Timeline
  - **v2.1E** Checklist
  - **v2.1F** Logbook
  - **v2.1G** Incident
- **v2.2** Work Order
- **v2.3** Engineering Operations
- **v2.4** Housekeeping
- **v2.5** HRIS

---

## 9. Open Questions
1. **PMS Integration:** How deeply should Housekeeping integrate with the existing PMS for live guest statuses (Check-in/Check-out)? Will we build an API gateway for PMS webhooks?
2. **Offline Sync Complexity:** For the Mobile PWA, how will we handle conflict resolution if two technicians attempt to complete the same offline PM checklist simultaneously?
3. **Inventory Management:** Work Orders consume parts. Is the Inventory / Procurement module from Finance Phase 1 mature enough to handle real-time storeroom depletion, or does it require expansion?

---

## 10. CTO Recommendations
1. **Adopt Table Partitioning Early:** For `activity_timelines` and `maintenance_executions`, implement PostgreSQL native table partitioning by year immediately to prevent future DBA nightmare scenarios.
2. **Mandate Meilisearch:** Do not attempt to use `WHERE LIKE` for logbooks or incidents. Spin up Meilisearch alongside Redis in the infrastructure stack.
3. **Decouple Core Foundations:** Build `Location`, `Department`, and `Checklist` as completely standalone micro-packages within the monolith. They must have absolutely zero knowledge of `Engineering` or `Housekeeping` to ensure universal reusability.
