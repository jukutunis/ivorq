# Checklist Foundation Implementation Plan (v2.1E)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Checklist Foundation is the universal compliance, inspection, and verification engine for IVORQ.
**Why it must exist first:**
- **Preventive Maintenance (PM):** Requires a checklist to define step-by-step equipment servicing procedures.
- **Work Orders:** Require structured completion verification.
- **Housekeeping:** Relies on checklists for daily Room Inspections and Deep Cleaning protocols.
- **Permit To Work (PTW):** Requires rigid safety checks (e.g., Hot Work protocols) before execution.
- **Audits & QA:** Demand quantifiable scoring mechanisms across all properties.
This Foundation acts as the independent core logic. PM, Housekeeping, and WO modules will depend on *it*, not the other way around.

---

## 2. Architecture Design & Entity Relationships

**Core Entities:**
- **`ChecklistTemplate`**: The master blueprint containing configuration metadata.
- **`ChecklistVersion`**: The immutable snapshot of the Template.
- **`ChecklistSection` & `ChecklistItem`**: The grouped questions/tasks attached to a Version.
- **`ChecklistExecution`**: A unique runtime instance assigned to a user/target.
- **`ChecklistResponse`**: The answers provided by the user for an Execution.
- **`ChecklistEvidence`**: Polymorphic pivot binding the execution explicitly to the Media Foundation.
- **`ChecklistApproval`**: Multi-tier sign-offs required to transition statuses.
- **`ChecklistException`**: Explicit defect records generated automatically when critical items fail.

---

## 3. Versioning Strategy (Execution Snapshotting)
**CTO Mandate:** Historical executions must NEVER change even if the SOP/Template is altered.
- **Strategy:** Every execution is bound strictly to a `ChecklistVersion` ID, not a `ChecklistTemplate` ID.
- **Flow:** If the Chief Engineer adds a new task to the "Chiller PM" template, the system creates `Version 2`. All new PMs clone `Version 2`. Historical PMs from last year remain eternally bound to `Version 1`. This guarantees absolute historical integrity during legal audits.

---

## 4. Execution Strategy
**Lifecycle:**
`Draft` ➔ `In Progress` ➔ `Submitted` ➔ (Optional: `Rejected` / `Approved`) ➔ `Completed`.
Alternative States: `Cancelled`, `Overdue`.
- **Snapshot Logic:** Upon creation (`Draft`), the entire schema of the `ChecklistVersion` is JSON-snapshotted into the `ChecklistExecution` row to ensure 100% database decoupling during fast mobile reads.
- **Audit Integration:** Every status transition automatically fires an immutable event into the **Timeline Foundation**.

---

## 5. Evidence Strategy & Media Integration
Checklists tightly couple with the Media Foundation (v2.1C) to prove compliance.
- **Supported Checks:** Pass/Fail, Multiple Choice, Currency, Text.
- **Evidence Mandates:** Certain items flag `requires_photo` or `requires_signature`.
- **Contextual Constraints:**
  - **GPS Verification:** The PWA forces coordinate capture. If the GPS differs from the assigned `Location` by >50 meters, the item requires Supervisor override.
  - **QR/Barcode Verification:** The technician MUST scan the physical Asset tag to unlock the checklist, guaranteeing they are physically in front of the machinery.

---

## 6. Approval Strategy & Exceptions
Not all checklists auto-complete. High-risk actions require multi-level approvals.
- **Exception Workflow:** If an item marked "Critical" fails (e.g., "Fire Door latch broken"), the system automatically spawns a `ChecklistException` record.
- **Approval Workflow:** The execution pauses at `Submitted`. It routes to a Supervisor. If an Exception exists, the engine demands a mandatory Supervisor comment and potentially an escalation to the Director before transitioning to `Completed`.

---

## 7. Mobile PWA Strategy
Technicians execute checklists in deep basements without connectivity.
- **Offline Mode:** The PWA downloads assigned `ChecklistExecution` JSON payloads via IndexedDB.
- **Offline Evidence:** Photos and Signatures are cached locally.
- **Background Sync:** A sync queue processes payloads chronologically when WiFi restores.
- **Conflict Handling:** Because `ChecklistExecution` targets a specific technician instance, conflicts are virtually zero.

---

## 8. Security Model
- **Isolation:** `property_id` strict enforcement globally.
- **Legal Hold:** Fully integrates with Media Foundation legal holds. Checklists locked under Legal Hold cannot be modified, deleted, or purged.
- **Immutability:** Once an execution is `Approved` or `Completed`, the `ChecklistResponse` and `ChecklistEvidence` tables are hard-locked at the database policy level.

---

## 9. Automation & Scoring Engine
- **Scoring Engine:** Numeric tracking (`ChecklistScore`) calculates Compliance Percentage (e.g., 92% QA Score) in real-time based on weighted `ChecklistItem` values.
- **Automation Engine:** Independent chron-jobs can auto-generate `ChecklistExecution`s based on schedules (e.g., Daily Pool Inspection) without relying on the PM module.

---

## 10. Scalability Review
**Enterprise Baseline:** 100 Properties, 50k Templates, 20M Executions, 100M Responses.
- **Partitioning:** `checklist_responses` and `checklist_executions` MUST be partitioned natively in PostgreSQL by Year/Month.
- **Caching:** `ChecklistVersion` definitions are cached in Redis. Mobile API endpoints fetch from Redis directly, sparing the database.
- **Search:** Meilisearch indexes executions, enabling rapid wildcard searches across 100M responses without B-Tree fragmentation.

---

## 11. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Checklist Fraud** | Critical | Mandatory GPS timestamping and QR Asset scanning enforce physical presence. |
| **Evidence Manipulation** | High | Media integration restricts uploads to live camera capture (blocking Camera Roll uploads) for critical tags. |
| **Template Corruption** | Critical | Strict `ChecklistVersion` isolation. Templates are never overwritten, only deprecated. |
| **Performance Bottlenecks** | High | Partition tables by Year/Month. Snapshot JSON definitions onto the Execution row to eliminate 5-table JOINs during mobile API fetches. |

---

## 12. Implementation Plan

### Entities
`ChecklistTemplate`, `ChecklistVersion`, `ChecklistSection`, `ChecklistItem`, `ChecklistExecution`, `ChecklistResponse`, `ChecklistException`, `ChecklistApproval`.

### Services
- **`ChecklistExecutionService`**: Manages status transitions, snapshot generation, and automation spawning.
- **`ChecklistScoringService`**: Evaluates weights and triggers Exceptions on failures.
- **`ChecklistVerificationService`**: Computes GPS drift and handles QR payload decoding.

### Integrations
- Relies heavily on **Timeline Foundation** (audit logging) and **Media Foundation** (evidence binding).

---

## 13. Testing Strategy
- **Versioning Tests:** Modify a template. Assert old executions maintain the old version structure.
- **Security Tests:** Attempt to modify a `ChecklistResponse` after the execution status is `Completed`. Assert 403 Forbidden.
- **Verification Tests:** Feed a mock GPS coordinate 1km away from the target Location. Assert `GPSVerificationException` is thrown.
- **Offline Sync Tests:** Feed 50 responses simultaneously mimicking a sudden WiFi restoration queue dump.

---

## 14. Open Questions
1. **Camera Roll Restrictions:** For high-compliance PTWs, should the mobile PWA strictly block native OS "Camera Roll" uploads and force raw HTML5 live-camera capture to prevent uploading old photos?
2. **Exception to Work Order:** When a `ChecklistException` is generated for a failed inspection, should the Checklist Foundation automatically spawn a Reactive Work Order, or should that orchestration be handled by the upcoming WO Module listening to an event?

---

## 15. CTO Recommendations
1. **Decouple Modules Completely:** As designed, this Foundation must have absolute zero knowledge of "Housekeeping" or "Engineering". It must rely strictly on Polymorphic relations to bind executions to targets.
2. **Mandate the JSON Snapshot:** Relational mapping (`Execution -> Item -> Section -> Version`) is architecturally pure but terrible for mobile offline sync. Snapshotting the full structure into a JSON column on `ChecklistExecution` at creation time guarantees fast PWA downloads and absolute historical integrity.
3. **Partition Day One:** 100,000,000 response rows will buckle a standard RDS instance within 2 years. Do not launch without PostgreSQL partition schemas active.
