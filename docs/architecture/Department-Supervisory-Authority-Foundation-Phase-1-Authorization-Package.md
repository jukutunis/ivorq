# Department Supervisory Authority Foundation — Phase 1 Authorization Package

## Status
Owner Implementation Authorized

## Purpose
This document establishes the implementation authorization bounds and test matrices for the future Phase 1 Department Supervisory Authority Foundation. It acts as the conceptual design gateway before any source code, database migration, or permission provisioning commits are allowed. No source code changes, database migrations, or permission provisioning changes are authorized or performed by the creation of this documentation package.

## Governing Decisions
This package is governed by and must adhere strictly to the following approved architecture baselines:
* **ADR-001** — Multi-Tenant Hierarchy (Active)
* **ADR-002** — Audit Trail Strategy (Active)
* **ADR-038** — Logbook Domain Boundary and Shift Handover Governance (Approved)
* **ADR-039** — Department Supervisory Scope and Delegated Operational Authority (Approved)

## Exact Authorized Outcome
No implementation is authorized or performed by the creation of this documentation package. If Owner Implementation Authorization is granted, the future Phase 1 implementation slice may establish only the following foundation:
1. A database relation representing `department_supervisors`.
2. An active assignment model using the `is_active` boolean status.
3. An explicit assignment lifecycle supporting creation, update (permitted only under separate management authority), and deactivation/reactivation. Hard deletion of assignment records is not supported in normal operations.
4. Property-consistent validation ensuring matching boundaries between the Department, the supervisor user, the active Property context, and the active authorization scope.
5. Prevention of duplicate active assignments for the same user-department pair.
6. A future property-scoped clarification permission key: `logbook.clarify`.
7. A distinct future supervisory-assignment management permission key (the exact canonical key name is deferred).
8. Mandatory-tier audit trail logging for assignment creation, modification, reactivation, and deactivation.
9. Narrowly scoped Department supervisory authority checks or query helpers. The future foundation must not create a generic delegated-authority engine, generic cross-domain authorization abstraction, or current Logbook Supervisory Clarification authority.

## Explicitly Unauthorized Outcome
The following outcomes are explicitly **unauthorized** by this package and must not be implemented:
* Logbook Supervisory Clarification models, controllers, routes, API endpoints, or user interfaces.
* Any changes to `Self-Correction` authorization or behavior.
* Any changes to `Follow-up Resolution` models, behaviors, or database tables.
* Any integration with Work Orders, Housekeeping, Incidents, the Task Engine, Shift Handover lifecycle, Timeline, HRIS, or other system modules.
* DepartmentHierarchy inherited supervisory authority (traversing parent-child department trees to resolve supervisor status).
* Temporal scheduling, effective dates, acting coverage, delegation workflows, or automatic expiry logic.
* Emergency write bypasses or broad administrator clarification shortcuts.
* A generic delegated authority engine or cross-domain authorization abstraction.
* Permission provisioning execution before separate approval.
* Data backfills or inferred supervisor assignments from existing users.
* An assignment administration UI.
* Audit implementation shortcuts or skipped audit trail evidence.

## Scope Boundary
This authorization package covers the creation of the backend database structures, models, validators, property/department consistency checks, and unit test coverage required to prove Phase 1 foundation boundaries only. No UI components, routes, or Logbook-specific clarification endpoints may be implemented.

## Future Data Model Contract
The database structure for `department_supervisors` must respect these conceptual boundaries:
* **Scope Anchor**: The relation is anchored to a specific `Department` ID.
* **Assignee**: The relation links a `User` to a `Department` with a supervisory role/flag.
* **Property Ownership**: The assignment must be property-consistent. The supervisor and the department must belong to the same property.
* **Active Status**: An `is_active` boolean column dictates operational eligibility.
* **Assignment Multiplicity**: Multiple supervisors per department must be supported. A single `departments.manager_id` column remains rejected.
* **Unique Active Assignment**: A user may only hold one active assignment for the same department at any given time.
* **Deactivation as Revocation**: Deactivation (`is_active = false`) is the normal revocation mechanism. Hard deletion is not a normal database operation.
* **Tenancy derivation**: Tenant context is derived through the server-resolved active Property. No redundant `tenant_id` column duplication may be introduced for this relation.
* **Membership Isolation**: A user's `users.department_id` remains ordinary membership only and never grants supervisory authority.

## Authorization Contract
Evaluation of authority must execute in this exact logical order:
1. Resolve the effective Property context server-side.
2. Derive the tenant context from the resolved Property context under ADR-001.
3. Confirm that the target Department belongs to the effective Property context.
4. Confirm that the supervisor user is valid and active for that effective Property context.
5. Confirm that the user's `department_supervisors` assignment for the Department is active (`is_active = true`).
6. Confirm that the caller has the required property-scoped permission.
7. Confirm that the caller is not performing prohibited self-management.

*Note: This package authorizes the creation of these validation checks conceptually; it creates no authority to append a Supervisory Clarification record.*

## Assignment Management Governance
The future implementation must isolate supervisor assignment management under these rules:
* The Spatie permission `logbook.clarify` alone must never grant rights to create, change, reactivate, or deactivate a `department_supervisors` assignment.
* The exact management permission key remains deferred until implementation authorization is granted.
* Self-management is strictly forbidden. A user must not create, update, reactivate, or deactivate their own `department_supervisors` assignment record.
* The identity of the actor managing the assignment records must be recorded and auditable.
* No hardcoded role names (e.g. Supervisor, Manager) may be used for authorization logic.

## Audit and Immutability Contract
* The `department_supervisors` model must align with [ADR-002](file:///c:/laragon/www/ivorq/docs/architecture/adr/ADR-002-Audit-Trail-Strategy.md) Mandatory Audit Entity expectations.
* Creating, changing, reactivating, and deactivating assignments must write Mandatory-tier audit evidence.
* Audit trail logging is a strict block invariant and must not be deferred.
* Historical audit record retention policies and archival schedules are deferred.
* Hard deletions are blocked under normal operations to preserve compliance logs.

## Property and Department Consistency Rules
* Cross-property supervisor assignment must fail closed with an authorization or validation error.
* Cross-department authority must not be inferred. A supervisor in Department A has no supervisory scope over Department B.
* `DepartmentHierarchy` parent-child connections do not grant inherited authority.
* A user's `users.department_id` does not substitute for a `department_supervisors` assignment.
* Client-provided Property or Department identifiers must never establish authority; they must only be validated against server-resolved contexts.

## Permission Provisioning Boundary
This documentation package does not provision, seed, register, or deploy any permission. If Owner Implementation Authorization is granted, Phase 1 may provision the required property-scoped permissions only through an approved, idempotent authorization deployment mechanism. The package does not choose the exact provisioning mechanism or the exact future assignment-management permission key.

* The Spatie permission `logbook.clarify` is the approved future clarification permission key.
* Supervisory-assignment management requires a separate future property-scoped permission, and the exact key for this management permission remains deferred.

## Locking and Transaction Expectations
* Storing assignment state changes and writing their required ADR-002 audit records must execute atomically within a single database transaction.
* Duplicate active assignment validation must remain correct under concurrent requests.
* No automatic retry loops are authorized. Any retry behavior requires a separately approved policy.
* Implementation must avoid inventing unnecessary database locking complexity.

## Test and Validation Matrix
The implementation must verify the following cases:
1. **Creation**: An authorized assignment-management actor can create a valid supervisor assignment.
2. **Deactivation**: An authorized assignment-management actor can deactivate an active assignment.
3. **Reactivation**: An authorized assignment-management actor can reactivate a deactivated assignment.
4. **Self-Creation Blocked**: A user is denied when attempting to create their own assignment.
5. **Self-Deactivation Blocked**: A user is denied when attempting to deactivate their own assignment.
6. **Self-Reactivation Blocked**: A user is denied when attempting to reactivate their own assignment.
7. **Unauthorized Actor Blocked**: An actor without assignment-management authority is denied when managing assignments.
8. **Cross-Property Blocked**: An assignment of a supervisor to a department belonging to a different property is blocked.
9. **Duplicate Active Blocked**: Creating or reactivating an assignment that results in a duplicate active assignment for the same user-department pair is blocked.
10. **Multiple Supervisors Allowed**: Multiple active supervisors can exist for a single department.
11. **Multi-Department Allowed**: A single user can hold active supervisor assignments across multiple departments.
12. **Membership Insufficient**: Having `users.department_id` match the department does not grant supervisory authority.
13. **Deactivated Denied**: A deactivated assignment (`is_active = false`) fails authorization.
14. **Audit Logging**: Mandatory-tier audit records are verified to exist for all creation, modification, reactivation, and deactivation events.
15. **Regression Verification**: Pre-existing Logbook Self-Correction and Follow-up Resolution behaviors remain unaffected and operational.

## Secure PostgreSQL Validation Boundary
The local runner environment for testing must conform to these rules:
* A single, fixed secure PostgreSQL test operation must be defined.
* Arbitrary test-path execution is not supported.
* No manual secret handling, credential environment processing, or print statements are allowed.
* Execution must target an isolated testing database only.
* Validation must output clear, detailed test counts and assertions.

## Allowed Future Repository Files
Expected future repository areas allowed for Phase 1:
* One migration file (creating `department_supervisors`).
* Files inside `Modules/Foundation/Department` (model, repository, service helpers).
* Files inside `Modules/Foundation/Authorization` (permission provisioning configuration).
* Narrowly scoped policy/helper files (e.g. traits or policies for department supervisors).
* `ADR-002` Mandatory Audit Matrix update.
* One focused PostgreSQL feature test.
* One fixed local secure test-runner operation definition outside Git (only after implementation authorization).

## Forbidden Files and Areas
No modifications are allowed in these directories or files:
* Existing Logbook entry lifecycle files and controllers.
* `Self-Correction` implementation files and services.
* `Follow-up Resolution` implementation files and services.
* `ShiftLog` lifecycle files.
* Work Order, Housekeeping, Incident, Task Engine, Timeline, HRIS, and Inventory modules.
* Generic RBAC database tables or model redesigns.
* `DepartmentHierarchy` structural code or schemas.
* Global application middleware.
* Existing secure runner operations.

## Acceptance Criteria
Phase 1 implementation cannot be considered complete unless:
* All authorized scope invariants are fully verified by tests.
* Mandatory audit trail coverage is validated.
* Property and Department boundaries fail closed on mismatch.
* Self-management operations are successfully denied.
* Duplicate active assignment validation blocks concurrent duplicates.
* Secure PostgreSQL validation returns green.
* No unrelated module or lifecycle behavior is modified.
* ADR-002 alignment is updated only within the approved implementation slice.

## Stop Conditions
Development must stop immediately if:
* The current database schema cannot prove safe Department-to-Property consistency.
* The current authorization system cannot provision property-scoped permissions safely.
* Mandatory audit logging cannot be made atomic with assignment changes.
* The required future file scope expands outside the defined areas without owner approval.
* Any design requires implementing a generic delegated authority engine.

## Required Delivery Evidence
At the conclusion of Phase 1, the following must be delivered:
* Exact implemented scope and file changes.
* Validation test results showing all test matrix cases passing.
* Complete git status and diff analysis confirming clean boundaries.
