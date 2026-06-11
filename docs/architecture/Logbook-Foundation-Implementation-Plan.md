# Logbook Foundation Implementation Plan (v2.1F)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Logbook Foundation serves as the operational diary and shift-to-shift communication backbone of the IVORQ platform. 
**Why it must exist first:**
- **Operational Continuity:** A Chief Engineer starting the morning shift must instantly know what the night shift encountered (e.g., failed chillers, guest noise complaints).
- **Compliance & Liability:** Security and Engineering require immutable, timestamped journals to prove patrols occurred and hazards were noted prior to a guest incident.
- **Dependency Inversion:** Work Orders and Incidents must depend on the Logbook, not the reverse. An unstructured `LogbookEntry` (e.g., "Water pressure low on floor 4") might later be escalated *into* a formal Work Order or Incident.

---

## 2. Architecture Design & Entity Relationships

**Core Entities:**
- **`Logbook`**: The container (e.g., "Engineering Logbook", "Security Logbook").
- **`LogbookEntry`**: The core unstructured or semi-structured journal note.
- **`LogbookCategory` & `LogbookTag`**: Taxonomy (e.g., `Information`, `Critical Alert`, `Maintenance`).
- **`ShiftHandover`**: A specialized grouping of entries bound to a shift transition.
- **`LogbookAcknowledgement`**: Tracks explicit "Read & Understood" signatures from staff.
- **`LogbookActionItem`**: A lightweight task spawned directly from an entry.
- **`LogbookWatcher`**: Subscribes a user to updates on a specific critical entry.

---

## 3. Shift Handover Strategy
**Workflow:**
1. At the end of the `Morning Shift`, the outgoing Supervisor initiates a `ShiftHandover`.
2. The engine dynamically pulls all `PendingItems`, `OpenIssues`, and `Critical Alerts` logged during that shift.
3. The incoming `Afternoon Shift` Supervisor must formally execute a `LogbookAcknowledgement` on the handover payload.
**Risk Mitigation:** The handover cannot be closed until all mandatory `Action Items` are routed or acknowledged, preventing critical knowledge drops between shift changes.

---

## 4. Acknowledgement Strategy
Not all communication is casual. A `Warning` or `Critical Alert` (e.g., "Fire Pump #2 is Offline") mandates compliance workflows.
- **Support:** `Read Confirmation`, `Acknowledged`, `Rejected`, `Requires Clarification`, `Escalated`.
- **Management Visibility:** Directors can view a dashboard of all `Critical Alerts` lacking a 100% acknowledgement rate from the active shift roster.
- **Immutability:** Acknowledgements are permanently bound to the user's ID and timestamp. They cannot be revoked.

---

## 5. Action Item Strategy
Logbooks capture observations that require minor follow-ups before rising to the level of a full Work Order.
- **`LogbookActionItem`:** Assignable to a `@user` or `@department` with a `Due Date` and `Priority`.
- **Escalation:** If an Action Item remains unresolved past shift end, the engine can automatically elevate it into a formal `WorkOrder` inside the forthcoming v2.2 module.

---

## 6. Media Integration (v2.1C)
Tightly bound to the Media Foundation.
- **Workflow:** Security guards logging a broken window on their night patrol use the Mobile PWA to snap a photo. The image is uploaded directly to the `LogbookEntry` via the Media Foundation, complete with EXIF GPS context and timestamp validation to prove the patrol occurred.

---

## 7. Timeline Integration (v2.1D)
Tightly bound to the Timeline Foundation.
- **Synchronization:** When a `LogbookEntry` is updated, commented on, or acknowledged, the `TimelineEvent` engine silently tracks the operational history.
- **Separation of Concerns:** The Logbook is the *content*; the Timeline is the *audit narrative* of how that content evolved.

---

## 8. Security Model
- **Isolation:** `property_id` and `department_id` strict enforcement. A Housekeeping supervisor cannot view the Security Logbook unless explicitly granted an overriding `logbook.view_all` permission.
- **Visibility:** Supports `Private Entries` (e.g., a Manager logging a staff disciplinary observation) hidden from standard shift workers.
- **Legal Hold:** Fully integrates with the Media and Timeline Legal Hold systems. A Logbook entry attached to a lawsuit is frozen and cannot be purged by retention chron-jobs.

---

## 9. Mobile PWA Strategy
Designed for the walking patrol.
- **Offline Mode:** Guards log entries in parking basements. The PWA caches the text and photos in IndexedDB.
- **Background Sync:** The entries push to the server upon WiFi reconnection. The original client-captured timestamp is preserved.
- **Voice Notes:** Utilizes Web Speech API to transcribe dictated notes while the technician's hands are full.
- **QR Context:** Scanning a Location QR code auto-fills the `location_id` on the new `LogbookEntry`.

---

## 10. Scalability Review
**Enterprise Baseline:** 100 Properties, 10 Years, 50,000,000 Entries, 100,000,000 Comments.
- **Partitioning:** `logbook_entries` and `logbook_comments` MUST be natively partitioned in PostgreSQL by Year/Month.
- **Search Strategy:** Standard SQL `LIKE` will catastrophically fail on 50M rows. The foundation strictly mandates pushing all textual content to Meilisearch/Elasticsearch for real-time `Keyword`, `Tag`, and `@Mention` queries.

---

## 11. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Missing Handover** | Critical | Shift Handover engine blocks the outgoing Supervisor from clocking out of the Logbook until the incoming Supervisor executes the digital Acknowledgement. |
| **Notification Fatigue** | High | `@mentions` and notifications must be batched or restricted to `Critical Alerts` to prevent staff from ignoring the system. |
| **Data Explosion** | High | Implement aggressive partition management and enforce strict `Retention Policies` (e.g., 5 years for standard logs, 10 for Security) to archive old data to S3 cold storage. |
| **Knowledge Loss** | Medium | A unstructured journal is hard to mine. AI/Search tagging must elevate "Lessons Learned" into the Knowledge Base. |

---

## 12. Knowledge Retention Strategy
The Logbook is a firehose of daily chatter. The system must support extracting value:
- **Pinning / Bookmarking:** Supervisors can flag a resolved `LogbookEntry` (e.g., "How we bypassed the broken chiller valve") as a `Best Practice`.
- **Knowledge Base (v2.7):** These flagged entries will natively migrate into the formal Knowledge Base Foundation later on the roadmap as permanent SOPs.

---

## 13. Implementation Plan

### Entities
`Logbook`, `LogbookEntry`, `LogbookCategory`, `LogbookTag`, `ShiftHandover`, `LogbookAcknowledgement`, `LogbookActionItem`.

### Services
- **`LogbookEntryService`**: Handles offline sync reconciliation and Meilisearch pushing.
- **`ShiftHandoverService`**: Calculates pending items and enforces the Acknowledgement handshake.
- **`LogbookActionService`**: Manages task due dates and dynamic escalations to the Assignment Engine.

### Integrations
- Requires zero upward dependencies. Sits parallel to Checklist Foundation, resting upon Location, Department, Media, and Timeline.

---

## 14. Testing Strategy
- **Handover Tests:** Assert that a `ShiftHandover` cannot transition to `Completed` if 3 `Critical Alerts` lack an `Acknowledged` signature.
- **Offline Sync Tests:** Push 50 concurrent log entries representing an offline batch dump. Assert timestamps remain chronological to the user's input, not the server receipt time.
- **Search Tests:** Query Meilisearch for a specific `LogbookTag` and assert sub-50ms response times.
- **Security Tests:** Assert that a Front Office user receives a 403 when attempting to query the Security Logbook.

---

## 15. Open Questions
1. **Voice Note Storage:** For Mobile Voice Notes, should the PWA merely transcribe the text, or do we physically store the `.mp3` audio file in the Media Foundation for forensic playback?
2. **Escalation Path:** If a `LogbookActionItem` is not completed, does it auto-generate a `WorkOrder` or does it just escalate via email/push to the Department Head?

---

## 16. CTO Recommendations
1. **Mandate Meilisearch:** Do not deploy this module using B-Tree indexing for search. Logbooks are effectively internal Twitter feeds for properties. Meilisearch is non-negotiable for 50,000,000 rows.
2. **Strict Append-Only Enforcement:** Ensure `LogbookEntry` records are treated as immutable legal journals. If a typo must be fixed, the UI should support a "Correction" appended to the original log, preserving the original text in the Audit Trail to prevent accusations of evidence tampering during lawsuits.
