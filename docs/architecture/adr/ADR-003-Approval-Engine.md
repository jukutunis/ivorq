# ADR-003: Approval Engine Architecture

## ADR Metadata
* **ADR Number:** ADR-003
* **ADR Title:** Approval Engine Architecture
* **Status:** Active
* **Date:** 2026-06-18
* **Authors:** Enterprise Architect, CTO, Workflow Architect, Governance Architect
* **Related ADRs:** ADR-001 (Architecture Principles), ADR-002 (Audit Trail Strategy)

## Context
Enterprise hospitality operations require strict governance and oversight over financial, operational, and inventory transactions. Actions such as issuing a Purchase Order, approving a capital expenditure (CAPEX), adjusting inventory stock, or finalizing a Banquet Event Order (BEO) carry significant financial and operational risk.

Historically, approval logic tends to become fragmented, duplicated, and hardcoded within individual modules (e.g., Purchasing has its own approval logic, BEOs have another). This fragmentation leads to inconsistent security models, audit gaps, rigid workflows that cannot adapt to tenant-specific organizational structures, and massive technical debt. 

A unified, centralized, and configurable Approval Engine is required as a core platform capability to handle all authorization workflows across the IVORQ multi-tenant ecosystem.

## Decision
We will implement a unified **Approval Engine** as a centralized domain service within IVORQ. All modules (Purchasing, Inventory, Sales & Event Management, etc.) must delegate workflow authorization to this central engine rather than implementing bespoke approval logic. The engine will evaluate rules, manage state transitions, handle escalations, and integrate tightly with the Audit Trail (ADR-002) and Notification systems.

## Architectural Principles
* **Single Approval Engine:** One centralized engine governs all approvals platform-wide. No module shall implement custom approval state machines.
* **Reusable Across Domains:** The engine is entity-agnostic. It can attach an approval workflow to any Eloquent model (e.g., `PurchaseOrder`, `BEO`, `StockAdjustment`) via polymorphic relationships.
* **Configurable Workflow Definitions:** Approval rules and chains are data-driven, defined per-tenant and per-property, allowing enterprise customers to model their specific organizational hierarchies without code changes.
* **Audit-First Design:** Every state transition, delegation, escalation, and decision is immutably logged in accordance with ADR-002.
* **Multi-Tenant Safe:** All approval chains, rules, and requests are strictly scoped to the `tenant_id`.
* **Property-Aware:** Approval workflows respect property boundaries; a GM at Property A cannot approve a request for Property B unless explicitly configured in a cross-property cluster role.
* **Role-Driven:** Approvals are routed to Roles (via Spatie Permission), not specific Users, ensuring workflows do not break when an employee leaves the organization.

## Approval Lifecycle
All approval requests must adhere to the following unified state machine:
* **Draft:** The entity is being constructed and is not yet subject to approval constraints.
* **Submitted:** The entity is locked for editing, and the approval workflow is instantiated.
* **Pending Approval:** The workflow is active, awaiting decisions from the designated authorities.
* **Approved:** All required steps in the approval chain have been satisfied. The entity may proceed to its next operational state.
* **Rejected:** An authority has explicitly denied the request. The workflow is terminated, and the entity is typically returned to a Draft state or marked void.
* **Cancelled:** The originator withdraws the request before a final decision is reached.
* **Expired:** The approval window timed out without a decision or escalation path.
* **Closed:** Terminal state for legacy or archived requests.

## Approval Levels
The engine supports complex organizational requirements through flexible routing:
* **Single-Level Approval:** A basic request requiring one signature (e.g., Department Head).
* **Multi-Level Approval (Sequential):** A chain requiring sequential sign-off (e.g., Supervisor → Manager → General Manager).
* **Parallel Approval:** Multiple authorities must sign off, but order does not matter (e.g., Finance and Engineering both need to approve a CAPEX).
* **Conditional Approval:** Steps are dynamically injected based on the payload (e.g., require Regional VP approval only if amount > $10,000).
* **Escalation Approval:** If an approver is unresponsive, the request automatically routes to their manager.
* **Delegated Approval:** An approver temporarily reassigns their authority (e.g., "Out of Office").

## Approval Authority Model
* **Who can approve:** Users possessing the specific Spatie Role designated in the active `ApprovalStep`, scoped to the correct `property_id` and `tenant_id`.
* **Who can reject:** Any user holding approval authority for the current pending step.
* **Who can delegate:** System Administrators, or the Approver themselves prior to taking a leave of absence (requires audit logging).
* **Who can override:** Only an explicit 'Super Admin' or 'Enterprise Auditor' role, restricted by strict policy and generating a critical severity audit alert.
* **Who can cancel:** The original requester or a System Administrator.

## Approval Entity Model
The core data structure relies on polymorphic relations to remain domain-agnostic:
* `ApprovalRequest`: The root entity tracking the overall lifecycle of a specific workflow instance. Polymorphically linked to the subject model (e.g., `PurchaseOrder`).
* `ApprovalStep`: Represents an individual level or requirement within a specific `ApprovalRequest`.
* `ApprovalDecision`: The immutable record of an individual's action (Approve/Reject) on an `ApprovalStep`, including timestamp, user ID, and optional comments.
* `ApprovalRule`: The configuration blueprint defining *how* an approval chain should be generated for a specific entity type and property.
* `ApprovalEscalation`: Configuration defining timeout periods and fallback roles if a step stalls.
* `ApprovalHistory`: The denormalized, read-optimized log of all state transitions for a given request.
* `ApprovalDelegate`: Maps temporary authority transfers between users.

## Approval Rule Engine
Rules dictate how an `ApprovalRequest` is instantiated when an entity is Submitted. Rules are evaluated based on:
* **Amount-based:** Financial thresholds (e.g., POs > $500 require GM, > $5000 require Corporate).
* **Department-based:** Routing based on the origin department (e.g., F&B vs. Engineering).
* **Role-based:** Targeting specific Spatie Roles (e.g., 'Director of Finance').
* **Property-based:** Property-specific override rules.
* **Tenant-based:** Global enterprise standards pushed down to all properties.
* **Risk-based:** High-risk actions (e.g., overriding a vendor block) trigger special compliance chains.

## Escalation Strategy
To prevent operational bottlenecks:
* **Timeout escalation:** If an `ApprovalStep` remains pending past its SLA (e.g., 48 hours), it triggers the escalation protocol.
* **Absent approver escalation:** Immediate escalation if the assigned role has no active users at the property.
* **Manager escalation:** Routes to the organizational superior of the stalled role.
* **Executive escalation:** Final fallback to Property GM or Regional Director.
* **Emergency escalation:** "Break-glass" procedures for critical operational needs (e.g., emergency plumbing repair), requiring post-facto justification and heavy auditing.

## Multi-Tenant Requirements
* **Tenant Isolation:** `tenant_id` is a composite key component for all approval entities. Global scopes enforce isolation.
* **Property Isolation:** Approval routing resolves users strictly within the `property_id` context of the request.
* **Approval Visibility Rules:** Users can only view pending approvals assigned to their Roles within their authorized Properties.
* **Cross-Tenant Restrictions:** Hard physical boundaries prevent approval rule bleeding or request cross-pollination between tenants.

## Audit Integration
Tight coupling with ADR-002 (Audit Trail Strategy) is mandatory. The Approval Engine does not replace the Audit log; it feeds it.
* **Mandatory Events:** Every `Submitted`, `Approved`, `Rejected`, `Delegated`, `Escalated`, and `Override` action MUST trigger a corresponding immutable entry via `LogsActivity`.
* The audit payload must include the exact Approval Engine state, the User ID, the Target Entity, and any provided justification comments.

## Notification Integration
The Engine dispatches generic events (e.g., `ApprovalStepPending`, `ApprovalRequestRejected`).
The Notification Engine consumes these events to deliver:
* **Email:** Standard digest or immediate alerts.
* **In-App Notifications:** Dashboard widgets and UI badges.
* **Push Notifications:** Future mobile app alerts.
* **Future SMS / WhatsApp:** For high-priority or executive escalations.

## Security Requirements
* **Approval Integrity:** Once an `ApprovalDecision` is recorded, it is cryptographically locked or rendered strictly read-only at the database level.
* **Non-repudiation:** The system must cryptographically bind the user's session and ID to the approval action.
* **Separation of Duties:** A user cannot approve their own request, even if they hold the requisite approval role (Self-Approval restriction).
* **Least Privilege:** Temporary delegates receive only the specific approval authority, not the delegator's full system permissions.
* **Override Controls:** Manual overrides must require a secondary authentication challenge (MFA) and generate immediate alerts to Tenant compliance officers.
* **Fraud Prevention:** Anomalous approval velocities (e.g., approving 50 POs in 10 seconds) will trigger a security hold.

## Hospitality-Specific Requirements
The engine must natively support the nuances of hotel operations:
* **Purchase Approval:** Enforcing OPEX vs. CAPEX budgets.
* **Stock Adjustment Approval:** Shrinkage, spoilage, or variance resolution.
* **Budget/Forecast Approval:** Month-end financial cycles.
* **BEO (Banquet Event Order) Approval:** Finalizing BEOs for distribution to operational departments.
* **Rate Override Approval:** Front Desk granting unauthorized discounts.
* **Room Block Approval:** Sales teams committing excessive inventory.
* **Engineering CAPEX Approval:** Major equipment replacement workflows.

## Anti-Patterns
The following practices are explicitly prohibited across IVORQ:
* **Hardcoded Approval Chains:** Writing `if (amount > 1000) sendToGM();` within controllers or domain services.
* **Direct Status Updates:** Using `$model->update(['status' => 'approved'])` bypassing the Approval Engine API.
* **Approval Bypassing:** Creating backdoors to forcefully skip an active approval step without triggering an `Override` audit event.
* **Cross-Tenant Approval Access:** Querying rules or requests without enforcing the `tenant_id` scope.
* **Silent Approvals:** System-generated approvals without an explicitly identified synthetic user or clear audit trail.
* **Shared Approval State:** Creating separate `status` columns in tables that conflict with the Approval Engine's authoritative state machine.

## Consequences
* **Positive Consequences:** Centralizes governance, eliminates duplicated code, ensures compliance across all modules, and allows enterprise clients to deeply customize their operational hierarchies.
* **Negative Consequences:** Introduces a central point of failure; if the Approval Engine goes down or has performance issues, the entire platform's operations stall.
* **Tradeoffs:** Increased cognitive load for developers who must interface with a complex polymorphic engine rather than simply updating a status column, traded for massive gains in enterprise security and auditability.

## Future Expansion
The architecture is designed to support future modules seamlessly:
* **Accounting:** Journal entry and month-end close approvals.
* **PMS:** Night audit sign-offs, comp room approvals, high-balance write-offs.
* **HRIS:** Leave requests, salary adjustments, hiring approvals.
* **CRM:** High-value loyalty point redemptions.
* **Revenue Management:** Rule changes and algorithm overrides.
* **Corporate Governance:** Enterprise-wide vendor onboarding approvals.
