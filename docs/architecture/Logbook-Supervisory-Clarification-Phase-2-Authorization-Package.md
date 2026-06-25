# Logbook Supervisory Clarification — Phase 2 Authorization Package

## Status
Status: Owner Implementation Authorized

## Purpose
This document defines the technical boundaries, constraints, and authorization criteria for the future Phase 2 Logbook Supervisory Clarification capability. It serves as an isolated architectural specification to govern the append-only supervisory annotation of submitted operational log entries while protecting the absolute immutability of the parent record.

## Governing Decisions
The design and execution of this specification are governed strictly by the following active decisions:
* **ADR-001**: Multi-property SaaS isolation, tenant context derivation, and Postgres-strict schema contracts.
* **ADR-002**: Standardized Spatie activity logging and transaction-bound Mandatory Audit Trail rules.
* **ADR-038**: Approved append-only mutability rules (immutable parents, creator self-corrections, supervisor clarifications).
* **ADR-039**: Organizational delegation of authority, separating normal department membership from explicit supervisory assignments.
* **Phase 1 Foundation (Commit 851a1e0)**: Reusable department supervisors table, lifecycle methods, and `logbook.clarify` permissions.

## Current Foundation Preconditions
Implementation of Phase 2 assumes the following Phase 1 foundation elements are active and unmodified in the codebase:
1. `department_supervisors` table with a partial unique index enforcing a single active supervisor per user and department.
2. `DepartmentSupervisorService` providing authority validations.
3. `logbook.clarify` and `department.supervisors.manage` permissions provisioned idempotently.
4. Spatie Teams configured to evaluate permissions under Property-level team scopes.
5. `activity_log` table configured for ULID keys and standard audit schemas.

## Exact Future Outcome
If Owner Implementation Authorization is granted, Phase 2 may implement only an append-only Supervisory Clarification capability for submitted Operational Log Entries within the authorized actor’s explicitly assigned Department scope.

The future Phase 2 implementation may establish only:
1. One append-only `LogbookEntrySupervisoryClarification` child-record type.
2. A narrow server-authorized service and policy boundary.
3. One restricted Department Review query for eligible submitted Operational Log Entries.
4. One append action for Supervisory Clarification.
5. Minimal Logbook workspace rendering for Department Review and append-only clarification history.
6. Mandatory audit evidence for clarification creation.
7. Focused PostgreSQL validation and one fixed future E22 runner operation.
8. ADR-002 Mandatory Audit Matrix alignment for the clarification entity.

## Explicitly Unauthorized Outcome
Phase 2 does not authorize the implementation, testing, or exposure of:
* Editing, deleting, replacing, reopening, or hiding the parent `LogbookEntry`.
* Editing, deleting, replacing, or hiding any `Self-Correction` record.
* Editing, deleting, replacing, or hiding any `Follow-up Resolution` record.
* Editing or deleting `Supervisory Clarification` records.
* Shift Handover correction, roster verification, or acknowledgment state changes.
* Department supervisor assignment administration UI, routes, or backend changes outside Phase 1 limits.
* Changes to `department_supervisors` database schema or Phase 1 authority logic.
* Altering the standard behavior or semantics of `users.department_id` (home department).
* `DepartmentHierarchy` inherited authority or supervisory cascade logic.
* Acting coverage, delegation, effective dates, expiry, or scheduled activation of supervisor roles.
* Broad department operational reporting, data export (PDF, CSV), or dedicated Department Head dashboard.
* Generic comment engines, polymorphic annotation systems, threads, replies, or social features.
* Incidents, Tasks, Work Orders, Housekeeping, HRIS, Timeline, Inventory, or other cross-domain integrations.
* Notification dispatching (email, SMS, WhatsApp), background queues, or workflow automation.
* Admin write bypasses, emergency overrides, or support-user writing bypasses.
* Provisioning of new permissions or default role permission changes.

## Parent Record Boundary
Any eligible parent record must satisfy these strict preconditions before a Supervisory Clarification can be appended:
* The parent must be a `LogbookEntry` record only.
* The parent must belong to the server-resolved active Property context.
* Tenant context must be derived through that resolved Property under ADR-001.
* The parent's Department serves as the authority scope anchor.
* The parent's status must be `Submitted` (Draft entries are excluded).
* Parent creator must never append a Supervisory Clarification to their own entry.
* Creator's `Self-Correction` or an existing `Follow-up Resolution` do not block a supervisor's clarification, but they must remain entirely unchanged.

## Supervisory Clarification Record Contract
The future database schema for `logbook_entry_supervisory_clarifications` must define only these conceptual fields:
* `id` (ULID, primary key)
* `property_id` (ULID, foreign key, derived server-side from parent entry)
* `logbook_entry_id` (ULID, foreign key, restricts delete of parent)
* `idempotency_key` (conceptually added)
* `clarification_reason` (string/text, mandatory, non-empty)
* `clarification_content` (text, mandatory, non-empty)
* `clarified_by` (ULID, foreign key, maps to authenticated user)
* `clarified_at` (timestamp, server-generated)
* `created_at` (timestamp, standard database timestamp)

Rules:
* No client-provided `property_id`, `clarified_by`, `clarified_at`, or authority-defining department identifiers are accepted.
* The `idempotency_key` is:
  - non-null;
  - client-generated;
  - required for every create action;
  - no default/fabricated server fallback;
  - protected by database uniqueness on (property_id, idempotency_key);
  - not editable after creation.
  (Do not write migration SQL or choose an exact column type).
* No status, `reply_to_id`, `thread_id`, visibility flags, `deleted_at`, `updated_at`, or polymorphic references.
* Parent deletion must be restricted, preventing cascading deletion of the logbook entry if clarifications exist.
* Multiple clarifications may be appended to a single entry (this child-record type supports multiple annotations per parent).
* Chronological query display must be deterministic: sorted by `clarified_at` ascending, then by `id` ascending.
* Hard deletion is not supported as an application path.

## Authorization Contract
For any Supervisory Clarification append action, the service must execute the following evaluation chain in order:
1. Resolve the effective Property context server-side.
2. Derive the tenant context from the resolved Property context (no `tenant_id` column duplication).
3. Resolve and lock the target `LogbookEntry` within the active Property.
4. Check the `idempotency_key`. If the key exists within the Property scope:
   a. Verify the immutable request identity fields match exactly: Property, parent LogbookEntry, actor, clarification_reason, and clarification_content. If they match, replay the existing clarification record without generating a second record or a second Mandatory audit event.
   b. If any immutable request identity fields differ, fail immediately as a controlled idempotency conflict before any state mutation or audit trail event is generated.
5. Require the parent `LogbookEntry` status = `Submitted`.
6. Require the actor ID is NOT the original `LogbookEntry` creator ID.
7. Require the actor holds the property-scoped `logbook.clarify` permission within the active Spatie Teams context.
8. Confirm through `DepartmentSupervisorService` that the actor has an active explicit supervisor assignment for the same parent Department.
9. Persist the clarification and record Mandatory audit evidence in one database transaction.

Key Clarifications:
* Idempotency evaluation happens inside the same transaction as parent lock, authorization re-check, clarification create/replay, and Mandatory audit.
* Same key with exact same immutable request identity replays existing record.
* Same key with different immutable request identity rejects before any new clarification or audit event is written.
* No automatic retry.
* `department.supervisors.manage` is not required to append a clarification.
* `logbook.clarify` alone is insufficient without an active explicit same-Department supervisor assignment.
* `users.department_id` (home department membership) is never sufficient to establish supervisory authority.
* Client-supplied Property or Department inputs are ignored as authority sources.
* No hardcoded role names or write bypasses are permitted.

## Department Review Visibility Boundary
The current "My Operational Entries" view must remain creator-scoped to prevent supervisors from leaking other staff records inside their workspace. Instead, a distinct, server-authorized Department Review query must be defined:
* It returns only `Submitted` logbook entries.
* It returns only records from Departments where the actor has both an active `DepartmentSupervisor` assignment and the property-scoped `logbook.clarify` permission.
* It denies all cross-Department and cross-Property entries by default.
* It must not use `users.department_id` as a substitute for supervisory assignment.
* It exposes only the parent entry and existing chronological supplements (self-corrections, follow-up resolutions) required to append a clarification.
* It serves strictly as an action queue, not a search screen, reporting tool, or dashboard.

## Append-Only and Immutability Contract
* **Create Only**: Supervisory Clarification supports create operations only.
* **No Updates or Deletes**: No update, edit, delete, replace, reopen, or status transition endpoints shall exist.
* **Role Boundary**: The original creator cannot clarify their own entries; they use `Self-Correction` instead.
* **Active Status Required**: Currently inactive former supervisors cannot append new clarifications.
* **History Preservation**: Existing clarification records remain visible even if a supervisor is later deactivated or reassigned.
* **Non-blocking Status**: A clarification does not alter parent status, follow-up status, or resolution fields.

## Audit and Transaction Contract
* The clarification model (`LogbookEntrySupervisoryClarification`) must be registered as a Mandatory Audit Entity under ADR-002.
* Creation of a clarification must synchronously emit Mandatory-tier audit evidence detailing the actor, subject, and attributes changed.
* Parent locking, authority validation, record insertion, and audit trail dispatching must run inside a single database transaction block.
* A forced outer transaction failure must result in rollback of both the clarification record and its audit trail evidence.

## API and Workspace Boundary
The future API and workspace routes are restricted to:
1. A server-authorized Department Review query retrieving eligible entries.
2. A single create action mapping to the append service.
3. Rendering chronological clarifications alongside self-corrections and resolutions in the existing Logbook workspace.
4. No edit, delete, or reopen controls will be rendered in the UI.
5. List-all, reports, or global exports are strictly prohibited.

## Permission Boundary
* The `logbook.clarify` permission is already provisioned by Phase 1. Phase 2 must not define or seed new permissions.
* The `department.supervisors.manage` permission is reserved for assignment administration and does not grant clarification authority.

## Future Data Consistency Rules
Each intentional new Supervisory Clarification submission must include one client-generated idempotency_key.

The same idempotency_key may be reused only when retrying the same intended submission after timeout, network failure, or uncertain response.

A replay is valid only when all immutable request identity fields match:
- Property;
- parent LogbookEntry;
- actor;
- clarification_reason;
- clarification_content.

A valid replay returns the existing Supervisory Clarification and must not create a second clarification record or second Mandatory audit event.

Reuse of the same idempotency_key with a different Property, parent LogbookEntry, actor, clarification_reason, or clarification_content must fail as a controlled idempotency conflict.

Every intentionally new clarification requires a new idempotency_key.

No automatic server retry loop is authorized.

The future Supervisory Clarification record must persist a non-null idempotency_key protected by database uniqueness on (property_id, idempotency_key).

Idempotency replay and mismatch conflict evaluation are scoped to the server-resolved effective Property.

The service must not read, compare, return, or disclose clarification records from another Property when evaluating an idempotency_key.

The same idempotency_key value in another Property is outside the replay scope of the current effective Property and must not cause cross-Property data disclosure.

* **Fabrication block**: No data backfill, fabricated historical clarifications, or inferred supervisor assignments are permitted.

## Test and Validation Matrix
The future test suite must run inside the secure PostgreSQL lane and verify:
1. Authorized active supervisor with `logbook.clarify` appends clarification to a `Submitted` entry.
2. Mandatory fields `clarification_reason`, `clarification_content`, and `idempotency_key` persist.
3. `clarified_by` and `clarified_at` are server-derived.
4. Parent `LogbookEntry` remains unchanged.
5. Clarification actor cannot be the parent creator even when holding required permissions and active assignment.
6. `logbook.clarify` without active supervisor assignment is denied.
7. Active supervisor assignment without `logbook.clarify` is denied.
8. Inactive supervisor assignment is denied.
9. Active supervisor from another Department is denied.
10. Cross-Property context is denied.
11. Draft parent is denied.
12. `users.department_id` alone grants no authority.
13. `department.supervisors.manage` alone grants no clarification authority.
14. Multiple authorized supervisors can append separate clarifications.
15. Department Review returns only eligible same-Department `Submitted` entries.
16. Department Review excludes other Departments and Properties.
17. My Operational Entries remains creator-scoped (no leakage).
18. same idempotency_key with identical Property, parent, actor, reason, and content replays the existing clarification with no second activity_log event;
19. same idempotency_key with changed reason is rejected;
20. same idempotency_key with changed content is rejected;
21. same idempotency_key with another parent is rejected;
22. same idempotency_key with another actor is rejected;
23. database uniqueness on (property_id, idempotency_key) is directly evidenced;
24. deliberately retried request creates exactly one clarification and exactly one Mandatory audit event.
25. Canonical Mandatory audit evidence exists for clarification creation.
26. Forced outer transaction rollback persists neither clarification nor audit evidence.
27. Existing logbook regression tests (E18, E19, E20, and E21) remain green.

## Secure PostgreSQL Validation Boundary
The future implementation must register exactly one new fixed operation:
```text
E22_LOGBOOK_SUPERVISORY_CLARIFICATION
```
Constraints:
* Runs only the focused PostgreSQL clarification test file.
* No arbitrary test paths or inputs.
* Mappings E17 through E21 remain unchanged.
* No manual credentials processing or output.
* Executes only in the isolated PostgreSQL test database.

## Expected Future Repository File Boundary
Expected files to be added or modified in Phase 2:
* One migration for `logbook_entry_supervisory_clarifications` table.
* Relation update on `LogbookEntry.php`.
* Model `LogbookEntrySupervisoryClarification.php`.
* Service `LogbookEntrySupervisoryClarificationService.php`.
* Policy `LogbookEntrySupervisoryClarificationPolicy.php`.
* Validation request `StoreSupervisoryClarificationRequest.php`.
* Query builder/controller adjustments in Logbook workspace module.
* Workspace React updates to render clarifications.
* ADR-002 Audit Matrix update.
* Focused test file `LogbookSupervisoryClarificationTest.php`.
* One E22 local runner mapping outside Git.

## Forbidden Files and Areas
No modifications are permitted on:
* `DepartmentSupervisor` models, services, migrations, providers, or seeders.
* Existing Self-Correction controllers, routes, or services.
* Existing Follow-up Resolution controllers, routes, or services.
* `ShiftLog` lifecycle implementation.
* Work Order, Housekeeping, Incident, Timeline, HRIS, Inventory, or Finance modules.
* `DepartmentHierarchy` schema or logic.
* Global middleware or generic RBAC redesigns.
* Runner operations E17 through E21.

## Acceptance Criteria
Phase 2 is complete only when:
* idempotent replay and mismatch conflict behavior are proven through secure PostgreSQL validation;
* no retry path creates duplicate clarification or duplicate audit evidence;
* Leakage check verifies Department Review is locked to supervisor's scope.
* Parent entry immutability is validated.
* Audit trail and rollback atomicity are verified.
* All baseline operations (E18–E22) return PASS.

## Stop Conditions
Implementation must stop if:
* Logbook review query cannot be scoped without leaking entries.
* Phase 1 active same-department supervisor authority cannot be validated.
* Spatie Teams context fails to evaluate the permission correctly.
* Mandatory audit logging cannot execute in transaction block.
* Proposed solution introduces polymorphics, generic comments, or cross-domain integrations.

## Required Delivery Evidence
* approved idempotency contract;
* exact replay identity fields;
* controlled mismatch conflict behavior;
* database uniqueness evidence;
* replay produces no duplicate audit evidence.
* Exact future outcome conditional wording.
* Exclusions list.
* Parent and child-record type contracts.
* Authority evaluation chain.
* Department Review boundary definition.
* Secure validation boundary mapping.
* Complete git status and check validation.
