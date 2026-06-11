# Timeline Foundation Implementation Plan (v2.1D)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Activity Timeline Foundation is the universal communication and historical event engine for the entire IVORQ platform. It must exist before operational modules because:
- **Work Orders & PMs:** Require a central thread to log progress, status shifts, and technician comments.
- **Incidents & Housekeeping:** Require immutable timestamped evidence logs for legal compliance and SLAs.
- **Asset Management:** Demands a lifetime history of every action performed on physical equipment over 10-20 years.
- **Cross-Department Collaboration:** Serves as the central `@mention` hub replacing disjointed emails and WhatsApp chats between Front Office and Engineering.

---

## 2. Architecture Design
The Timeline is designed as a highly scalable, polymorphic, append-only log.

### 2.1 Core Entities
- **`TimelineEvent`**: The primary log entry. Append-only.
- **`TimelineActor`**: The entity initiating the event (User, Guest, System, IoT Device).
- **`TimelineTarget`**: The polymorphic entity receiving the event (Asset, WorkOrder, Incident).
- **`TimelineMetadata`**: A JSON column storing deep contextual snapshots (e.g., {"previous_status": "Open", "new_status": "In Progress"}).
- **`TimelineComment`**: Text payloads appended to an event.
- **`TimelineMention`**: Explicit user or department pings.
- **`TimelineAttachment`**: Polymorphic links to the Media Foundation.
- **`TimelineReaction`**: Lightweight feedback (e.g., Thumbs Up, Acknowledged).

### 2.2 Event Types Supported
`Created`, `Updated`, `StatusChanged`, `Assigned`, `Reassigned`, `Commented`, `Mentioned`, `AttachmentAdded`, `AttachmentRemoved`, `Approved`, `Rejected`, `Completed`, `Cancelled`, `Reopened`, `Escalated`, `SignatureCaptured`, `MediaViewed`, `MediaDownloaded`.

---

## 3. Entity Relationships & Polymorphic Strategy
To support any future module, the `TimelineEvent` heavily utilizes Polymorphic relations.
- **`target_type` & `target_id`**: Points universally to `Asset`, `Location`, `Department`, `WorkOrder`, `PermitToWork`, `Incident`, `Logbook`, or `CAPEXRequest`.
- **Inheritance:** The Timeline inherently inherits the security scope of its `Target`. A technician who cannot view an `Incident` automatically cannot view its attached `TimelineEvent`s.

---

## 4. Event Driven Architecture (Async Writes)
Timelines cannot bottleneck operational transactions.
- **Domain Events:** Core modules broadcast generic Laravel Domain Events (e.g., `WorkOrderStatusChanged`).
- **Listeners & Queues:** `TimelineEventSubscriber` catches the event and instantly pushes it onto a Redis queue (`timeline-writes`).
- **Async Processing:** Background workers physically construct the `TimelineEvent` row in PostgreSQL. If the primary WO transaction fails and rolls back, the queue payload is dropped. If the timeline worker fails (e.g., DB lock), it auto-retries 3 times exponentially. Idempotency keys (`hash(target_id + event_type + timestamp)`) prevent duplicate row generation.

---

## 5. Audit Log vs Timeline Strategy (CTO Directive)
The Timeline **DOES NOT** replace the Audit Log. They fundamentally co-exist.
- **Audit Log (Compliance Layer):** Tracks raw database column changes, API IPs, and raw data dumps. Strictly immutable. Read solely by System Admins and Legal via backend interfaces.
- **Activity Timeline (Operational Layer):** User-facing. Human-readable narratives (e.g., "John Smith transitioned the Work Order to In Progress"). Supports collaboration, comments, and photos. Can support soft-deletes or redactions (for inappropriate comments) *only* if the Audit Log retains the original sin.

---

## 6. Security Model
- **Isolation:** Explicitly tagged with `property_id` and `department_id` to enforce hard tenant boundaries.
- **Role Visibility:** Supports `Public Notes` (visible to all staff) vs `Private Notes` (visible strictly to Department Managers/Directors).
- **Legal Hold Awareness:** The Timeline actively respects the Media Foundation's `Legal Hold` flags. If an Incident goes to legal hold, editing or redacting associated Timeline Comments is globally blocked at the Policy level.

---

## 7. Media & Mobile Strategy

### 7.1 Media Integration
Tightly coupled with v2.1C.
- Events auto-generate for `PhotoUploaded`, `SignatureAdded`, or `MediaQuarantined`.
- An engineer uploading 3 "Progress Photos" groups them logically under a single `TimelineEvent` via `TimelineAttachment`.

### 7.2 Mobile PWA Strategy
- **Offline Capture:** Technicians in basements can add `TimelineComments`. The PWA queues them locally in IndexedDB.
- **Background Sync:** The payload transmits when WiFi restores.
- **Conflict Handling:** The Timeline is naturally append-only, effectively eliminating collision conflicts. Timestamps are captured locally at the moment of creation (trusted client-time bounded by server reconciliation) to ensure the timeline narrative reads linearly even if synced hours later.

---

## 8. Retention Strategy
Driven by the parent target's lifecycle:
- **Asset Timeline:** Indefinite (Lifetime of the Asset).
- **Incident / HR Timeline:** 10 Years (Legal Compliance).
- **Work Order / PM Timeline:** 5 Years (Operational Standard).
- **Archival:** Nightly chron-jobs evaluate closed target entities older than their retention threshold, physically pruning timeline rows or offloading them to Cold Storage (S3 CSV dumps) to save DB costs.

---

## 9. Scalability Review & Performance Model
**Enterprise Baseline:** 100 Properties, 10 Years History, 10,000,000+ Timeline Events.
- **Partitioning:** `timeline_events` MUST be partitioned natively in PostgreSQL by Year/Month. Attempting to query a flat 10M-row table for a specific Work Order will degrade the entire ERP.
- **Caching:** The most recent 50 events for an active `target_id` are cached natively in Redis to render the frontend instantly.
- **Search Strategy:** B-Tree indexing on `(target_type, target_id)` and `(property_id, created_at)`. Full-text search on `TimelineComment` is offloaded entirely to Meilisearch/Elasticsearch.

---

## 10. Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Timeline Explosion** | Critical | Utilize PostgreSQL native table partitioning (monthly bounds). Enforce strict data retention purging routines for WO/PMs older than 5 years. |
| **Queue Failure** | High | Redis queue outages could cause lost history. Implement Dead Letter Queues (DLQ) and robust exponential backoff. |
| **Search Degradation** | High | Never use `LIKE %...%` on millions of comments. Meilisearch integration is absolutely mandatory. |
| **Permission Leakage** | Critical | Private comments must be physically separated from public JSON payloads or strictly enforced via Policy gates before returning API responses. |

---

## 11. Implementation Plan

### Entities
`TimelineEvent`, `TimelineComment`, `TimelineMention`, `TimelineAttachment`.

### Services & Listeners
- **`TimelineWriterService`**: Executes the physical DB inserts.
- **`TimelineEventSubscriber`**: Traps system domain events.
- **`TimelineMentionService`**: Parses `@username` regex and interfaces with the Notification Center.

### API & Search
- Cursor-based pagination REST endpoint (e.g., `?cursor=xyz` instead of `?page=2`) to handle massive scrolling histories seamlessly.
- Syncs to Meilisearch automatically via Laravel Scout upon queue completion.

---

## 12. Testing Strategy
- **Queue Tests:** Fire a mock `WorkOrderCreated` event, manually process the queue, and assert the `TimelineEvent` appears in the DB.
- **Idempotency Tests:** Send identical payloads to the worker and assert only 1 DB row is created.
- **Security Tests:** Create a "Private Note" and assert a basic Technician role receives a `403` or filtered response when requesting the timeline feed.
- **Performance Tests:** Seed 1,000,000 mock events and measure partition-bounded lookup speeds.

---

## 13. Open Questions
1. **IoT Event Floods:** Will the Timeline ingest raw automated IoT state changes (e.g., "Chiller temp reached 22C"), or should IoT logs be strictly isolated to an independent telemetry time-series database (like InfluxDB) to prevent Timeline explosion?
2. **Mention Scope:** Should an `@department` mention (e.g., `@Housekeeping`) broadcast a push notification to *every* active Housekeeping employee on shift, or strictly to the Department Supervisor queue?

---

## 14. CTO Recommendations
1. **Mandate Table Partitioning:** Do not launch this module on a flat PostgreSQL table. Native Year/Month partitioning is a hard requirement for 10M+ rows to ensure database vacuuming and archival processes function without locking up the production system.
2. **Isolate Telemetry:** Keep raw machine/IoT data out of the Timeline. Reserve the Timeline strictly for human-readable state changes, comments, and media.
3. **Cursor Pagination:** Force the frontend team to use Cursor Pagination immediately. Traditional offset pagination will cripple the database when a user tries to jump to page 500 of a 10-year-old Asset history.
