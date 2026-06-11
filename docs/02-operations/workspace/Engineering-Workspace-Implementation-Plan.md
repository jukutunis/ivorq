# IVORQ Engineering Workspace v2.3 — Implementation Plan

**Role:** Hospitality Operations Architect / CMMS Architect
**Layer:** Orchestration Layer
**Status:** Ready For CTO Review

---

## 1. Architecture Review
The Engineering Workspace is the central orchestration layer connecting previously validated and locked backend modules (`Asset`, `Preventive Maintenance`, `Work Order`, `Incident`, `Logbook`, `Inventory`). It sits above the foundational data layer and acts as the "Command Cockpit" for the property's entire engineering department. It uses the UI/UX tokens and architectures established in **IVORQ Design System v1.1**.

### Data Flow & Composition
- **Aggregator Services:** The workspace will utilize new View Models and Aggregator Services (e.g., `EngineeringDashboardService`) to stitch together data from isolated domains (fetching Asset health, PM compliance, and WO queues concurrently) without breaking module boundaries.
- **Event-Driven UI:** WebSockets (via Laravel Echo) will push real-time updates to the Workspace Timeline, Incident Alerts, and SLA warnings.

---

## 2. Workspace Layout
Conforming to **IVORQ Design System v1.1**, the workspace abandons deep nested menus in favor of a top-level Mega Menu and Contextual Navigation.

**Top Navigation Context (Engineering Domain):**
- **Dashboard:** The command center view.
- **Assets:** Access to the Asset Grid, hierarchical trees, and lifecycle metrics.
- **Maintenance (PM):** Access to PM schedules, checklists, and compliance tracking.
- **Work Orders (WO):** Central grid for WO assignment, SLA tracking, and resolution.
- **Incidents:** High-priority, guest-impacting issues.
- **Logbook:** Shift handovers and daily operational logs.

---

## 3. User Journeys & Role-Based Views

### Technician View
**Focus:** Execution, mobility, and rapid data entry.
- **My Tasks Panel:** A combined stream of Assigned WOs and PM executions due today.
- **Quick Update UI:** Slide-over (Drawer) for adding comments, labor hours, and used materials to a WO.
- **Mobile First actions:** 
  - Scan QR code to pull up Asset History.
  - Upload Photo directly from camera for Incident/WO evidence.
  - Digital Signature capture for PM completion.
- **Offline Actions:** Support for caching "My Tasks" and synchronizing completion statuses upon reconnection.

### Supervisor View
**Focus:** Resource allocation, SLA monitoring, and quality control.
- **Team Load:** Visual indicator of WO distribution across technicians.
- **Escalations Queue:** Immediate visibility of WOs breaching SLA or marked as High Guest Impact.
- **Approvals Drawer:** Slide-over notification center for approving material requests, overtime, or closure sign-offs.
- **Department Timeline:** A unified vertical feed of all shift activities.

### Chief Engineer (CE) & Director of Engineering (DOE) View
**Focus:** High-level metrics, compliance, and asset health.
- **Asset Health KPI:** Summary of critical assets requiring capital expenditure (CapEx) or frequent repairs.
- **PM Compliance:** Percentage of PMs completed on schedule.
- **Budget Impact:** YTD maintenance costs vs. budget (integrating with Finance/Inventory).
- **Guest Impact Matrix:** Heatmap of engineering issues affecting guest satisfaction scores.

### Cluster Engineering View
**Focus:** Multi-property oversight.
- **Global Property Selector:** Ability to view aggregated metrics across the region or drill down into a specific hotel.
- **Standardization Compliance:** Tracking adherence to global PM checklists.

---

## 4. Engineering Command Center
A live "Operations Board" designed to be cast to a large monitor in the Engineering Office.
- **Live Operations Board:** Auto-refreshing grid of active technicians and their current assignments.
- **Priority Score Ranking:** WOs sorted dynamically by the Priority Score Engine (accounting for VIP status, Location, and SLA).
- **Emergency Queue:** Flashing/highlighted rows for `Crimson/Rose` (Danger) alerts.

---

## 5. Dashboard Design
Following the `Data First` and `Low Click Count` principles:
- **Top Row (KPI Cards):** 
  - *Open Work Orders* (Numeric + Delta vs Yesterday).
  - *PM Compliance %* (Color-coded Green/Amber/Red).
  - *Critical Incidents* (Pulsing Red indicator if > 0).
- **Middle Row (Action Queues):**
  - *Today's Priorities:* Tabbed lists of "My Open WOs", "Overdue WOs", "Upcoming PMs".
  - *Inventory Alerts:* Low stock warnings for critical spares.
- **Right Side (Timeline & Actions):**
  - *Live Timeline Feed:* Real-time activity stream.
  - *Quick Action Buttons:* Create WO, Create Incident, Scan Asset.

---

## 6. Mobile Design (PWA First)
- **One Hand Operation:** Primary actions (e.g., "Complete Task", "Add Material") located at the bottom of the screen.
- **QR First:** Persistent floating action button (FAB) for QR scanning.
- **Photo First:** Incident creation defaults to opening the camera.
- **Connectivity:** Service Workers will cache the active `Asset` list and assigned `Work Orders` for basement/offline operations.

---

## 7. Integration Matrix
| Module | Workspace Integration Point | Contract Interface |
| :--- | :--- | :--- |
| **Asset** | Asset details in WO/PM slide-overs, QR scanning. | `AssetManagementContract` |
| **Work Order** | Assignment queues, SLA countdowns, Labor tracking. | `WorkOrderContract` |
| **PM** | Compliance dashboards, Checklist execution UI. | `PreventiveMaintenanceContract` |
| **Incident** | Emergency alert banners, Guest Impact matrix. | *Pending Contract* |
| **Logbook** | Shift handover summaries, daily timeline. | *Pending Contract* |
| **Inventory** | Low stock alerts, Material issuance on WOs. | *Pending Contract* |

---

## 8. Universal Search & Quick Create
- **Ctrl+K / Cmd+K:** Universal command palette.
  - *Lookups:* "WO-1024", "Chiller Pump 2".
  - *Actions:* "Create Work Order", "Log Incident".
- **Global Quick Create:** Top navigation `+` button opens context-aware Drawers (Slide-overs). No full-page redirects.

---

## 9. Open Questions & CTO Recommendations

> [!WARNING]
> **Open Question 1:** For the "Offline First" Mobile Workspace, what is the maximum cache size or scope we should permit for local device storage? (e.g., Only assigned tasks for today, or the entire property asset registry?)

> [!IMPORTANT]
> **Open Question 2:** Should the Cluster Engineering View rely on synchronized Data Warehouse metrics, or perform live cross-database queries against property shards?

**CTO Recommendations:**
1. **Frontend State Management:** Mandate the use of a robust global state manager (e.g., Zustand or Pinia) or rely heavily on Inertia.js shared props for the Universal Search and Notification Drawer to prevent redundant API calls.
2. **WebSocket Infrastructure:** Adopt Laravel Reverb or Pusher immediately to support the "Live Operations Board" requirements without polling.
3. **Lazy Loading:** Enforce lazy loading for the "Timeline Feed" and "Photo Gallery" components to maintain sub-second TTI (Time to Interactive).

---

## 10. Sprint Readiness
| Metric | Status |
| :--- | :--- |
| Backend Foundation Locked | **Yes** (Asset, PM, WO validated) |
| UI/UX Design System Locked | **Yes** (v1.1 active) |
| Integrations Defined | **Yes** |
| Architecture Approved | **Pending CTO Review** |

**Status:** Ready For CTO Review.
