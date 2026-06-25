# ADR-039: Department Supervisory Scope and Delegated Operational Authority

## Status
Approved

## Context
In the IVORQ Hospitality Operations Platform, operational continuity and integrity require a structured approach to delegated authority. Under [ADR-038](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-038-Logbook-Domain-Boundary-Shift-Handover-Governance.md), submitted Logbook entries remain permanently immutable. However, authorized Supervisors and Department Heads are permitted to append a "Supervisory Clarification" to a submitted entry.

Currently:
1. `User` objects have a `department_id` representing their primary or home department membership.
2. Roles and permissions are scoped to properties (`property_id` = Spatie `team_id`) using Spatie Permission's teams configuration.
3. The platform has no formal mechanism to define who is a supervisor, department head, or delegated leader within a department, nor does it limit supervisory actions (like appending clarifications) to those specific individuals.

To implement the supervisory correction/clarification model safely and without hardcoding roles (such as "Supervisor" or "Department Head"), the system requires a clean, reusable foundation for representing delegated departmental authority.

## Decision
1. **Separation of Ordinary Membership and Supervisory Scope**: Department supervisory authority is separate from ordinary user department membership. Simply being a member of a department does not grant supervisory rights.
2. **User Department Assignment Semantics**: The `users.department_id` column remains a user's ordinary, primary, or home department assignment. It is useful for general operations, logging, and filtering, but it is not sufficient by itself to authorize supervisory actions.
3. **Introduction of Department Supervisors**: A future `department_supervisors` data relation will serve as the authoritative assignment foundation for delegated operational authority within a Department.
4. **Property-Scoped Clarification Permission**: The permission `logbook.clarify` is a property-scoped permission using the existing Spatie Teams context, with `property_id` acting as the permission team context.
5. **Supervisory Clarification Requirements**: A future Supervisory Clarification check requires all of the following:
   1. A server-resolved active Property matching LogbookEntry.property_id.
   2. Tenant context derived from that resolved Property according to ADR-001; ADR-039 introduces no tenant_id duplication.
   3. A property-scoped logbook.clarify permission assigned to the user in the active Property context.
   4. An active (is_active = true), explicit department_supervisors assignment for the same Department as the submitted LogbookEntry.
   5. A submitted, immutable parent LogbookEntry.
   6. The clarification actor is not the original LogbookEntry creator.
   The original creator uses Self-Correction and must not append a Supervisory Clarification to their own LogbookEntry.
6. **Cross-Department Denial by Default**: Cross-department clarification is denied by default. A user assigned as a supervisor in one department has no supervisory authority over another department's logs.
7. **DepartmentHierarchy Boundary**: The `DepartmentHierarchy` structure (derived via `parent_id` relations) represents organizational hierarchy and channel context only. In v1, it grants no inherited supervisory authority (e.g., a supervisor of a parent department does not automatically inherit supervisory rights over child departments).
8. **No Hardcoded Roles**: No hardcoded role names (such as "Supervisor", "Manager", or "Department Head") may be used in authorization logic. Authority must be checked using permissions and explicit department supervisor assignments.
9. **No Admin Shortcuts**: No broad "property admin can clarify every Department entry" bypass or shortcut is approved by this ADR for creating Supervisory Clarifications. Separate platform support, forensic, or audit read access may be designed later as read-only access and does not grant clarification authority.
10. **Append-Only Model**: Supervisory Clarification is append-only. It creates a child record but must never modify the original `LogbookEntry`, any existing `Self-Correction`, or any existing `Follow-up Resolution`.
11. **Active Assignment Contract**: An active department supervisor assignment is a record with `is_active = true`. Deactivation changes `is_active` to `false`. Normal operational revocation must deactivate but not delete the assignment record.
12. **Assignment Governance**: A future distinct property-scoped supervisory-assignment management permission is required to create, change, reactivate, or deactivate a `department_supervisors` assignment. The permission `logbook.clarify` alone does not grant assignment-management authority. No actor may create, reactivate, deactivate, or otherwise manage their own `department_supervisors` assignment.
13. **Mandatory Audit Alignment**: The `department_supervisors` relationship is classified as a Mandatory Audit Entity under [ADR-002](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-002-Audit-Trail-Strategy.md). Any creation, modification, reactivation, or deactivation of a department_supervisors assignment must produce Mandatory-tier audit evidence before the relation may control Supervisory Clarification authority. Hard deletion of assignment records is not a defined normal operation under this ADR. Any extraordinary deletion, if ever permitted, requires explicit future governance and equivalent Mandatory-tier audit evidence.

## Scope
This ADR governs the department supervisory scope for Logbook v1 only. Extensions or reuse of this framework for other operational modules (such as Work Orders, Housekeeping, Incidents, or the Task Engine) are out of scope and require a separate ADR or formally approved extension slice.

## Core Invariants
* A user must possess both the property-scoped permission (`logbook.clarify`) AND an active department-level supervisor assignment to append a Supervisory Clarification.
* A user may hold only one active `department_supervisors` assignment for the same Department at one time.
* The system must prevent duplicate active assignments for a user-department pair while allowing multiple supervisors per Department and a single supervisor across multiple Departments.
* A user is prohibited from creating, reactivating, deactivating, or managing their own `department_supervisors` assignment record.
* The resolved `property_id` of the user, the supervisor assignment, and the target `LogbookEntry` must match.
* The original entry must be in a `Submitted` status.
* The Supervisory Clarification cannot alter, delete, or hide the original log entry, any existing `Self-Correction`, or `Follow-up Resolution` records.

## Authorization Model
A request to append a Supervisory Clarification must satisfy the following chain:
1. Effective Property is server-resolved and matches `LogbookEntry.property_id`.
2. Tenant context is derived from the resolved Property according to [ADR-001](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-001-Multi-Tenant-Hierarchy.md); [ADR-039] introduces no `tenant_id` duplication.
3. User holds the property-scoped `logbook.clarify` permission in the active Property context.
4. User has an active explicit `department_supervisors` assignment (`is_active = true`) for the same Department as the submitted `LogbookEntry`.
5. `LogbookEntry` status is `Submitted`.
6. Clarification actor is not the original `LogbookEntry` creator. (The original creator uses Self-Correction. A creator must not append a Supervisory Clarification to their own `LogbookEntry`).

## DepartmentHierarchy Boundary
* Parent-child department relationships are ignored when calculating supervisory permissions in v1.
* A user must be explicitly assigned as a supervisor for the target department itself to perform a clarification action.

## Data Model Direction
The future `department_supervisors` foundation conceptually conforms to these boundaries:
* **Department is the Scope Anchor**: Assignments are associated with a specific `Department` ID.
* **User is the Assignee**: The relationship links a `User` to a `Department` with a supervisory role/flag.
* **Property Consistency**: An assignment must be property-consistent. A user can only be assigned as a supervisor of a department that belongs to a property the user is authorized to access.
* **Multiple Supervisors Supported**: The model must support more than one supervisor per department. Consequently, a single column on the departments table (e.g. `departments.manager_id`) is **rejected** because it is structurally incapable of representing multiple delegated authorities safely.
* **Lifecycle & Temporal Rules**: Temporal logic (effective dates, historical tracking, acting coverage) is deferred and will not be included in the initial database schema unless explicitly required by a future ADR.

## Implementation Sequencing

### Phase 1 — Department Supervisory Authority Foundation
* Implement the database schema and model for `department_supervisors` (e.g., a pivot/relationship table) with `is_active` boolean controls.
* Create validation logic ensuring property-consistent supervisor assignments and active duplicate prevention.
* Provision the property-scoped permission `logbook.clarify` using an approved idempotent authorization deployment mechanism consistent with IVORQ permission governance.
* Implement a helper/trait method (e.g. `$user->isSupervisorOf($department)`) to facilitate policy checks.
* *Note: No Logbook clarification UI or endpoint changes are implemented in Phase 1.*

### Phase 2 — Supervisory Clarification for LogbookEntry
* Create the `LogbookEntrySupervisoryClarification` model, migrations, and schema.
* Enforce rules in `LogbookEntrySupervisoryClarificationPolicy` (validating the submitted parent status, department match, permission, and assignment).
* Implement the API endpoints, controller logic, and frontend UI components to allow authorized supervisors to view and submit clarifications.
* *Note: Original Self-Correction authority (for the entry creator) remains unchanged and isolated from supervisory checks.*

### Phase 3 — Future Broader Delegated Operational Authority
* Any expansion of the department supervisory model to other modules (such as Work Orders, Housekeeping, Incidents, or the Task Engine) must be governed by a separate ADR or approved extension slice.
* No automatic inheritance of authority across other domains is approved by this document.

## Consequences

### Positive
* Strictly protects submitted log records while maintaining auditing compliance.
* Decouples user-to-department membership from operational leadership, ensuring security principles of least privilege.
* Avoids hardcoded role names, making the system adaptable to custom property roles and organizational hierarchies.

### Negative / Trade-Offs
* Requires managing an additional database relationship (`department_supervisors`) and its associated administration UI.
* Denying parent-child inherited authority in v1 increases manual assignment overhead for properties with complex department structures.

## Deferred Decisions
* Active/inactive scheduling, effective dates, acting coverage, and delegation workflows for supervisor assignments are deferred.
* Audit-event retention scheduling and archival periods for department_supervisors audit records are deferred.
* The requirement to produce Mandatory-tier audit evidence for every assignment creation, modification, reactivation, and deactivation is not deferred.
* Inherited supervisory authority traversal through the department hierarchy is deferred.
* Deployment of department supervisory logic to other operations domains (Work Orders, Housekeeping, etc.) is deferred.
* The exact canonical permission key for supervisory-assignment management is deferred to the approved Authorization Foundation implementation slice.

## Non-Goals
* No immediate implementation (this document is a conceptual draft and design specification only).
* No hardcoding of specific role names in authorization logic.
* No inherited parent/child department authority.
* No cross-department supervisor access.
* No modification of the semantics or structure of `users.department_id`.
* No modification of `ADR-002` or `ADR-038` invariants.
* No generic, polymorphic delegated authority engine.
* No automatic impact on Work Order, Housekeeping, Incident, Task Engine, Shift Handover, or Self-Correction authorization boundaries.

## References
* [ADR-001 — Multi-Tenant Hierarchy](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-001-Multi-Tenant-Hierarchy.md) (Active)
* [ADR-002 — Audit Trail Strategy](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-002-Audit-Trail-Strategy.md) (Active)
* [ADR-029 — Security Roles and Permissions Governance](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-029-Security-Roles-and-Permissions-Governance.md) (Proposed)
* [ADR-038 — Logbook Domain Boundary and Shift Handover Governance](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-038-Logbook-Domain-Boundary-Shift-Handover-Governance.md) (Approved)
