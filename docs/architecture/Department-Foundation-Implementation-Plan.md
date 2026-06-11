# Department Foundation Implementation Plan

**Document Type:** Master Architecture Blueprint (v2.1A)
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Department Foundation is the organizational spine of IVORQ. It must precede HRIS, Engineering, Housekeeping, and Work Orders because every employee, asset, approval workflow, and financial cost center inherently belongs to a Department. Without it:
- HRIS cannot assign personnel to teams or define reporting lines.
- Work Orders and PMs cannot be routed to the correct execution group (e.g., Engineering vs. IT).
- The Finance Suite cannot distribute operational budgets to functional teams.
- Escalation paths inside the Universal Assignment Engine would break.

---

## 2. Architecture Design
The Foundation operates on the following core entities:
- **`Department`**: The root aggregate root (e.g., "Engineering", "Housekeeping").
- **`DepartmentHierarchy`**: Resolves parent-child organizational trees.
- **`DepartmentRole` & `DepartmentPosition`**: Determines job functions (e.g., "Chief Engineer", "Room Attendant").
- **`DepartmentManager`**: Pivot mapping an `Employee` (HRIS) as the head of a department.
- **`DepartmentCostCenter`**: The financial bridge linking the operational department to the GL Cost Center.
- **`DepartmentType` & `DepartmentStatus`**: Enums categorizing the operational nature (Front of House, Heart of House, Corporate) and lifecycle (Active, Suspended, Inactive).

---

## 3. Entity Relationships & Organization Hierarchy

### 3.1 Multi-Property Strategy
A `Department` possesses a `property_id` allowing strict property-level isolation. However, to support enterprise scale, the architecture supports:
- **Single Property:** "Property Housekeeping" (`property_id` = Property A).
- **Cluster Department:** "Cluster Engineering" (`property_id` = Cluster Group UUID), overseeing multiple physical locations.
- **Corporate / Shared Service:** "Corporate Finance" (`property_id` = Corporate UUID), managing global policy without local property constraints.

### 3.2 Organization Hierarchy
Supported natively via the `DepartmentHierarchy` adjacency list (Materialized Path or Nested Sets):
- General Manager
  - Rooms Division
    - Front Office
    - Housekeeping
  - Engineering
  - Finance
  - Security & HR

### 3.3 Department Role Model
Roles govern operational execution. `DepartmentRole` bridges into the Assignment Engine:
- **Chief Engineer:** Approval Authority, Manager.
- **Engineering Supervisor:** Quality Assurance, Dispatcher.
- **Technician / Room Attendant:** Execution Level.

### 3.4 Cost Center Strategy
The `DepartmentCostCenter` is a mapped ID that future-proofs GL integration.
- `ENG-001` (Engineering), `HK-001` (Housekeeping).
- Work Order parts consumed by Engineering are automatically billed to the `ENG-001` cost center in the GL, driving the Budget & Forecast variance reports without manual journaling.

---

## 4. Integration Engines

### 4.1 Assignment Engine Integration
The `Universal Assignment Engine` treats `Department` as an assignable principal.
- If a Work Order is logged as "Broken AC", it is assigned directly to the `Engineering` Department.
- The department's internal rules distribute it to an available `Technician`.
- Escalations naturally trigger if the `DepartmentManager` ignores the assignment SLA.

### 4.2 Approval Engine Integration
Routing logic traverses the `DepartmentHierarchy` upwards:
- *Technician* submits a High-Risk PTW.
- Routes to *Engineering Supervisor*.
- Escalates to *Chief Engineer* (Manager).
- Final Capex approval routes to *Director of Finance* (via hierarchy link to Corporate).

---

## 5. Security Model & Business Rules

### Business Rules (BR)
- **BR-001:** Department cannot be deleted if active employees or assets are attached (Soft Delete only).
- **BR-002:** Department Managers must belong to the exact same `property_id` (or the parent Cluster/Corporate ID).
- **BR-003:** Department `code` must be strictly unique per `property_id`.
- **BR-004:** Circular reporting structures in `DepartmentHierarchy` are blocked at the database and application levels.
- **BR-005:** A Department without a defined `DepartmentManager` defaults escalations to the parent Department's Manager.

### Security Model
- **Department Isolation:** Staff strictly view WOs, PMs, and Incidents bound to their Department unless explicitly granted enterprise access.
- **Property Isolation:** Global constraints prevent Property A staff from modifying Property B departments.

---

## 6. Scalability & Performance Review
**Baseline Estimates:** 100 Properties, 5000 Employees, 500 Departments, 10 Years History.
- **Volume:** Departments are low-volume, high-read.
- **Caching:** The entire `DepartmentHierarchy` tree must be cached natively via Redis per `property_id`, automatically invalidated upon updates, avoiding heavy recursive SQL queries during page loads.
- **Search Strategy:** As department names change rarely, a simple BTREE index on `name` and `code` is sufficient. No Meilisearch required strictly for Departments.

---

## 7. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Hierarchy Corruption** | Critical | Use robust nested-set validation to prevent circular dependencies (A manages B, B manages A). |
| **Cross-Property Leakage** | High | Apply Global Scopes on all queries forcing `where('property_id', Auth::user()->property_id)`. |
| **Permission Escalation** | Medium | Changing a `DepartmentRole` must require corporate or HR-level approval. |
| **Manager Conflicts** | Low | Enforce strict DB unique constraints on `(department_id, is_primary_manager)`. |

---

## 8. Implementation Plan

### Entities & Enums
- **Models:** `Department`, `DepartmentHierarchy`, `DepartmentRole`, `DepartmentManager`, `DepartmentCostCenter`.
- **Enums:** `DepartmentTypeEnum` (Corporate, Cluster, Property, SharedService), `DepartmentStatusEnum` (Active, Suspended, Inactive).

### Logic Layers
- **Repositories:** `DepartmentRepository` (handles caching and hierarchy traversal).
- **Services:** `DepartmentHierarchyService` (blocks circular logic), `DepartmentManagerService` (syncs HRIS assignments).
- **Policies:** `DepartmentPolicy` (view, create, edit, delete).
- **API Endpoints:** RESTful JSON API supporting standard CRUD and a distinct `/api/departments/tree` endpoint for frontend visualization.

### Migration & Deployment Strategy
- Rolled out as a completely isolated package (`Modules/Foundation/Department`).
- Database migrations establish ULID-based tables.

---

## 9. Testing Strategy
- **Unit Tests:** Validate `DepartmentHierarchyService` correctly detects and throws exceptions on circular references. Validate Enum parsing.
- **Feature Tests:** Assert endpoints correctly return the JSON tree structure. Ensure `property_id` isolation intercepts cross-tenant edits.
- **Integration Tests:** (Future) Verify Universal Assignment Engine successfully attaches mock WOs to mock Departments.
- **Concurrency Tests:** Attempt to assign two different primary managers simultaneously to verify DB locks hold.

---

## 10. Open Questions
1. **Cost Center Maturity:** Does the existing GL foundation require a synchronous API call to establish a Cost Center concurrently when an Operations user creates a Department, or are they mapped after the fact by Finance?
2. **Cluster Rollups:** For a Cluster Engineering department covering 3 hotels, does the `Department` model need a `cluster_property_ids` JSON array, or will we strictly utilize a specialized Corporate `property_id` hierarchy?

---

## 11. CTO Recommendations
1. **Immutable Cost Centers:** Once a `Department` is bound to a `DepartmentCostCenter` and financial transactions occur, block any unlinking at the API level to prevent GL orphan records.
2. **Prioritize the Tree Caching:** Do not deploy this module without Redis caching of the organizational tree. Computing hierarchies on the fly will bottleneck the Approval Engine heavily down the line.
3. **Decouple from HRIS:** In v2.1A, an "Employee" might not exist yet (HRIS is v2.5). Design the `DepartmentManager` to map to a generic `user_id` or stub `Employee` ID that can be backfilled when HRIS launches.
