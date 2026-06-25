# ADR-038: Logbook Domain Boundary and Shift Handover Governance

## Status
Approved

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform. Operational continuity between shifts and the preservation of daily records are core platform requirements. The Logbook domain is intended to become audit-aligned and tamper-evident through mandatory audit alignment and the immutability rules defined in this ADR. The Logbook domain serves as the operational journal and shift-to-shift communication backbone of the platform.

The initial implementation of the Logbook domain was delivered in commit `5121110 feat(logbook): implement shift handover lifecycle`, which established a basic Shift Handover vertical slice containing:
* `ShiftLog` model and `shift_logs` database migration.
* A draft-to-acknowledgement lifecycle: `Draft` → `Submitted` → `Acknowledged`.
* A single embedded same-property acknowledgement.

Before expanding the Logbook domain to support broader daily operational logging, roster-enforced acknowledgements, correction mechanisms, media attachments, incident linkages, and automated alerts, a canonical ADR must define clear boundaries, lifecycles, and governance rules.

## Decision

### 1. Domain Boundary and Standalone Integrity
The Logbook domain, located in `Modules/Operations/Logbook`, is established as a standalone, independent operational journal.
* The Logbook domain must not automatically create or mutate records in other domains (such as `Tasks`, `Work Orders`, `Incidents`, `Media`, or `Notifications`).
* Task, Work Order, Incident, and future domains may store a one-way source reference to a Logbook record using their own approved data model.
* No external domain may mutate, delete, reopen, or bypass the Logbook source record.

### 2. Hierarchical Scope and Visibility
Operational and shift log visibility is bound strictly to the property and department hierarchy:
* **Hierarchy Path:** `Property` → `Department` → optional `Sub-Department / Channel`.
* **Rules:**
  * `Department` mapping is mandatory for both Shift Handovers and future Operational Log Entries.
  * `Sub-Department` (Channel) is optional and uses the existing `DepartmentHierarchy` structure.
  * No new `Section` master entity will be introduced.
  * The existing `area` free-text field is a temporary contextual label only; it must not be used for permissions, data access constraints, or official reporting filters.
  * Users can view only assigned Logbook scopes/channels. Access across departments or higher-level administrative reviews requires explicit permissions.
  * Private per-entry features are excluded from the initial version.

### 3. Shift Handover Lifecycle and Roster Snapshot
The Shift Handover represents a formal shift-transition record that requires roster-scoped attestations.
* **Canonical Lifecycle:** 
  * Future roster-enabled records use: `Draft` → `Submitted` → `Partially Acknowledged` → `Fully Acknowledged`.
* **Compatibility Rules:**
  * The canonical roster-enforced lifecycle applies only after the approved roster acknowledgement extension is implemented.
  * Existing ShiftLog Slice 1 records remain governed by their existing `Draft` → `Submitted` → `Acknowledged` lifecycle and must not be retrofitted, reinterpreted, or rewritten as roster-enforced records.
* **Rules for Future Roster Enforcement:**
  * The outgoing Supervisor or Duty Leader creates and submits the handover.
  * When a Shift Handover is submitted, the required incoming roster must be resolved, validated, snapshotted, and persisted atomically with the handover submission.
  * If the authoritative incoming roster cannot be resolved, submission must fail closed. The system must not submit a handover with an incomplete, deferred, or mutable required-roster snapshot.
  * Roster exceptions (e.g., staff sick leave or shift adjustments) must be appended to the record and fully audited.
  * The creator of a Shift Handover is permanently barred from acknowledging their own handover.
  * Access and acknowledgement are driven by permissions and organizational assignments; role names must never be hardcoded in code.
  * The actual Shift/Roster Engine implementation remains deferred. No asynchronous placeholder behavior or queue jobs are introduced for this step.

### 4. Roster Acknowledgement Record Model
* **Canonical Principle:**
  * Each incoming roster acknowledgement is an append-only attestation record linked to a Shift Handover.
  * A Shift Handover is:
    * `Submitted` when no required roster member has acknowledged;
    * `Partially Acknowledged` when at least one but not all required members have acknowledged;
    * `Fully Acknowledged` only when every member of the immutable required incoming roster snapshot has acknowledged or has an audited roster exception.
  * Current ShiftLog Slice 1 uses a single embedded same-property acknowledgement and is accepted as the initial vertical slice.
  * Roster-wide acknowledgement requires a later approved reconciliation and extension slice. It must not retrofit or reinterpret existing records.

### 5. Operational Log Entry vs. Shift Handover
A clear separation is established between daily operational events and shift handovers:
* **Operational Log Entry:** Created by Staff or other authorized operational users within their permitted Logbook scope. It requires a mandatory `Department` and optional `Sub-Department/Channel`. It transitions `Draft` → `Submitted`, becoming immutable immediately upon submission.
* **Rules:**
  * System-generated Logbook records or event-driven Logbook entry creation are out of scope and require separate future authorization.
  * Current `ShiftLog` from Slice 1 is positioned as the initial `ShiftHandover` record, not a generic operational log.
  * Shift Handovers summarize shift execution and link distinct operational log entries; they are not auto-generated aggregations of time intervals.

### 6. Immutability and Correction Model
Once submitted, original Logbook and Handover records must never be edited, deleted, or reopened. Corrections must be handled using an append-only supplemental record pattern:
* **Supplemental Record Types:**
  * `Self-Correction`: Appended only by the original author/creator.
  * `Supervisory Clarification`: Appended by an authorized Supervisor or Department Head.
* **Rules:**
  * The original record remains fully visible and unaltered.
  * Supplemental records must include the actor identity, timestamp, correction reason, and content.
  * Corrections must be rendered chronologically alongside or beneath the original record in all views and reports.
  * No controlled reopen of submitted original records is permitted.

### 7. Follow-up Model
Follow-up is not a Task Engine and does not introduce tasks, assignments, due dates, SLAs, or external-domain automation.
* **Derivation Rules:**
  * `Not Required`: `requires_follow_up` is false.
  * `Open`: `requires_follow_up` is true and no append-only Follow-up Resolution exists.
  * `Resolved`: `requires_follow_up` is true and one or more append-only Follow-up Resolution records exist, with the most recent valid resolution determining the current resolved presentation.
* **Rules:**
  * The original Logbook record remains unchanged.
  * Resolution must capture the actor, timestamp, and resolution note in an append-only record.
  * For v1, a valid Follow-up Resolution is terminal for that Logbook record. If the same operational concern recurs after resolution, a new Logbook record must be created. The original record and its resolution must not be reopened, overwritten, or reclassified.
  * Users may manually create a separate Task, Work Order, or Incident referencing the Logbook record within their own domains.

### 8. Reporting Model
Logbook reports are read models generated dynamically from immutable database records. No separate reporting tables are used.
* **First-Class Read Models:**
  1. **Shift Handover Record:** Formal operational document displaying statuses, narrative summaries, incoming roster status, linked log entries, follow-ups, and corrections.
  2. **Daily Department Logbook:** Chronological feed of submitted Operational Log Entries, filtered by Property, Department, date, sub-department, and shift context.
  3. **Follow-up Register:** Workspace view displaying submitted entries with an `Open` follow-up status.
  4. **Pending Acknowledgement Report:** Highlight of submitted handovers showing missing roster signatures.
  5. **Department Head Review View:** Summary workspace for department heads to review handover states, open follow-ups, and critical events.
* **Rules:**
  * No `ShiftHandoverSnapshot` table will be created. The submitted `ShiftHandover` record serves as the immutable snapshot.
  * Future exports must include the generated timestamp, report scope, requesting user, and data classification metadata.

### 9. Domain Boundaries
Clear lines are established between Logbook and other platform domains:
* **Logbook:** The operational journal and handover source of truth.
* **Task Engine:** Handles assigned work with ownership, due dates, and completion workflows.
* **Work Order:** Formal technical or operational work execution records.
* **Incident:** Governance, CAPA, and formal investigation records.
* **Media Foundation:** Future owner of binary storage, storage rules, and file lifecycles. Logbook will reference Media only after approval of the Media ADR.
* **Timeline:** Chronicler of human-readable activity narratives.
* **Audit Log:** Forensic state-change and compliance validation layer.
* **Rules:**
  * Acknowledgements are not approvals and must not use the Approval Engine.

### 10. Tenancy and Audit Guardrails
The Logbook domain must align with the active core architecture:
* **ADR-001 (Multi-Tenant Hierarchy):** 
  * Effective property context must always be resolved server-side.
  * Where X-Property-ID or similar client transport metadata is supplied, it may only be validated against the server-resolved property context. It must never establish, switch, or override the effective property context.
  * Tenant context must be derived from Property and cannot conflict. Database-level `tenant_id` duplication must follow established IVORQ storage conventions.
* **ADR-002 (Audit Trail Strategy):** Logbook and handover records must be added to the Mandatory Audit Entity Matrix in a future ADR-002 update. Current Slice 1 does not possess complete mandatory audit coverage.
* **ADR-003 (Approval Engine):** Logbook processes are excluded from the Approval Engine.

## Scope
This ADR governs all operational log entries, shift handovers, roster-enforced acknowledgements, corrections/clarifications, follow-ups, reporting read models, and domain boundaries.

## Core Invariants
* Root operational records, including Shift Handover and Operational Log Entry, must carry server-authoritative property and department scope.
* Supplemental records, including acknowledgements, corrections, clarifications, roster exceptions, and follow-up resolutions, must be validated against and remain constrained to the immutable scope of their parent record.
* Whether scope fields are physically duplicated on child records follows established IVORQ storage conventions and is not decided by this ADR.
* Outgoing shift logs and handovers are immutable once submitted.
* A user is prohibited from acknowledging their own shift handover.
* Roster snapshots must lock the list of required incoming signatories at the time of submission.

## Lifecycle Model
* **Shift Handover:** `Draft` → `Submitted` → `Partially Acknowledged` (some roster members acknowledged) → `Fully Acknowledged` (all roster members acknowledged).
* **Operational Log Entry:** `Draft` → `Submitted`.

## Department / Channel Scope
* Every entry must map to a `Department` using `department_id`.
* Optional channel segmentation maps to sub-departments within the `DepartmentHierarchy` tree.
* Free-text `area` is kept for contextual notes only and must not be used for permissions or report indexing.

## Visibility and Permission Principles
* Roster users can view only their assigned department/channel logs.
* Supervisors and Department Heads can view logs across their department via scoped permissions.
* Cross-department log searches require elevated overrides.

## Operational Log Entry and Shift Handover Boundary
* `ShiftLog` from Slice 1 is classified as the initial `ShiftHandover` record, not a generic operational log.
* Handovers summarize shift execution and link distinct operational log entries; they are not auto-generated aggregations of time intervals.

## Correction and Clarification Model
* Submitted entries block direct database updates.
* Corrections append `Self-Correction` (creator only) or `Supervisory Clarification` (supervisor only) models.
* Render pipelines display appends inline with the original text.

## Follow-up Model
* Follow-up is represented as `Not Required`, `Open`, or `Resolved` derived from follow-up records.
* Resolution note, resolver ID, and timestamp must be captured in an append-only structure.

## Reporting Model
* Reports are on-the-fly read projections of the immutable records.
* Print and exports must append metadata (requesting user, timestamp, scopes).

## Domain Boundaries
* Logbook does not own media binaries, task workflows, or incident investigations.
* Media association is deferred until the Media ADR is approved.

## Audit and Tenancy Alignment
* Strict multi-tenant isolation is maintained. Mismatches in property resolution fail closed with HTTP `403` or `404` errors.
* Audit trail integration via a future ADR-002 matrix inclusion is required.

## Consequences

### Positive
* Secures operational records for legal compliance and SLAs.
* Eliminates knowledge drops during shift transitions via roster enforcement.
* Decouples daily operational chatter from complex task and work order lifecycles.

### Negative / Trade-Offs
* Roster tracking requires integration with shift schedules, increasing complexity.
* Immutable record growth may affect future capacity, archival, search, and performance design. Partitioning or any other physical storage strategy is deferred until supported by volume, retention, and performance evidence.

## Deferred Decisions
The following elements are explicitly deferred:
* Media relation schema and attachment implementation.
* Incident domain linkage and task/work order integrations.
* Notification triggers, alerts, queue jobs, and WhatsApp messaging.
* Full compliance SLA / cutoff time alerting.
* Shift scheduling and roster engine integration.
* Multi-property consolidated reports.
* Private log entries.
* Offline PWA sync.
* Search and indexing strategy for unstructured log content is deferred to a separately approved search and performance design.
* PDF/CSV export engine and snapshot tables.

## Implementation Guardrails
* Roster snapshot calculations must be performed atomically at submission.
* Database columns containing unstructured log content index plans are deferred.

## Current Slice 1 Reconciliation
The existing Shift Handover vertical slice (commit `5121110`) is accepted as the codebase foundation. No modifications, database rollbacks, deletions, renames, data rewrites, or history rewrites are requested for the current `ShiftLog` structure. The future reconciliation scope is limited to:
* document ShiftLog as the initial Shift Handover model;
* verify and align mandatory Department scope;
* implement mandatory audit alignment after ADR approval;
* introduce future roster acknowledgement records only in an approved extension slice;
* introduce follow-up resolution records only in an approved extension slice;
* preserve the existing Draft → Submitted → Acknowledged behavior for existing ShiftLog records.

## References
* ADR-001 — Multi-Tenant Hierarchy (Active)
* ADR-002 — Audit Trail Strategy (Active)
* ADR-003 — Approval Engine (Active)
* IVORQ Operations Core Blueprint v2.1 (Proposed)
* Logbook Foundation Implementation Plan v2.1F — Pending CTO Approval
