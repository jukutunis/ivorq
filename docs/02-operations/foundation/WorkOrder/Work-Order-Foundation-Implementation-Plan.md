# IVORQ Work Order Foundation v2.2C — Implementation Plan

**Document Type:** Architecture & Implementation Blueprint
**Status:** Pending CTO Approval
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
- **WorkOrderApproval:** Gatekeeping for Emergency or high-cost WOs.
- **WorkOrderSLA:** Tracks response and resolution times against predefined priority matrices.
- **WorkOrderEscalation:** Tracks SLA breaches and hierarchical routing (Supervisor → Manager).
- **WorkOrderComment:** Internal communication thread.
- **WorkOrderWatcher:** Subscribers to WO updates (e.g., the User who reported the issue).
- **WorkOrderClosure:** Immutable snapshot of the WO upon final resolution, capturing digital signatures and root cause.

---

## 2. Module Relationships

The Work Order module acts as the nexus for the Operations domain:

| Upstream (Triggers) | Downstream (Outputs) | Future Integrations |
| :--- | :--- | :--- |
| **Asset:** Master record linkage. | **Timeline:** All WO events broadcast to immutable operational logs. | **Inventory:** Stock deduction on material usage. |
| **PM Execution:** Failed PMs generate WOs. | **Logbook:** Shift handover highlights open critical WOs. | **Knowledge Base:** Resolved complex WOs become SOPs. |
| **Incident:** CAPA actions generate WOs. | **Media:** Photos/Videos attached directly to WO. | **Contractor/PTW:** Permit-to-Work linkages. |

---

## 3. Business Rules

1. **Isolation:** Strict `property_id` scoping is mandatory. No exceptions.
2. **Key Generation:** ULID primary keys exclusively.
3. **Immutability on Closure:** Once a WorkOrder is transitioned to `Closed`, all fields become immutable. Any further action requires a new WO or an explicit authorized reversal (which generates an audit event).
4. **No Hard Deletes:** Work Orders can never be deleted, only marked as `Cancelled` (soft delete with reason).
5. **Emergency Bypass:** Work Orders flagged as `Emergency` bypass standard approval hierarchies and trigger immediate push notifications.
6. **Guest Integration:** Guest Request WOs must contain reference links to the PMS to track guest satisfaction and room status.
7. **Auditability:** Every status change, assignment, and comment must be captured in `WorkOrderHistory` and broadcast as domain events.

---

## 4. Open Questions

> [!WARNING]
> **Pending CTO Clarification**
> 1. **Inventory Schema:** Do we mock the `inventory_items` table structure now to support `WorkOrderMaterial`, or strictly use polymorphic relations (e.g. `material_type`, `material_id`) pending the Inventory Foundation sprint?
> 2. **SLA Calendars:** Should SLA calculations account for specific Property Business Hours (e.g., pausing the clock overnight), or are they strictly 24/7 calculations for v1.0?
> 3. **Approval Flows:** Are approvals linear (A → B → C) or do we need parallel approval support for high-cost CAPA work orders?

---

## 5. CTO Recommendations

- **Polymorphic Origin Tracking:** Introduce a `source_type` and `source_id` on the WorkOrder table to trace whether it originated from a PM Exception, an Incident CAPA, or a Guest Request.
- **Event-Driven Architecture:** Do not tightly couple WO status updates to notification logic. Use Laravel Events (e.g., `WorkOrderEscalated`) and let isolated Listeners handle SMS/Push/Email.
- **State Machine:** Implement a strict State Machine pattern for Work Order statuses (`Draft` → `Pending Approval` → `Assigned` → `In Progress` → `Paused` → `Resolved` → `Closed`) to prevent invalid transitions.

---

## 6. Sprint Readiness

| Criteria | Status | Notes |
| :--- | :--- | :--- |
| **Asset Foundation** | ✅ Validated | Asset IDs available for linking. |
| **PM Foundation** | ✅ Validated | PM Exceptions ready to trigger WOs. |
| **Governance Locked** | ✅ Validated | Master architecture and directory structures established. |
| **Design System Locked**| ✅ Validated | v1.1 standards explicitly defined. |
| **Database Strategy** | ✅ Validated | ULID, Property Isolation rules confirmed. |

**Sprint Status: READY FOR IMPLEMENTATION** (Pending Plan Approval)

---

## 7. Implementation Phases

- **Phase 1: Database Scaffolding:** Migrations, Models, Factories, and Seeders for all WO entities.
- **Phase 2: Core Domain Logic:** Repositories and Services (State Machine, Assignment Engine, SLA Engine).
- **Phase 3: Labor & Materials:** Time tracking logic and Inventory event contracts.
- **Phase 4: Events & Observers:** Hooking up `WorkOrderCreated`, `WorkOrderEscalated`, `WorkOrderCompleted` to the Timeline system.
- **Phase 5: API & Policies:** Secure endpoints with Spatie Permissions and Property Isolation.
- **Phase 6: UI/UX Foundation:** Implementing Slide-over forms, Filter+Grid data tables, and Mobile PWA views following Design System v1.1.
- **Phase 7: Testing:** 100% Feature and Unit test coverage for the WO lifecycle.

---

## 8. Risks

> [!CAUTION]
> **Identified Implementation Risks**
> - **State Transition Complexity:** Without a robust State Machine, race conditions could allow a user to pause a WO that is already closed.
> - **Performance with SLAs:** Continuous SLA polling can degrade DB performance. Escalations should be managed via scheduled command (Cron) querying indexed `target_resolution_at` timestamps, not dynamic calculations.
> - **Mobile Offline Sync:** Resolving a WO offline while a manager reassigns it online requires clear conflict resolution (First-Sync-Wins strategy).

---

## 9. Dependency Matrix

| Dependency | Type | Impact |
| :--- | :--- | :--- |
| `Modules/Operations/AssetManagement` | Hard | Mandatory for Asset linkage. |
| `Modules/Operations/Maintenance` | Hard | PM Exceptions generate WOs. |
| `Modules/Operations/Media` | Soft (Event) | Photo/Signature attachments. |
| `Modules/Operations/Timeline` | Soft (Event) | Audit logging of WO states. |
| `Modules/Operations/Inventory` | Mocked (Contract) | Material deduction (future). |

---

## 10. Future Expansion Strategy

- **IoT Integration:** Building Management Systems (BMS) automatically generating WOs based on anomalous temperature/pressure readings.
- **Predictive Maintenance:** Machine Learning models analyzing WO Labor and Material histories to predict future Asset failures.
- **Contractor Portal:** External vendors receiving limited-scope access to specific WOs to upload their own PTW (Permit to Work) and completion photos.
