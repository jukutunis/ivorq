# Inventory and Cost Ledger Foundation Execution Specification

## Document Status
Draft

## Purpose
This document provides an evidence-based execution specification for implementing the IVORQ Inventory Ledger and Cost Ledger foundations. It defines the physical state of the active branch, identifies mandatory architecture boundaries, establishes a controlled vertical-slice implementation sequence, and defines explicit activation gates for downstream Cost Control features. It does not authorize bypassing governed ADRs.

## Active-Branch Evidence Snapshot
* Inventory Ledger persistence: Not observed
* Cost Ledger persistence: Not observed
* AVCO calculation evidence: Partially present
* Receiving document/line evidence: Confirmed present
* GRNI posting path: Confirmed present
* AP invoice matching path: Confirmed present
* PPV evidence: Partially present
* Financial period controls: Partially present
* Business Date / Night Audit implementation: Requires active-branch revalidation
* Queue/outbox/idempotency infrastructure: Requires active-branch revalidation
* Existing test coverage: Requires active-branch revalidation
* Existing migration compatibility: Requires active-branch revalidation

## Confirmed Existing Components
Repository evidence confirms operational components exist for Purchasing and Receiving (`ReceivingDocument`, `ReceivingLine`). Accounts Payable is supported by `ApInvoice` and `ThreeWayMatchingEngine`. General Ledger integration includes `GrniPostingEngine` and `ApPostingEngine` to handle liability accruals and relief. Conceptual AVCO mathematics are observed in isolated service logic (`ReceiptService`).

## Confirmed Missing or Incomplete Components
Dedicated immutable physical schemas for Inventory Ledger and Cost Ledger do not exist. There is no dedicated subledger schema for GRNI aging. Financial period controls do not yet effectively constrain operational inventory and cost events. Robust Business Date closure and Night Audit controls are not physically implemented across operational modules.

## Architecture Boundaries That Must Not Be Broken
- Inventory Ledger = immutable quantity movement source of truth
- Cost Ledger = immutable valuation and cost-history source of truth
- Cost Control = reads, analyzes, investigates, and recommends only
- Procurement = commercial intent and commercial commitment owner
- GRNI / AP / Finance = governed matching, posting, reconciliation, and period-close domains
- Business Date / Night Audit = separate operational-close boundary
- Budgeting / Forecasting / Formal Encumbrance = deferred; ADR-035 is not created or implied by this task

## Slice 0 — Active-Branch Engineering Preflight

Slice 0 must pass before any implementation code, migration, or test is changed.

Required gates:
- Baseline test suite and frontend build are executed and their result is classified.
- Existing failures are identified as pre-existing or introduced using repository evidence.
- Migration compatibility is inspected against active schema and existing domain models.
- Existing transaction boundaries, queue/outbox behavior, retry/idempotency behavior, and event/listener behavior are inspected.
- Existing Finance period-close and Business Date/Night Audit implementation boundaries are verified or explicitly recorded as unavailable.
- No implementation slice may begin if a relevant baseline failure, migration conflict, source-of-truth conflict, or closed-period/business-date bypass risk remains unresolved.

Slice 0 outcomes:
- GO TO SLICE 1
- NO-GO — Root-cause remediation required

## Target Vertical-Slice Sequence

### Slice 1 — Inventory Ledger Foundation
* **Objective:** Establish the immutable quantity movement ledger.
* **Preconditions:** ADR-008 boundaries understood.
* **Allowed code domains:** Inventory domain, migrations, repository layer.
* **Prohibited behavior:** No cost or valuation columns allowed in this ledger.
* **Mandatory tests:** Append-only integrity, idempotency, quantity summation.
* **Gate to continue:** Proven ability to record `In`, `Out`, `Transfer`, and `Adjust` movements safely.
* **Expected isolated commit boundary:** Inventory Ledger persistence and base service layer.

### Slice 2 — Cost Posting Engine and Cost Ledger
* **Objective:** Establish the immutable valuation and cost-history ledger.
* **Preconditions:** Slice 1 complete. ADR-010 and ADR-012 understood.
* **Allowed code domains:** Cost and Valuation domain, migrations, repository layer.
* **Prohibited behavior:** No quantity movement allowed; must strictly reference Inventory Ledger. No silent historical AVCO updates.
* **Mandatory tests:** AVCO propagation, valuation consistency, handling of retrospective corrections.
* **Gate to continue:** Proven ability to value an Inventory movement immutably.
* **Expected isolated commit boundary:** Cost Ledger persistence and valuation engine.

### Slice 3 — Receiving, GRNI, AP, and PPV Integration
* **Objective:** Connect operational receiving to physical ledgers and finalize PPV logic.
* **Preconditions:** Slices 1 and 2 complete. ADR-011 and ADR-014 understood.
* **Allowed code domains:** Receiving, AP, GRNI, matching engines.
* **Prohibited behavior:** Must not bypass GRNI accrual or silently swallow invoice-to-receipt variances.
* **Mandatory tests:** Full PO -> Receipt -> Invoice lifecycle with AVCO and PPV extraction.
* **Gate to continue:** Proven three-way match generating accurate physical ledger and GRNI outcomes.
* **Expected isolated commit boundary:** Integration services and updated matching engines.

### Slice 4 — Period Close, Business Date, Correction, and Reconciliation Controls
* **Objective:** Implement temporal controls over the new ledgers.
* **Preconditions:** Slices 1–3 complete. ADR-013 and ADR-034 understood.
* **Allowed code domains:** Period Management, Night Audit engine, cross-module constraints.
* **Prohibited behavior:** Must not silently backdate or mutate closed-period or closed-business-date evidence.
* **Mandatory tests:** Rejection of backdated events into closed periods, generation of correct adjustment entries for late events.
* **Gate to continue:** Hard constraint preventing corruption of closed data.
* **Expected isolated commit boundary:** Temporal control middleware, business date logic, period exception handlers.

### Slice 5 — Cost Control Phase 1 Read Models and Investigation Workflow
* **Objective:** Expose safe, read-only analysis tools for the Cost Controller.
* **Preconditions:** Slices 1–4 complete. Cost-Control-PRD.md requirements understood.
* **Allowed code domains:** Cost Control workspace, read models, variance investigation services.
* **Prohibited behavior:** Must not post ledger entries, alter AP invoices, or change POs.
* **Mandatory tests:** Safe read models, variance case creation, correct Data Readiness state reflection.
* **Gate to continue:** Functional read-only workspace without ledger mutation rights.
* **Expected isolated commit boundary:** Cost Control read models, API endpoints, variance workflow schema.

### Slice 6 — Recipe and Yield Activation Gate
* **Objective:** Prepare standard costing and theoretical yield capabilities.
* **Preconditions:** Governed recipe version, UOM, yield, and ingredient mapping evidence must exist.
* **Allowed code domains:** F&B Recipe engine.
* **Prohibited behavior:** No theoretical consumption can be processed until this gate passes.
* **Mandatory tests:** Recipe versioning integrity, multi-level recipe cost rollout.
* **Gate to continue:** Accurate standard cost generation from active recipes.
* **Expected isolated commit boundary:** Recipe/Yield domain.

### Slice 7 — POS Depletion Activation Gate
* **Objective:** Enable automated inventory depletion based on sales.
* **Preconditions:** Approved sales-to-item/menu mapping, source freshness, idempotency, Business Date, and location/outlet scope must be resolved.
* **Allowed code domains:** POS integration, Night Audit interfaces.
* **Prohibited behavior:** Must not deplete without valid location and business date context.
* **Mandatory tests:** Safe translation of sales mix into inventory relief events.
* **Gate to continue:** Reconciled depletion events accurately recorded in Inventory Ledger.
* **Expected isolated commit boundary:** POS integration and depletion engine.

### Slice 8 — Actual versus Theoretical Variance Activation Gate
* **Objective:** Enable A-v-T investigation.
* **Preconditions:** Actual and theoretical inputs must be complete and reconciled enough for operational analysis.
* **Allowed code domains:** Cost Control analytical engine.
* **Prohibited behavior:** Must not assert theoretical assumptions as financial truth.
* **Mandatory tests:** Accurate variance math across defined time periods.
* **Gate to continue:** Verified A-v-T reporting reflecting incomplete data safely.
* **Expected isolated commit boundary:** A-v-T read models and calculation services.

## Required Data and Event Invariants
- Inventory movement is append-only.
- Historical source events are never silently updated to correct quantities.
- Corrections use governed reversal or corrective entries.
- Every ledger event retains Tenant, Property, location, item, UOM, source reference, Business Date, actual occurrence time, recorded time, actor/system identity, and idempotency context.
- Cost Ledger valuation must reference governed source movement and valuation context.
- Cost Control cannot be made a ledger owner.
- A closed Business Date or Finance period cannot be bypassed by direct mutation.
- Retry must not duplicate quantity, valuation, GRNI, AP, PPV, or Finance consequences.
- No implementation may infer readiness from documentation alone; active-branch behavior, migrations, tests, and integration boundaries must be revalidated before each affected slice.

## Idempotency, Retry, Reversal, and Correction Rules
Any failed event must be safely retriable without duplicating ledger impacts. Unresolved events must use a safe queue or outbox pattern in compliance with ADR-017. Retroactive corrections must result in explicit adjustment events rather than mutating historical records.

## Test and Validation Matrix
All slices require automated tests targeting their specific invariants. Validation must include failure testing (e.g., attempting to post to a closed period) to ensure architectural safety nets function as intended.

## Pilot Acceptance Gates
The overall implementation will be validated through a defined operational pilot (e.g., F&B-only initial scope) prior to full activation, verifying data integrity in a constrained environment.

## Explicit Deferred Scope
Budgeting, forecasting, budget vs. actual reporting, procurement commitment against budget, and formal encumbrance capabilities are explicitly deferred pending future authorized ADR-035 design.

## Go / No-Go Decision for Implementation Train
CONDITIONAL GO — Active-branch engineering preflight may begin. Implementation Slice 1 may begin only after all Slice 0 validation gates pass.

This specification does not itself authorize implementation. It authorizes only controlled engineering preflight. Each implementation slice requires successful predecessor gates, active-branch validation, explicit source-of-truth conformance, isolated reviewable changes, and green validation before the next slice may begin.

## Implementation Commit Boundaries
Commits must be strictly isolated to the functional domain of the active slice. PRs that bundle multiple slices will be rejected to ensure targeted code review and rollback safety.

## Risks Requiring Root-Cause Investigation
Any degradation in `ThreeWayMatchingEngine` reliability or incomplete GRNI accrual handling discovered during active-branch revalidation must be escalated for root-cause remediation prior to connecting the new Cost Ledger.

## References
- Cost-Control-PRD.md
- Sprint-15-Cost-Control-Readiness-Audit.md
- ADR-008 through ADR-014
- ADR-016, ADR-017, ADR-020, ADR-021, ADR-022, ADR-032, ADR-034
