# IVORQ Interaction Layer Standard

This document is the normative implementation handbook for developers and AI coding agents. It provides practical, concrete rules for building and modifying user interfaces in the IVORQ platform.

---

## 1. Purpose and applicability
This standard governs the presentation and interaction layers of every current and future IVORQ domain. It ensures a consistent, high-density, and operationally safe experience for hospitality professionals.

All interaction specifications are classified as follows:
- **MANDATORY**: Strict requirements that must be followed.
- **RECOMMENDED**: Best practices that should be followed unless a strong architectural reason justifies deviation.
- **PROHIBITED**: Design choices and behaviors that are strictly forbidden.
- **DOMAIN-SPECIFIC**: Rules that apply to specific feature areas or business units.

---

## 2. Mandatory interaction principles
- **MANDATORY**: Use mature hospitality operational logic, delivering a modern IVORQ Human Designed Hospitality UX. Avoid copying generic SaaS templates or legacy ERP clones.
- **MANDATORY**: Design interfaces to be dense but calm. Density must serve operational utility, not decorative complexity.
- **MANDATORY**: Ensure operational clarity and visible state transitions are prioritized over purely aesthetic animations.

---

## 3. Application workspace architecture and operational density
- **MANDATORY**: User-facing modules must be structured as application workspaces, utilizing:
  - A compact functional top application shell.
  - A horizontal module navigation bar.
- **RECOMMENDED**: Present related tools, lists, and context within a single tabbed workspace to minimize page hops.
- **MANDATORY**: Maintain a high but readable information density. Visual spacing must align items in structured, readable layouts.

---

## 4. Entry points
Interaction entry points must occur at appropriate levels of hierarchy:
- **Global:** Universal Search, Quick Create, and the Notification Center.
- **Workspace:** Filtered queues, boards, worklists, and department workspaces.
- **Entity/Detail:** Persistent record-detail panels for core business models.
- **Follow-Through:** My Work lists, department queues, and approval queues.

---

## 5. Context acquisition and Quick Create rules
- **MANDATORY**: Context Actions must preserve the origin entity context.
- **MANDATORY**: Do not use detached blank-form creation for any action that requires a specific source entity.
- **MANDATORY**: Quick Create is allowed only for models/actions that do not require a specific source entity. Where source context is mandatory, Quick Create must require the user to select or acquire the origin entity before proceeding.

---

## 6. Origin context, correlation, immutable snapshot, and live context rules
- **MANDATORY**: Workflow objects must preserve:
  - An immutable origin identity/reference (e.g., original transaction ID).
  - Property and tenant correlation context.
  - A creation-time snapshot of variables when historically relevant.
  - Clearly distinguishable live linked context where active state matters.
- **MANDATORY**: Prevent context loss when work is handed off between departments or viewed from different workspaces.

---

## 7. Governed operational tiles and drill-down rules
- **MANDATORY**: Dashboard tiles must be functional operational indicators, not decorative metrics.
- **MANDATORY**: A tile must drill down directly into a precise filtered queue, list, or context.
- **PROHIBITED**: Tiles must never directly execute a controlled action or posting.
- **MANDATORY**: Personalization must never change authority, permissions, workflow ownership, source data, or mandatory department queues.

---

## 8. Queue, grid, tab, filter, and persistent detail-panel pattern
- **MANDATORY**: Present operational queues in structured data grids.
- **MANDATORY**: Use persistent contextual record-detail panels on the side or bottom of grids to display selected records without navigating away.
- **RECOMMENDED**: Provide tabbed filters to switch between logical states (e.g., Awaiting My Review, Draft, Posted).

---

## 9. Context Actions pattern
- **MANDATORY**: Entity-aware buttons (Context Actions) must be:
  - Relevant to the currently selected entity.
  - Lifecycle-aware (only active when the entity status permits).
  - Permission-aware.
- **PROHIBITED**: Do not expose unavailable or unsafe actions. Gray out or hide actions where context or permissions reject them.

---

## 10. Shared workflow projection pattern
- **MANDATORY**: The same operational object may appear across multiple projections (e.g., entity timeline, My Work, approval queue, notifications). These are views of a single authoritative domain object, not duplicates.
- **MANDATORY**: Workflow objects must remain distinct domain concepts. No global task engine is assumed.

---

## 11. Operational status, blocker, owner, and next-step pattern
- **MANDATORY**: Statuses must describe:
  - Current operational condition.
  - Blocker and why it exists.
  - Owner or department responsible.
  - Next available or recommended action.
- **PROHIBITED**: Generic "Pending" labels, vague error toasts, or technical/database error language are insufficient as primary status explanations.

---

## 12. Controlled-action three-phase pattern
- **MANDATORY**: Controlled actions (reversal, void, adjustment, posting, close, approvals) must use a three-phase interaction pattern:
  1. **Pre-action Gate:** Verifies eligibility, displays active blockers, expected impact, and next steps.
  2. **Controlled Confirmation:** Displays immutable original evidence, explicit expected outcome, irreversibility statement, and approval references.
  3. **Post-execution Evidence:** Displays result reference, status outcome, executor, timestamp, audit/linkback, and contextual next steps.
- **PROHIBITED**: Generic "Are you sure?" confirmations are prohibited for controlled actions.
- **PROHIBITED**: A success toast alone is insufficient as primary proof of a completed controlled action.

---

## 13. Permission-aware interaction and backend authority boundary
- **MANDATORY**: While the UI may hide or disable actions based on backend-provided permissions, the backend remains the final authoritative enforcement layer.
- **PROHIBITED**: Hiding UI controls must never replace backend authority enforcement.

---

## 14. UI, HTTP Boundary, Application Service, and Domain Engine responsibilities
- **MANDATORY**: The UI/Interaction layer is responsible only for rendering state and collecting inputs.
- **PROHIBITED**: The UI must never calculate or authoritatively decide eligibility, authority, inventory quantity, stock impact, accounting ledger impact, valuation, business date, period state, approval state, idempotency, or audit records.

---

## 15. Loading, empty, error, success, stale-state, and refresh behavior
- **MANDATORY**: Provide clear visual indicators for loading, empty, and permission states.
- **MANDATORY**: Maintain state freshness. Stale data must trigger a refresh or display a warning before a controlled action is attempted.

---

## 16. Responsive behavior without reducing operational clarity
- **MANDATORY**: The interface must adapt cleanly to different screen resolutions.
- **MANDATORY**: Responsiveness must not compromise density or hide critical operational detail panel information.

---

## 17. Anti-patterns and prohibited implementations
The following implementations are strictly **PROHIBITED**:
- Public website or landing-page compositions for operational workspaces.
- Decorative dashboard card overload or generic KPI grids without drill-down.
- Generic table-to-blank-form workflow patterns.
- Detached task/request creation when entity context is required.
- Action buttons without context, lifecycle, authority, or expected impact checks.
- Hidden blockers.
- Vague generic status labels (e.g., "Pending") as the primary operational explanation.
- Direct execution of controlled actions from dashboard tiles.
- Duplicating workflow objects across queues.
- Placing business, stock, accounting, approval, idempotency, or audit calculation logic in the frontend.
- Using a long AdminLTE-style sidebar as the default application shell.
- Enforcing one identical workspace layout across all domains.
- Decorative gradients, glassmorphism, or animation without operational purpose.

---

## 18. Inventory Reversal Workspace reference implementation
The Inventory Reversal workspace serves as the reference implementation, defining three distinct journey states:

### Phase 1: Eligible for Reversal — Request Approval
- The original transaction context is fully visible.
- The user sees candidate eligibility, blockers, and next steps.
- A "Request Reversal" Context Action is active.

### Phase 2: Final Approved — Ready for Controlled Execution
- The approved request record and approval evidence are visible.
- The immutable original transaction evidence is visible.
- The user sees execution readiness and a controlled confirmation gate.
- "Execute Reversal" is an explicit, separate action.

### Phase 3: Reversal Executed — Result and Audit Evidence
- The original transaction remains visible and marked as reversed.
- The new linked reversal transaction is visible.
- Stock restoration, reversed carrying value, approval reference, executor, timestamps, and audit evidence are visible.
- A second reversal is clearly unavailable.

---

## 19. Reference acceptance checklist for future UI slices
Future UI slices must satisfy this checklist:
- [ ] Workspace uses horizontal module navigation.
- [ ] Context Actions are entity-aware and lifecycle-aware.
- [ ] Controlled actions use the three-phase gate.
- [ ] Statuses explain the blocker, owner, and next step.
- [ ] No business, stock, or cost logic is performed on the client.
- [ ] Replay/idempotency keys are handled end-to-end.
- [ ] Detail panels remain navigable.

---

## 20. Domain-specific boundaries and incremental adoption rules
- **DOMAIN-SPECIFIC**: A domain may use layouts and status lifecycles specific to its workflow, but must comply with the core interaction boundaries defined here.
- **MANDATORY**: Adoption is incremental, domain-by-domain. Do not perform bulk UI refactoring of completed, stable codebases.
