# SPRINT 14.8.1 — BEO DISTRIBUTION DASHBOARD ARCHITECTURE REVISION

**Classification**: KEEP  
**Domain**: Sales & Event Management / Operations Execution  

## Executive Summary
This architecture revision refines the operational boundaries of the BEO Distribution Dashboard following the Sprint 14.8.0 audit. The core focus is establishing a highly accountable, state-driven distribution engine that bridges Sales formulation with inter-departmental execution. This document solidifies the dedicated aggregates, acknowledgement engines, escalation routing, and enterprise-grade integration strategies necessary for hospitality execution.

---

## Architecture Revisions

### 1. BEO Distribution Aggregate Decision
**Decision: Dedicated Distribution Aggregate (`BEODistribution`)**
We reject the concept of overloading the `BEOIssueLog` to handle distribution state. The `BEOIssueLog` is strictly designed for operational constraints, blockers, and ad-hoc communication. A dedicated `BEODistribution` aggregate must be introduced to cleanly manage the lifecycle state machine (Sent, Delivered, Viewed, Acknowledged, Superseded). This ensures single-responsibility and clean auditing.

### 2. Acknowledgement Ownership
**Decision: Both (Department Level & User Level)**
Accountability in hospitality requires a dual approach:
1. **Department Level (Mandatory):** A designated Department Head or Supervisor must explicitly sign off on the BEO, committing the department to its execution.
2. **User Level (Read-Receipt/Optional):** The system will passively track which individual users have viewed the distributed payload to provide operational visibility to management, mitigating "I didn't see the update" excuses on the banquet floor.

### 3. Escalation Strategy
**Decision: Hybrid Escalation Model (Time & Role Based)**
Escalations are triggered temporally but routed hierarchically.
- **Time-Based Triggers:** e.g., Unacknowledged at T-48 hours, T-24 hours, and T-4 hours before the function start time.
- **Role-Based Routing:** An unacknowledged BEO escalates first to the Department Head, then to the Director of Events, and finally to the General Manager/Executive Committee if critical.

---

## Ownership Matrix
| Domain / Module | Entity / Concept | Responsibility |
| :--- | :--- | :--- |
| **Sales & Event Management** | `BEODistribution` | Core aggregate. Owns the payload and state. |
| **Sales & Event Management** | `BEOAcknowledgement` | Child of Distribution. Tracks department sign-off. |
| **Foundation Engine** | `WorkflowEngine` | Orchestrates the state transitions (Draft -> Published). |
| **Foundation Engine** | `NotificationEngine` | Transports the alerts (Push, Email, In-App). |

---

## Department Matrix
| Department | Primary View Projection | Mandatory Acknowledgement | Escalation Path |
| :--- | :--- | :--- | :--- |
| **Kitchen** | Menu, Dietary, Timing | YES | Exec Chef → F&B Dir |
| **Banquet** | Setup, Menu, Timeline | YES | Banquet Mgr → F&B Dir |
| **Stewarding**| Equipment, Headcount | YES | Chief Steward → Exec Chef |
| **Engineering**| AV, Power, Rigging | YES | Chief Eng → Dir of Ops |
| **Housekeeping**| Buffers, Turnarounds | YES | Exec Housekeeper → Dir of Ops |
| **Front Office**| VIPs, Group Blocks | NO (Info Only) | FOM → Dir of Rooms |
| **Security** | Access, VIP Protection| YES (If required) | Dir of Security → GM |

---

## Escalation Matrix
| Trigger Condition | Severity | Primary Notification | Escalated Notification |
| :--- | :--- | :--- | :--- |
| Unacknowledged (T-48 Hrs) | NOTICE | Department Head | N/A |
| Unacknowledged (T-24 Hrs) | WARNING | Department Head | Director of Events |
| Unacknowledged (T-4 Hrs) | CRITICAL | Department Head | General Manager / Exec |
| Critical Revision (T-24 Hrs) | CRITICAL | Department Head | Director of Events |

---

## Notification Matrix
| Channel | Primary Use Case | Handled By |
| :--- | :--- | :--- |
| **In-App** | Primary operational queue and acknowledgement prompts. | Notification Engine |
| **Push Notification**| Urgent real-time alerts for floor staff (Banquets/Security). | Notification Engine |
| **Operations Board** | BOH digital signage broadcast (Visual Warnings). | Operations Calendar |
| **Email** | External distribution (AV Partners, Florists) & Exec Digest. | Notification Engine |
| **WhatsApp/SMS** | *Deferred.* Future integration for instant mobile alerts. | Integration Engine |

---

## Workflow Matrix
The `BEODistribution` lifecycle binds directly to the Foundation Workflow Engine:
1. **DRAFT:** Work in progress by Sales.
2. **PENDING_APPROVAL:** Awaiting internal Director of Sales signature.
3. **PUBLISHED:** Locked as v1.0. Read-only snapshot generated.
4. **SENT/DISTRIBUTED:** Queued into Department Inboxes.
5. **ACKNOWLEDGED:** Department signature recorded.
6. **REVISED:** Triggers Superseded state on v1.0, launches v2.0 queue.
7. **COMPLETED:** Post-event historical lock.
8. **CANCELLED:** Immediate operational halt broadcast.

---

## Enterprise Integration & Readiness

### Universal Search Integration
Fully compatible. The dashboard will register with the Foundation Search Engine, allowing unified, indexed searching by:
- `BEO Number` (e.g., BEO-2026-1042)
- `Event Name` (e.g., "Apple Keynote")
- `Account Name` (e.g., "Apple Inc.")
- `Venue` (e.g., "Grand Ballroom")
- `Acknowledgement Status` (e.g., "status:unacknowledged dept:kitchen")

### Operations Board Integration
Highly ready. The `OperationsCalendar` BFF will consume `BEODistribution` status. Unacknowledged BEOs or Critical Revisions will project directly onto the BOH timeline as flashing Warning/Critical blocks.

### Mobile Readiness & Multi Property
- **Mobile:** The architecture relies on standardized DTOs and API-first JSON endpoints. This ensures zero friction for future iOS/Android native app consumption by Banquet Captains.
- **Multi-Property:** The `BEODistribution` aggregate inherits `property_id` from the parent Event. Strict global scoping policies will guarantee inter-property isolation, while allowing authorized Area Directors cluster-level views.

---

## Enterprise Risks
1. **Notification Fatigue:** Emitting push notifications for every minor typographical BEO revision will cause departments to ignore the system. The Delta engine *must* categorize revisions by severity.
2. **Read-Only Lock Violations:** A distributed BEO must be cryptographically or programmatically locked. Editing a Distributed BEO without triggering a formal Revision creates massive liability.

---

## Recommended Sprint 14.8.2 Scope
1. Scaffold `BEODistribution` and `BEOAcknowledgement` entities and migrations.
2. Implement `DistributionLifecycle` state machine (Draft -> Published -> Sent).
3. Develop the Revision Delta Engine (to categorize revision severity).
4. Implement `BEODistributionRepository` with strict property scoping.

---

## Final Recommendation & Status
**Status: APPROVED**

The architectural boundaries are clean, the escalation strategy is enterprise-grade, and the decision to implement a dedicated `BEODistribution` aggregate protects the integrity of the Sales domain. Proceed to implementation phase.
