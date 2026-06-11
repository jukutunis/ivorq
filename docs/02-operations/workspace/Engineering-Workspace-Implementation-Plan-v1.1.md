# IVORQ Engineering Workspace v2.3 Revision 1.1 — Implementation Plan

**Role:** Hospitality Operations Architect / CMMS Architect
**Layer:** Orchestration Layer
**Status:** Ready For Final CTO Lock

---

## 1. Architecture Review
The Engineering Workspace is the central orchestration layer connecting previously validated and locked backend modules (`Asset`, `Preventive Maintenance`, `Work Order`, `Incident`, `Logbook`, `Inventory`). It acts as the "Command Cockpit" for the property's entire engineering department. It relies entirely on the UI/UX tokens established in **IVORQ Design System v1.1**.

### Data Flow & Composition
- **Aggregator Services:** View Models and Aggregator Services (e.g., `EngineeringDashboardService`) stitch together data from isolated domains without breaking module boundaries.
- **Workspace Priority Engine:** A new multi-variable scoring model that synthesizes priority across modules.
- **Event-Driven UI:** WebSockets (via Laravel Reverb/Echo) push real-time updates to Timeline Feeds, Incident Alerts, and SLA warnings.

---

## 2. Workspace Layout
The workspace utilizes a top-level Mega Menu and Contextual Navigation, avoiding deep nested sidebars.

**Top Navigation Context (Engineering Domain):**
- **Dashboard:** The command center view.
- **Assets:** Access to the Asset Grid, hierarchical trees, and lifecycle metrics.
- **Maintenance (PM):** PM schedules, checklists, and compliance tracking.
- **Work Orders (WO):** Central grid for WO assignment, SLA tracking, and resolution.
- **Incidents:** High-priority, guest-impacting issues.
- **Logbook:** Shift handovers and daily operational logs.

---

## 3. Dedicated Operational Widgets & Boards

### Shift Handover Widget
Integrated directly with the Logbook and Timeline foundations, this widget ensures seamless transitions between shifts.
- **Open Handover:** Active shift notes currently being drafted.
- **Pending Acknowledgement:** Handovers requiring signature from the incoming supervisor.
- **Acknowledged Handover:** Historical, signed-off handovers.
- **Critical Handover Notes:** Priority items pinned to the top of the feed.
- **Unread Handover Items:** Real-time indicator for team members coming on shift.

### My Area Widget
To reduce noise and improve productivity, technicians are routed to specific zones rather than viewing the entire property.
- **My Areas:** E.g., Pool Area, Main Plant Room.
- **My Buildings / My Floors:** E.g., Tower A, Guest Rooms 101-120.
- **My Rooms / My Equipment:** E.g., Boiler Room, Kitchen.

### Guest Impact Board
A dedicated operational board evaluating engineering issues through the lens of guest experience.
- **Metrics:** Room Out Of Order (OOO), Room Out Of Service (OOS), VIP Room Issues, Guest Complaints.
- **Impact Flow:** Guest Impact Work Orders, Guest Impact Incidents.
- **Visualization:** Visual Heatmap, Priority Ranking, Escalation Indicators.
- **Integrations:** PMS, Work Orders, Incidents.

### Asset Health Board
A dedicated command board for Asset lifecycle tracking.
- **Metrics:** High Risk Assets, Warranty Expiring, Frequent Failure Assets.
- **Active Issues:** Assets With Open WO, Assets With Repeated Incidents.
- **Scoring:** Asset Criticality Ranking, Asset Condition Ranking.
- **Integrations:** Asset Foundation, PM Foundation, Work Order Foundation.

---

## 4. Engineering Command Center V2
An expanded "Operations Board" designed to be cast to large monitors in the Engineering Office, synthesizing all data streams into a unified emergency matrix.
- **Live Operations Board:** Active technicians and current assignments.
- **Guest Impact Board:** Heatmap of guest-facing failures.
- **Asset Health Board:** Visual tracking of critical infrastructure.
- **Emergency Queue:** Immediate life-safety or catastrophic failure alerts.
- **Critical Incident Queue:** High-priority incident stream.
- **Overdue Queues:** Separate feeds for Overdue PM and Overdue WO.
- **Shift Handover Queue:** Real-time visibility into shift transition status.
- **Approval Queue:** Pending material or overtime approvals.

---

## 5. Workspace Priority Model
A dedicated engine to compute real-time operational urgency.
- **Inputs:** Work Order Priority Score, Guest Impact, Asset Criticality, Incident Severity, SLA Breach Risk.
- **Outputs:** Today's Top Priorities (Technician Dashboard), Engineering Command Center Ranking, Emergency Queue Ranking.

---

## 6. User Journeys & Role-Based Views

### Technician View
**Focus:** Execution, mobility, and noise reduction.
- **My Tasks Panel:** Combined stream of Assigned WOs and PMs due today.
- **My Area Widget:** Filters all global data down to assigned zones.
- **Mobile First Actions:** QR Scanning, Camera Upload, Signature Capture.

### Supervisor View
**Focus:** Resource allocation, SLA monitoring, and quality control.
- **Team Load:** Visual indicator of WO distribution.
- **Escalations Queue & Approvals Drawer.**
- **Shift Handover Widget.**

### Chief Engineer (CE) & Director of Engineering (DOE) View
**Focus:** High-level metrics, compliance, and asset health.
- **Asset Health Board & Guest Impact Board.**
- **PM Compliance & Budget Impact.**

### Cluster Engineering View
**Focus:** Multi-property oversight without impacting transactional database performance.
- **Architecture (CTO Mandate):** NO live cross-database queries.
- **Data Flow:** Uses the Data Warehouse with Nightly Sync or Near Realtime Sync.
- **Dashboards:** Cluster Dashboard, Regional Dashboard, Portfolio Dashboard.

---

## 7. Mobile Strategy (PWA First) & Offline Cache
- **PWA First:** Installable via browser, One-Hand Operation, Touch-Friendly (44x44px targets).
- **Hardware Integration:** QR First (floating FAB), Photo First (direct camera integration).
- **Offline Cache Strategy (CTO Mandate):**
  - **DO NOT CACHE:** Entire Asset Registry, Entire Property Dataset, Entire Media Library.
  - **CACHE ONLY:** Assigned Tasks, Assigned WO, Assigned PM, Recent Assets, Recent Locations.
  - **Maximum Retention:** 7 Days Local Cache.

---

## 8. Integration Matrix
| Module | Workspace Integration Point |
| :--- | :--- |
| **Asset** | Asset Health Board, Mobile QR scanning, Recent Asset cache. |
| **Work Order** | Assigned Tasks, Overdue Queues, Guest Impact WOs. |
| **PM** | Assigned PMs, Compliance Dashboards, Overdue PM Queue. |
| **Incident** | Critical Incident Queue, Guest Impact Incidents. |
| **Logbook** | Shift Handover Widget, Unread Handover Items. |
| **Inventory** | Low stock alerts, Material issuance approvals. |
| **PMS** | Guest Impact Board (OOO, OOS, VIP Rooms). |

---

## 9. Open Questions & CTO Recommendations

> [!TIP]
> **Resolved Architecture Constraints:** Offline caching has been strictly bounded to 7 days for Assigned Tasks only. Cluster querying has been offloaded to a synchronized Data Warehouse. 

> [!WARNING]
> **Open Question 1:** For the `WorkspacePriorityEngine`, what should be the mathematical weight distribution between `SLA Breach Risk` vs. `Asset Criticality` when ranking the Emergency Queue?

**CTO Recommendations:**
1. **Frontend State Management:** Mandate robust global state managers to handle the `WorkspacePriorityEngine` outputs on the client side, preventing excessive REST polling.
2. **Data Warehouse Infrastructure:** Establish the ELT (Extract, Load, Transform) pipeline for the Cluster Engineering View immediately to prepare for the Near Realtime Sync requirements.

---

## 10. Sprint Readiness
| Metric | Status |
| :--- | :--- |
| Backend Foundation Locked | **Yes** (Asset, PM, WO, Audit validated) |
| UI/UX Design System Locked | **Yes** (v1.1 active) |
| Integrations Defined | **Yes** |
| Workspace Blueprints Drafted | **Yes** (v1.1) |

**Status:** Ready For Final CTO Lock
