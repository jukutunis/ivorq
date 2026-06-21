# Ledger Event Integrity and Property Business Date Control Recovery Specification

## Document Status
Draft

## Purpose
This specification defines the recovery path for missing ledger event-integrity controls and backend Business Date enforcement prior to activating the new Inventory and Cost ledgers. It establishes the minimum backend scope necessary to safely resume the Ledger Foundation implementation train.

## Root-Cause Evidence Summary
* Property business date persistence: Not observed
* Server-side business date enforcement: Not observed (UI filter only)
* Generic outbox pattern: Not observed
* Ledger-specific transaction/locking: Confirmed present (extensive use of `DB::transaction` and `lockForUpdate()`)
* Idempotency constraints: Partially present (relies on DB locks rather than dedicated idempotency keys)

## Existing Reusable Platform and Domain Primitives
The repository heavily utilizes standard Laravel `DB::transaction()` and pessimistic locking (`lockForUpdate()`) across AP, GRNI, and existing inventory stock balances. No centralized outbox or global event-bus replay primitive exists.

## Decision: Ledger-Specific or Platform-Level Idempotency
LEDGER-SPECIFIC IDEMPOTENCY IS SUFFICIENT FOR SLICE 1

Active-branch evidence proves that robust pessimistic locking and transaction boundaries are successfully utilized in existing financial components. Future ledger design can reliably enforce idempotency through unique constraints on source references, explicit transaction boundaries, and deterministic duplicate-rejection handling without requiring a generic platform outbox.

## Decision: Minimum Property Business Date Control Scope
The minimum backend foundation must enforce Property-scoped business date control. It must provide a server-side resolver that identifies the current active open business date for a given Property, shielding ledger entries from arbitrary client-supplied dates.

## Mandatory Architecture Boundaries
* Ledger-specific idempotency must use database constraints and pessimistic locking.
* Business Date is resolved server-side from governed Property context.
* Actual occurrence timestamp, recorded timestamp, and Business Date remain distinct.

## Property Business Date Data and State Model
A persistence layer (e.g., `PropertyBusinessDate` or extension of `Property`) must maintain:
- Tenant ID
- Property ID
- Active Business Date (Date)
- Status (Open/Closed)
- Audit context

## Server-Side Business Date Resolution Rules
A resolver service must accept a Property ID and return the currently active Open Business Date. If no open date exists, or the context is invalid, it must throw an explicit, controlled exception.

## Event Integrity Contract for Future Inventory Ledger
A ledger event must preserve both:
1. a deterministic idempotency key for retry-safe command processing; and
2. a granular source-event identity.

For Receiving, source identity must be based at least on the ReceivingLine level, not ReceivingDocument alone. The final source-event composite must distinguish legitimate original receipt, reversal, and correction events. A source document alone must never be assumed to produce only one ledger movement.

The final physical unique indexes must be derived from actual repository schema and tests. They must prevent duplicate retry effects without blocking legitimate separately identified reversal or correction events.

## Retry, Duplicate, and Transaction Rules
Duplicates must be rejected deterministically by database constraints. Retries must not yield duplicate quantity or financial consequences.

## Finance Period Interaction Boundary
Business Date control does not replace Finance Period Close.
Finance Period Close does not replace Business Date control.

## Night Audit Boundary
This recovery phase does not implement a full Night Audit workflow or UI. It implements only the backend control required to prevent ledger corruption.

## Security, Tenant, Property, and Audit Requirements
Tenant and Property isolation must be strictly enforced in the resolver.

## Minimum Backend Recovery Scope
Option B — dedicated Property Business Date persistence within the existing Modules\Foundation\Property boundary.

Implementation must reuse, where compatible:
Property ULID conventions
Property company_id / tenant relationship
BelongsToProperty scope
CurrentPropertyService context
HasAuditColumns
LogsActivity
Property authorization conventions
Property factory and test-fixture conventions
Property timezone attribute

## Explicitly Deferred Scope
- Inventory Ledger
- Cost Ledger
- Full Night Audit UI/Workflow
- Finance Period-Close Engine
- Generic Outbox/Event Bus

## Test and Validation Matrix
- Property A and Property B resolve independently.
- Missing business date fails safely.
- Closed business date fails safely.
- Client-supplied dates are ignored.

## Recovery Implementation Gates
Must pass active-branch build and tests before proceeding to ledger implementation. Active-branch Property model placement, timezone configuration conventions, and Tenant scope traits must be fully verified before implementation.

## Go / No-Go Decision for Business Date Foundation
CONDITIONAL GO — Minimum Property Business Date backend foundation may be implemented only through the mapped Foundation Property conventions, with Property-scoped authorization, server-side resolution, green tests, and isolated commit boundaries.

## Go / No-Go Decision for Future Inventory Ledger Slice 1
CONDITIONAL GO — Future Inventory Ledger Slice 1 may begin only after the Property Business Date foundation is implemented, Business Date tests are green, Finance Period status can be resolved through a read-only guard, and ledger-specific idempotency is enforced by tested persistence constraints and transaction boundaries.

## Implementation Commit Boundaries
Commit 1: Specification

## References
- Cost-Control-PRD.md
- ADR-017, ADR-034
