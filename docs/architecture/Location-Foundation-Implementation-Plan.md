# Location Foundation Implementation Plan (v2.1B)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Location Foundation serves as the spatial backbone of IVORQ's Operations Core. It provides the exact physical coordinates for every action occurring within a property. Without this foundation:
- **Work Orders** cannot direct technicians to the correct floor or room.
- **Preventive Maintenance (PM)** cannot group scheduled tasks geographically.
- **Housekeeping** cannot track Room Statuses or dispatch cleaners efficiently.
- **Incidents** (e.g., a water leak) lack physical containment boundaries.
- **Assets** float in the system without a physical home, preventing accurate auditing.

---

## 2. Architecture Design
The Foundation operates on the following core structures:

### 2.1 Core Entities
- **`Location`**: The universal aggregate root representing a spatial node.
- **`LocationHierarchy`**: An adjacency list or Closure Table managing the deeply nested spatial relationships.
- **`LocationType`**: Enum categorizing the node (Building, Floor, Area, Room, Zone, Exterior).
- **`LocationStatus`**: Enum for operational readiness (Active, Out of Order, Out of Service, Under Renovation).
- **`LocationQR`**: Stores the globally unique payload mapping to a physical QR Code affixed to the location.

### 2.2 The Universal Spatial Tree
Locations are rigidly nested to support cascading logic:
- `Property` (Inherited scope)
  - `Building` (e.g., Tower A)
    - `Floor` (e.g., Floor 12)
      - `Area` (e.g., North Corridor)
      - `Room` (e.g., Room 1201)

This allows extremely powerful queries, such as retrieving every HVAC Asset or Work Order existing *anywhere* under "Tower A".

---

## 3. Operations & PMS Integration

### 3.1 QR Code Mobile Strategy
Every created `Location` automatically generates a deterministic QR payload.
- When an engineer scans the QR code affixed to "Room 1201" using the PWA, the system instantly loads the `Location Dashboard`, displaying:
  - All Assets housed in the room.
  - Active Work Orders and Incidents.
  - Historical maintenance logs.

### 3.2 PMS Gateway Preparation (Housekeeping)
Rooms are a specific `LocationType` with extended states:
- They must support integrations with the upcoming PMS Gateway (Check-in/Check-out status, Dirty/Clean status).
- The Location Foundation will expose explicit webhook-ready fields (e.g., `pms_room_id`, `housekeeping_status`) to seamlessly catch PMS syncs in Sprint 2.4B.

---

## 4. Business Rules

- **BR-001 (Property Isolation):** Every location strictly belongs to a single `property_id`.
- **BR-002 (Uniqueness):** The `location_code` (e.g., `RM-1201`) must be absolutely unique within a Property to prevent dispatch errors.
- **BR-003 (Cascading Deletes):** A Location cannot be deleted if any Assets, PMs, Work Orders, or child Locations are attached to it. It must be Soft Deleted or marked "Out of Service".
- **BR-004 (Hierarchy Validation):** A `Room` cannot physically parent a `Building`. Structural logic prevents illogical spatial nesting.
- **BR-005 (Status Inheritance):** If a `Floor` is marked `Under Renovation`, the system logically propagates warnings to all child `Room` locations to suppress non-critical PM generation.

---

## 5. Security Model
- **Isolation:** Users queried strictly by their HRIS/Department `property_id` scope.
- **Access Control:** 
  - `location.view`: Base operational access.
  - `location.create` / `location.edit`: Restricted to Chief Engineers and Ops Directors.
  - `location.delete`: Heavily restricted. Requires ensuring zero financial/operational dependencies exist.

---

## 6. Performance Strategy
**Baseline Estimates:** 100 Properties, 500,000 Locations (Rooms, Corridors, Plant Rooms).
- **Hierarchy Querying:** Using standard `parent_id` recursion is deadly slow. We will implement **Closure Tables** or a robust nested set algorithm to allow retrieving all child descendants of "Tower A" in a single sub-10ms SQL query.
- **Indexing:** BTREE indexes placed heavily on `property_id` + `location_code`, and `property_id` + `location_type`.
- **Caching:** The structural location tree per property is cached in Redis. Adding a new room invalidates and rebuilds the JSON tree automatically.

---

## 7. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Recursive Deadlock** | Critical | Implementing Closure Tables prevents cyclic references where a room accidentally becomes the parent of a building. |
| **Performance Bottleneck** | High | Generating the full dropdown tree for Work Order creation will crash the UI. The API must return the cached tree instantly. |
| **PMS ID Mismatch** | High | If the PMS uses a different naming convention (e.g., 1201A vs 1201-A), syncing fails. We will implement a distinct `pms_alias` field on the Location to map external keys flawlessly. |

---

## 8. Implementation Plan
*(For Architecture Approval - Code will be written post-approval)*
- **Entities & Enums:** `Location`, `LocationClosure`, `LocationTypeEnum`, `LocationStatusEnum`.
- **Services:** `LocationHierarchyService` (handles tree logic and caching), `LocationQRService` (generates deterministic payloads).
- **API:** Endpoints to support CRUD and tree visualization for the PWA.

---

## 9. Open Questions
1. **PMS Alias Strategy:** Do we need to support multiple PMS aliases per room, or is a 1:1 mapping sufficient for the upcoming PMS Gateway?
2. **QR Payload Format:** Should the QR code payload be a direct deep-link URI (e.g., `ivorq://app/location/ulid`) to trigger the PWA instantly, or a raw JSON payload string?

---

## 10. CTO Recommendations
1. **Mandate the Closure Table:** Do not attempt to use simple `parent_id` relations for the Location tree. When an engineer asks "Show me all active WOs in Tower A", a simple `parent_id` requires querying every floor, then every area, then every room recursively. A Closure Table executes this in one highly indexed `JOIN`.
2. **Standardize the QR Structure Now:** Lock in the URI scheme for the QR payload immediately so physical labels can be printed and affixed by operational teams long before the PWA finishes development.
