# IVORQ Work Order Foundation v2.2C — Implementation Plan (v1.1)

**Document Type:** Architecture & Implementation Blueprint
**Status:** Ready For Final Lock
**Domain:** Operations (Engineering)

---

## 1. Architecture Review

The Work Order (WO) Foundation is the core operational execution engine for IVORQ. All physical maintenance, inspections, repairs, and guest requests converge into this module. It sits structurally downstream from Assets and Preventive Maintenance (PM).

### Core Entities
- **WorkOrder:** The master record (ULID, `property_id` scoped).
- **WorkOrderTask:** Checklist items or discrete steps required to complete the WO.
- **WorkOrderAssignment:** Maps WOs to Departments, Teams, or specific Users.
- **WorkOrderLabor:** Time tracking (Actual vs Planned) for technicians.
- **WorkOrderMaterial:** Tracks consumed spare parts (Contract-ready for Inventory module).
- **WorkOrderApproval:** Gatekeeping for Emergency or high-cost WOs. Supports Linear and Parallel models.
- **WorkOrderSLA:** Tracks response and resolution times against predefined priority matrices and department calendars.
- **WorkOrderEscalation:** Tracks SLA breaches and hierarchical routing (Supervisor → Manager).
- **WorkOrderComment:** Internal communication thread.
- **WorkOrderWatcher:** Subscribers to WO updates (e.g., the User who reported the issue).
- **WorkOrderClosure:** Immutable snapshot of the WO upon final resolution, capturing digital signatures and root cause.

---

## 2. Module Relationships

The Work Order module acts as the nexus for the Operations domain:

| Upstream (Triggers) | Downstream (Outputs) | Future Integrations |
| :--- | :--- | :--- |
| **Asset:** Master record linkage. | **Timeline:** All WO events broadcast to immutable logs. | **Inventory:** Strict contract interface for Stock deduction. |
| **PM Execution:** Failed PMs generate WOs. | **Logbook:** Shift handover highlights open critical WOs. | **Knowledge Base:** Resolved complex WOs become SOPs. |
| **Incident:** CAPA actions generate WOs. | **Media:** Photos/Videos attached directly to WO. | **Contractor/PTW:** Permit-to-Work linkages. |

---

## 3. Business Rules

1. **Isolation:** Strict `property_id` scoping is mandatory. No exceptions.
2. **Key Generation:** ULID primary keys exclusively.
3. **Mandatory Tracing:** `source_type` and `source_id` are mandatory fields on every Work Order to permanently link the origin (e.g., Guest Request, CAPA, PM Exception).
4. **Immutability on Closure:** Once a WorkOrder is transitioned to `Closed`, all fields become immutable. Any further action requires a new WO or an explicit authorized reversal (which generates an audit event).
5. **No Hard Deletes:** Work Orders can never be deleted, only marked as `Cancelled` (soft delete with reason).
6. **Emergency Bypass:** Work Orders flagged as `Emergency` bypass standard approval hierarchies and trigger immediate push notifications.
7. **Guest Integration:** Guest Request WOs must contain reference links to the PMS to track guest satisfaction and room status.
8. **Auditability:** Every status change, assignment, and comment must be captured in `WorkOrderHistory` and broadcast as domain events.

---

## 4. CTO Revisions (v1.1 Additions)

### 4.1 Work Order Number Engine Architecture
- **Auto-Increment Identity:** Work Orders require a human-readable prefix + auto-increment number that resets per property/year (e.g., `WO-PROP1-2026-0001`). 
- **Generation:** Handled via a robust sequence generation service using DB transaction locks or Redis atomic counters to prevent collision.

### 4.2 Cost Tracking Architecture
- **Total Cost of Ownership (TCO):** WOs must aggregate `Labor Cost` + `Material Cost` + `External Service Cost`.
- **Budget Linkage:** When completed, WO costs should be queryable by the Finance/Budget module to compare actual vs forecasted maintenance spending per Asset.

### 4.3 Guest Impact Architecture
- **Impact Flagging:** Explicit boolean flags (`has_guest_impact`, `is_room_out_of_order`) allowing Front Office to intercept and block room check-ins.
- **Resolution Callback:** Closing a Guest Impact WO triggers a webhook/event back to the PMS signaling the room is ready for inspection.

### 4.4 Inventory Integration Contract
- **Interface Only:** The WO Material system will use a strict contract/interface approach. No actual `inventory_items` tables will be referenced via Foreign Keys. Instead, materials will be captured via polymorphic or isolated UUID fields pending the full Inventory Foundation implementation.

### 4.5 SLA Department Calendars
- **Business Hours:** SLAs will respect configurable Department Calendars (e.g., Engineering is 24/7, but Landscaping is Mon-Fri 8 AM - 5 PM). Clocks pause during off-hours unless overridden by `Emergency` priority.

### 4.6 Approval Engine Modes
- **Linear Approvals:** Tiered (Supervisor → Manager → Chief Engineer).
- **Parallel Approvals:** Multi-department sign-offs (e.g., Finance AND Engineering must approve simultaneously for a $10,000+ repair).

---

## 5. Implementation Phases

- **Phase 1: Database Scaffolding:** Migrations, Models, Factories, and Seeders (including `source_type`/`id` and numbering rules).
- **Phase 2: Numbering & Core Domain Logic:** Number generator Service, Repositories, State Machine.
- **Phase 3: Assignment, Approval & SLA Engine:** Implement Linear/Parallel approvals and calendar-aware SLA calculators.
- **Phase 4: Labor, Materials & Costs:** Time tracking, cost aggregation, and Inventory contract interfaces.
- **Phase 5: Events & Observers:** Timeline broadcasting, Guest Impact webhooks.
- **Phase 6: API & Policies:** Secure endpoints with Spatie Permissions and Property Isolation.
- **Phase 7: UI/UX Foundation:** Implementing Slide-over forms, Filter+Grid data tables, and Mobile PWA views following Design System v1.1.
- **Phase 8: Testing:** 100% Feature and Unit test coverage for the WO lifecycle.

---

## 6. Updated Dependency Matrix (v1.1)

| Dependency | Type | Impact |
| :--- | :--- | :--- |
| `Modules/Operations/AssetManagement` | Hard | Mandatory for Asset linkage and TCO tracking. |
| `Modules/Operations/Maintenance` | Hard | PM Exceptions generate WOs via mandatory `source_type`. |
| `Modules/Operations/Media` | Soft (Event) | Photo/Signature attachments. |
| `Modules/Operations/Timeline` | Soft (Event) | Audit logging of WO states. |
| `Modules/Operations/Inventory` | Interface Only | Strict contract boundary. No direct DB relations. |
| `Modules/Finance/Budgeting` | Contract | WO Cost Tracking aggregations. |
| `Modules/Integrations/PMS` | Webhook | Guest Impact triggers (Oversight/Room Status). |

---

## 7. Updated Risk Assessment (v1.1)

> [!CAUTION]
> **Identified Implementation Risks**
> - **Number Engine Collisions:** Race conditions on high-volume property WO generation. Must use atomic counters or strict row-level DB locks (`SELECT FOR UPDATE`).
> - **SLA Calendar Complexity:** Pausing and resuming SLA timers across overlapping shifts, weekends, and holidays can lead to extreme query complexity. Must rely on caching or pre-computed deadline timestamps (`target_resolution_at`).
> - **Parallel Approval Deadlocks:** Ensuring one rejection cleanly invalidates the parallel chain without stranding the Work Order in a ghost state.

---

## 8. Updated Readiness Review

| Criteria | Status | Notes |
| :--- | :--- | :--- |
| **Asset Foundation** | ✅ Validated | Asset IDs available for linking. |
| **PM Foundation** | ✅ Validated | PM Exceptions ready to trigger WOs. |
| **Governance Locked** | ✅ Validated | Master architecture and directory structures established. |
| **Design System Locked**| ✅ Validated | v1.1 standards explicitly defined. |
| **Architecture Lock** | ✅ Validated | All CTO revisions applied (Cost, SLA Calendars, Interfaces). |

**Sprint Status: READY FOR IMPLEMENTATION** (Status: Ready For Final Lock)
