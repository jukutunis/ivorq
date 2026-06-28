# ADR-040: IVORQ Interaction Layer Standard

**Status:** Accepted
**Date:** 2026-06-29

## Context

The IVORQ platform is a modular, enterprise hospitality operations platform. To maintain UI/UX consistency, operational safety, and system-wide reliability, the platform requires a unified, cross-domain standard for the user-interaction layer. The core product principle is:
```
Use mature hospitality operational logic,
deliver modern IVORQ Human Designed Hospitality UX.
```
This requires a structured, dense, but calm enterprise application layout optimized for hospitality roles, rather than generic SaaS compositions, website marketing layouts, or unreadable legacy ERP clones. To ensure data integrity and clear boundaries, a mandatory interaction standard must govern workspaces, action context, workflow projections, controlled-action confirmations, status visualization, and frontend-backend responsibilities.

## Decision

All IVORQ modules and features must adhere to these mandatory, cross-domain interaction rules:

### 1. Application Workspace Architecture
- IVORQ user-facing operational surfaces are designed as dedicated application workspaces, not public website pages or generic CRUD routes.
- The default UI wrapper is a compact top application shell with a horizontal module navigation bar.
- Workspaces must maintain a high operational information density tailored to the role, structured via tabs, queues, data grids, detail panels, and contextual action surfaces.

### 2. Contextual Operational Actions
- Business actions must be initiated directly from the relevant entity or workspace context.
- Operational entry points include:
  - Global: Universal Search, Quick Create (only for non-contextual actions), Notification Center.
  - Workspace: Filtered queues, boards, worklists.
  - Entity/Detail: persistent record-detail panels for Rooms, Stays, Reservations, Work Orders, Purchase Receipts, and Inventory Transactions.
  - Follow-Through: My Work, department queues, approval queues.

### 3. Multi-Entry, Authoritative Workflow Projection
- A single domain-owned object may be projected across multiple worklists (e.g., entity timeline, My Work, department queue, approvals, notifications).
- These projections are views of the single authoritative domain object, not duplicate workflow entities.
- Task, request, work order, and approval lifecycles remain distinct domain concerns. This standard does not establish or imply a mandatory global task engine.

### 4. Origin Context and Correlation
- Workflow and transaction requests must preserve origin context:
  - Immutable origin identity/reference.
  - Property and tenant correlation context.
  - Creation-time snapshot when context matters.
  - Clearly visible live linked context where state is dynamic.
- The standard prevents context loss when handing off work between departments or workspaces.

### 5. Governed Operational Tiles and Drill-Down
- Dashboard tiles must act as functional operational indicators, not decorative KPI cards.
- Clicking a tile must drill down directly to a filtered queue or record list.
- Tiles must never directly execute controlled business or posting actions.
- Workspace personalization (reordering, pinning, hiding tiles) must never alter user permissions, workflow ownership, property boundaries, or backend gates.

### 6. Context Actions
- Context Actions must be entity-aware and lifecycle-aware.
- They must carry valid business context into the workflow and be disabled/hidden if the entity is in an invalid state or the user lacks permission.
- Detached blank-form creation is prohibited when a specific source entity context is required.

### 7. Controlled-Action Interaction Gate
- Controlled actions (reversals, voids, adjustments, transfers, posting, closing, approvals, etc.) must follow a strict three-phase interaction pattern:
  1. **Pre-action Gate:** Displays operational context, eligibility check results, blockers, and authority/approval requirements.
  2. **Controlled Confirmation:** Displays immutable original evidence, explicit expected outcome, irreversibility statements, and mandatory approval references.
  3. **Post-execution Evidence:** Displays status outcome, result reference, executor, timestamps, audit/linkback, and contextual next steps.
- Generic "Are you sure?" confirmation dialogs are prohibited for controlled actions.

### 8. Operational Status, Blocker, and Next-Step Clarity
- User-facing status messages must describe current operational conditions, active blockers (why they exist), who is responsible, and the recommended next action.
- Vague status labels, generic error toasts, or technical implementation language are insufficient.

### 9. Frontend and Backend Boundary
- The architecture is strictly separated:
  `Interaction/UI Layer → HTTP Boundary → Application Service → Domain Engine → Ledger/Audit`
- The UI renders server-provided state, validation errors, and result references.
- The UI must never calculate or decide eligibility, authority, inventory balances, cost valuation, business dates, period states, approval states, idempotency, or audit records.

### 10. Permission-Aware Interaction
- The UI may hide or disable actions based on backend-provided authority state.
- The backend remains the final authoritative enforcement layer. Hiding a UI element is not a security boundary.

### 11. Incremental Adoption
- This standard applies prospectively to all current and future IVORQ domains.
- It is adopted domain-by-domain and workspace-by-workspace. It does not authorize bulk UI refactoring of completed, stable codebases.
- The Inventory Reversal Workspace serves as the first reference implementation.

## Non-Goals

This ADR does not authorize:
- UI implementation, CSS layout changes, or component creation.
- A global Task Engine or workflow orchestrator.
- A global status enum, centralized glossary file, or registry.
- Bulk refactoring of existing modules.
- Business rule changes for Inventory Reversal or other modules.
- Modifications to ADR-044, ADR-045, or ADR-046.

## Future Gates

Detailed visual specifications, React component libraries, and specific controller implementations must be proven in individual feature slices.

## Consequences
- Every new user interface slice must satisfy the reference acceptance checklist.
- Security and data consistency are guaranteed by backend boundaries, while the frontend guides the user safely through complex multi-phase tasks.
- Developer velocity remains high through domain-by-domain adoption without cascading refactoring requirements.
