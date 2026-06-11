# Incident Foundation Implementation Plan (v2.1G)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Incident Foundation is the universal system of record for operational risk, safety, and compliance across the IVORQ ecosystem. 
**Why it must exist first:**
- **Legal & Risk Exposure:** It centrally manages high-liability events (Guest injuries, Fire incidents, Data breaches) ensuring proper documentation before memories fade or evidence is lost.
- **Dependency Inversion:** Work Orders and Asset Management must depend on the Incident Foundation. An equipment failure is logged first as an `Incident`. That incident dictates root cause investigations and *subsequently* spawns reactive `Work Orders` to execute corrective actions.
- **Cross-Department Span:** Incidents are agnostic. A severe leak in the lobby requires Security (crowd control), Engineering (shutoff), and Housekeeping (cleanup). The Incident acts as the unifying parent record.

---

## 2. Architecture Design & Entity Relationships

**Core Entities:**
- **`Incident`**: The master record (Severity, Status, Category).
- **`IncidentLocation`**: Spatial linkage via Location Foundation.
- **`IncidentReporter` & `IncidentVictim` & `IncidentWitness`**: People involved.
- **`IncidentInvestigation`**: The formal review process container.
- **`IncidentRootCause`**: The verified underlying failure mechanism (e.g., Equipment Failure).
- **`CorrectiveAction` & `PreventiveAction`**: Actionable tasks assigned to mitigate the root cause.
- **`IncidentApproval`**: The multi-tiered sign-off required to officially close the case.

---

## 3. Investigation Strategy
When a `Critical` incident is reported, it cannot simply be "Closed". It must transition into the `Investigating` status.
- **Workflow:** An `Investigation Team` is assigned. They must collect `Witness Statements`, reconstruct the timeline (integrating with Timeline Foundation), and physically inspect the site.
- **Evidence Workflow:** Every step of the investigation requires documented proof linked directly to the Media Foundation (Photos, CCTV footage, Medical Reports).

---

## 4. Root Cause Strategy
The foundation enforces structured methodologies (e.g., "5 Whys" or Fishbone logic) to prevent recurring failures.
- **Root Cause Taxonomy:** Human Error, Process Failure, Equipment Failure, Vendor Failure, Training Deficiency.
- **Mandatory Logic:** An Incident graded as `High` or `Critical` severity mathematically cannot transition to `Pending Approval` without at least one mapped `IncidentRootCause`.

---

## 5. Corrective Action Strategy (CAPA)
Identifying the root cause is useless without action.
- **`CorrectiveAction`**: Immediate fixes (e.g., "Replace the broken lobby tile").
- **`PreventiveAction`**: Long-term mitigations (e.g., "Change the SOP for lobby tile cleaning").
- **Execution:** These actions are strictly tracked by Due Dates and Owners. They will natively map into the future Work Order module (e.g., executing the physical tile replacement). An Incident cannot be `Closed` until all attached Corrective Actions are verified as completed.

---

## 6. Media Integration (v2.1C)
Evidence integrity is paramount for insurance and legal defense.
- **Support:** Photos, CCTV MP4s, Signed Witness PDFs, Police Reports.
- **Legal Hold:** The Incident module actively drives the Media Foundation's `Legal Hold` policy. If an Incident involves litigation, the attached media is permanently frozen, overriding all S3 deletion chron-jobs.

---

## 7. Timeline & Logbook Integration (v2.1D & v2.1F)
- **Timeline Integration:** Every status change, evidence upload, and corrective action generates an immutable `TimelineEvent`. This creates a flawless chronological audit narrative proving exactly when management responded to the crisis.
- **Logbook Integration:** A Security Guard logging a water leak in the Shift Logbook can escalate that specific entry directly into an `Incident`. The original logbook text and timestamp become the genesis of the Incident's timeline.

---

## 8. Security Model
- **Confidentiality Tiers:** Standard incidents follow `Department Isolation`. However, `Medical Incidents` or `HR Incidents` are automatically flagged as `Confidential`. Their visibility is stripped from standard department rosters and restricted explicitly to HR Directors and General Managers.
- **Witness Protection:** Witness statements and PII are redacted from standard exports.
- **Immutability:** Once an Incident transitions to `Closed`, the entire record schema becomes completely immutable at the database level to prevent post-litigation tampering.

---

## 9. Mobile PWA Strategy
Incidents happen in the field. The response must be immediate.
- **Emergency Reporting Workflow:** The PWA features a "Quick Report" mode. A housekeeper can snap a photo, dictate a voice statement, and submit.
- **Offline Reliability:** If a fire occurs in the basement (no WiFi), the PWA caches the report, GPS coordinates, and photos in IndexedDB, syncing immediately upon connection.
- **Digital Signatures:** Investigators can capture digital signatures on glass from witnesses immediately after the event while memories are fresh.

---

## 10. Scalability Review
**Enterprise Baseline:** 100 Properties, 10 Years, 5,000,000 Incidents.
- **Partitioning:** `incidents` and `corrective_actions` tables must be partitioned natively in PostgreSQL by Year.
- **Search Strategy:** Full-text searching across 5 million investigations requires Meilisearch. Standard SQL `LIKE` queries are banned for incident keyword searches.
- **Storage Strategy:** Incident media naturally trends towards heavy CCTV video files. The Cloudflare R2 / S3 Glacier transition rules defined in the Media Foundation will manage this cost.

---

## 11. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Evidence Tampering** | Critical | Enforce absolute immutability on closed incidents. Route all evidence through the MediaAuditTrail. |
| **Privacy Breach** | Critical | Utilize strict DB-level global scopes to hide `Confidential` incidents from unauthorized database queries. |
| **Missed Escalations** | High | Implement a robust asynchronous Notification Engine. A `Critical` incident must trigger immediate WhatsApp/SMS pushes to the GM, bypassing standard email queues. |
| **Offline Sync Failure** | High | Apply the "First Sync Wins" timeline strategy to ensure offline reports correctly reconstruct the historical timeline upon connection. |

---

## 12. Future Integration Strategy
This foundation prepares the bedrock for:
- **Risk Register:** Repeated identical incidents automatically flag a systemic hazard in the future Risk Register.
- **Insurance Claims & Legal Case Management:** Incidents will serve as the primary foreign-key target for future financial and legal tracking modules.
- **CAPEX:** A severe equipment failure incident directly justifies a future CAPEX replacement request.

---

## 13. Implementation Plan

### Entities
`Incident`, `IncidentInvestigation`, `IncidentRootCause`, `CorrectiveAction`, `PreventiveAction`, `IncidentWitness`, `IncidentApproval`.

### Services
- **`IncidentLifecycleService`**: Manages status transitions, enforcing mandatory CAPA and Root Cause checks before allowing closure.
- **`IncidentEscalationService`**: Evaluates `IncidentSeverity` and triggers the Notification Engine (WhatsApp/SMS).
- **`IncidentInvestigationService`**: Binds Checklist Foundation templates to the investigation process.

### Integrations
- Relies completely on Checklist (for investigation forms), Timeline (for audit trails), Media (for evidence), and Location (for mapping).

---

## 14. Testing Strategy
- **Escalation Tests:** Create a `Critical` severity incident and assert the Notification queue instantly receives the SMS payload for the General Manager.
- **Compliance Tests:** Attempt to transition an incident to `Closed` without completing the attached `CorrectiveAction`s. Assert an `IncompleteActionException`.
- **Security Tests:** Create an incident tagged `Confidential` (Medical). Authenticate as a standard Engineering Supervisor and assert a `403 Forbidden` response.
- **Offline Tests:** Simulate a network drop, create an incident via the PWA service worker payload, and assert the original client GPS and Timestamp are preserved upon sync.

---

## 15. Open Questions
1. **External Stakeholder Notifications:** Should the escalation engine support notifying external entities (e.g., local fire departments or external insurance adjusters) via automated email routing for `Emergency` incidents?
2. **CCTV Parsing:** Will we eventually require an integration with external VMS (Video Management Systems) to automatically pull the 5-minute video window surrounding an incident's timestamp, or will security always manually upload the MP4?

---

## 16. CTO Recommendations
1. **Strict CAPA Enforcement:** Do not compromise on the business rule that prevents an Incident from closing if a Corrective Action remains open. Allowing "paper closures" renders the entire risk management system useless during a legal audit.
2. **Confidentiality by Default:** When engineering the database scopes, default to blocking visibility. Explicit permission must be required to view an Incident, preventing accidental data leaks of sensitive employee medical or disciplinary issues.
3. **Mandate Meilisearch for Investigations:** Finding historical precedent (e.g., "Have we ever had a slip-and-fall near the pool?") across 10 years of data is impossible without a dedicated search engine. Deploy Meilisearch concurrently with this module.
