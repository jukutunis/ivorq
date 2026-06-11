# Department Foundation Implementation Plan (v1.1)

**Document Type:** Master Architecture Blueprint (Revision 1.1)
**Status:** Pending CTO Approval

---

## 1. Revised Architecture & New Entities

The Department Foundation has been significantly expanded to handle SLA enforcement, calendar availability, dynamic property grouping, and granular communication routing.

**Core Entities:**
- **`Department`**: The root operational aggregate.
- **`DepartmentPropertyPivot`**: Establishes a many-to-many mapping for departments servicing multiple properties.
- **`DepartmentSLA`**: Controls mandatory response and resolution timeframes.
- **`DepartmentCalendar` & `DepartmentShift`**: Defines working hours and availability.
- **`DepartmentContact`**: Manages operational communication endpoints (Radio, Hotline, WhatsApp).
- **`DepartmentHierarchy` & `DepartmentRole`**: Retained for structural mapping.

---

## 2. Updated Business Rules & Standards

### 2.1 Department Code Standard
To ensure seamless multi-property reporting and integration with the Universal Document Number Engine, codes strictly follow a standardized schema:
- **Root Codes:** ENG (Engineering), HK (Housekeeping), FO (Front Office), FIN (Finance), HR (Human Resources), SEC (Security), IT (Information Technology), FB (Food & Beverage), PUR (Purchasing).
- **Sub-Departments:** Hyphenated derivations mapping to the parent (e.g., `ENG-ELECTRICAL`, `HK-PUBLICAREA`, `FO-CONCIERGE`).
- **Rules:** The code must be strictly unique *within* its scope (Property, Cluster, or Corporate). When feeding the Document Number Engine, `ENG` automatically seeds WO prefixes (e.g., WO-ENG-2026-00001).

### 2.2 Department Type Enhancement
The `DepartmentTypeEnum` structurally impacts enterprise reporting and Finance integration:
- **Operational:** Cost centers actively executing physical labor (e.g., Engineering, Housekeeping). Highly integrated with PMs and Inventory.
- **Revenue:** Departments directly driving income (e.g., FO, FB). Impact: Revenue Forecast mapping.
- **Support:** Internal administrative teams (e.g., HR, Security). Impact: Zero direct revenue, heavy SLA dependencies.
- **Corporate:** Global entities governing property SOPs. Impact: Costs distributed via corporate allocation journals.
- **SharedService:** Centralized teams executing tasks for multiple properties (e.g., Cluster IT). Impact: P&L expenses proportioned across the `DepartmentPropertyPivot` properties.

---

## 3. SLA, Calendar, and Contact Design

### 3.1 Department SLA
Defines the strict operational timeframes for the Universal Assignment Engine.
- **Response SLA:** Time to acknowledge a Work Order/Incident (e.g., Security = 5 min, Engineering = 15 min).
- **Resolution SLA:** Target time to close the task.
- **Escalation Rules:** If Response SLA breaches, the Assignment Engine immediately elevates the ticket to the defined `DepartmentManager`.

### 3.2 Department Calendar & Availability
Determines *when* the Universal Assignment Engine can logically dispatch tasks.
- **24/7 Operations:** Security, Engineering. Tasks dispatched immediately.
- **Business Hours:** Finance (08:00-17:00). A PM assigned to Finance outside these hours pauses its SLA countdown until the next active `DepartmentShift`.
- **Entities:** `DepartmentCalendar` handles standard availability; `DepartmentShift` handles specialized overnight or split schedules.

### 3.3 Department Contact
The Universal Notification Center requires endpoints independent of a specific `Employee` (HRIS).
- **ContactTypes:** Email, WhatsApp, Hotline, Radio Channel (e.g., "Channel 4"), Emergency Contact.
- **Support:** A Critical Incident triggers the Notification Center to push to the department's "Emergency Contact" and broadcast a dispatch alert via "WhatsApp" and "Radio Channel" instructions.

---

## 4. Multi-Property Strategy (Department Property Pivot)

**CTO Directive:** The `cluster_property_ids` JSON array has been completely stripped.

**Design:** A normalized `DepartmentPropertyPivot` table.
- **Support:** A single `Department` record (e.g., "Cluster IT") can explicitly map to Property A, Property B, and Property C.
- **Benefits:** Massive scalability. Normalization allows database-level cascading, hyper-fast indexed relational queries (e.g., `SELECT * FROM departments WHERE property_id IN (Pivot)`), and absolute security isolation preventing Property D from viewing Cluster IT's records.

---

## 5. Finance Integration Review

**CTO Directive:** Operations does *not* possess the authority to unilaterally create Financial Cost Centers.

**Governance Flow:**
1. Operations Manager creates the `Department` (e.g., "ENG-LANDSCAPING").
2. The department goes live operationally, allowing HRIS assignments and Calendar configurations.
3. **Finance Mapping:** The `Department` record remains financially "unmapped."
4. The Director of Finance evaluates the operational unit, creates the `Cost Center` inside the Finance Suite, and manually maps it back to the `Department`.
5. Only post-mapping can the department execute Inventory part consumption or Capex WOs.

---

## 6. Future Engine Integration Review

- **HRIS:** Heavily relies on `DepartmentHierarchy` and `DepartmentRole` to establish the employee roster.
- **Assignment / Approval Engines:** Completely dependent on the new `DepartmentCalendar` and `DepartmentSLA` to calculate deadlines and escalate stagnant tickets mathematically.
- **WO / PM / Incidents:** Rely on the `Department Code Standard` to generate logical, readable IDs, and route execution to the correct `DepartmentContact` (e.g., Radio Channels for urgent incidents).
- **Housekeeping & Engineering:** Calendar and Shift logic ensures rooms aren't assigned for deep cleaning to a closed sub-department.
- **Visitor Management:** Identifies which Department to notify upon a contractor's arrival via the `DepartmentContact` email/WhatsApp.

---

## 7. Risk Analysis & Mitigation

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **SLA Deadlock** | High | If a department sets a 5-minute Response SLA but operates 08:00-17:00, off-hour incidents will breach instantly. *Mitigation:* The Assignment Engine must enforce SLA calculations strictly against the active `DepartmentCalendar`. |
| **Pivot Table Orphan Records** | Medium | A Property is deactivated, leaving Shared Service pivot maps active. *Mitigation:* Strict foreign key cascading on `DepartmentPropertyPivot`. |
| **Contact Obsolescence** | Medium | A radio channel changes, causing dispatches to fail. *Mitigation:* Notification Center must fall back to the `DepartmentManager` default HRIS contact if a `DepartmentContact` errors out. |
| **Cost Center Gap** | High | Engineering consumes parts before Finance maps the Cost Center. *Mitigation:* Operations API must throw `FinancialMappingRequiredException` blocking part consumption on unmapped departments. |

---

## 8. Updated Implementation Plan

### Entities
- `Department`, `DepartmentPropertyPivot`, `DepartmentHierarchy`, `DepartmentRole`, `DepartmentManager`, `DepartmentSLA`, `DepartmentCalendar`, `DepartmentShift`, `DepartmentContact`, `DepartmentCostCenterMapping`.

### Services
- **`DepartmentSLAService`**: Calculates real-time SLA deadlines against calendars.
- **`DepartmentAvailabilityService`**: Evaluates if a department is currently "On Shift" for dispatch.
- **`DepartmentFinanceMappingService`**: Manages the approval handshake with the Finance suite.

### Security
- Properties can strictly view Departments mapped via the Pivot table. Super-admins manage corporate nodes.

---

## 9. Updated Testing Strategy
- **SLA Computation Testing:** Assert that a 15-minute SLA pauses at 17:00 Friday and resumes at 08:00 Monday based on `DepartmentCalendar`.
- **Pivot Testing:** Verify that querying departments for Property A returns Property A-exclusive departments *and* Shared Service departments linked via the pivot.
- **Finance Integration Testing:** Assert that attempting to deduct Inventory Parts for a Work Order assigned to a financially "unmapped" department explicitly fails.

---

## 10. CTO Recommendations
1. **SLA Calculation Performance:** Calculating business-hour SLAs on the fly for thousands of Work Orders is CPU-intensive. Pre-calculate the `target_response_at` and `target_resolution_at` timestamps exactly once upon Assignment generation, caching the result on the WO record.
2. **Prioritize Pivot Integrity:** Building the `DepartmentPropertyPivot` correctly now is the difference between a multi-property SaaS succeeding or collapsing under query load. Index both `department_id` and `property_id` in the pivot table heavily.
3. **Approval Flow Governance:** Enforce the Finance mapping flow tightly. Operations will complain about the delay, but preventing uncontrolled GL expense leakage justifies the friction.
