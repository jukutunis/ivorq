---
name: ivorq-cross-domain-ownership-boundaries
description: |
  IVORQ cross-domain module ownership boundaries. Use when a task touches
  multiple domains, crosses ownership lines, or needs to read/mutate data
  owned by another module via approved contracts.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Cross-Domain Ownership Boundaries

## Purpose

Each IVORQ module owns its data, lifecycle, and source of truth. Cross-domain work must go through approved contracts — never by directly accessing another module's internal tables.

## Module ownership map

| Module | Owns |
|--------|------|
| **Front Desk** | Stay operational front-office workflow. Arrival, check-in, room assignment, room move, departure preparation, operational handover, closure readiness. Does NOT own checkout/payment/settlement. |
| **Housekeeping** | Room readiness lifecycle. Cleanliness status, inspection, readiness state transitions. Front Desk may only read Housekeeping status via approved projection. |
| **Engineering** | Room availability and maintenance blocks. Work orders, preventive maintenance, technician scheduling. Front Desk may only read Engineering status via approved projection. |
| **Finance / General Cashier** | Payment execution, cashier sessions, cash counts, reconciliation, cash returns, voids. |
| **Accounting / General Ledger** | GL posting, journal entries, financial periods, financial statements, period close. |
| **Banking** | Controlled banking workspace, bank reconciliation, statement line registration. Only where approved. |
| **Inventory** | Inventory Ledger — source of truth for stock quantities and movements. |
| **CostControl** | AVCO durable state, Cost Ledger entries. Derived Cost Ledger delivery via transactional outbox. |
| **PMS** | Reservations, guest profiles. Front Desk reads reservation/guest data for operational context. |

## Cross-domain rules

1. **Read only through approved projections or services.** Do not query another module's internal tables directly.
2. **Write only through approved service boundaries.** Do not INSERT/UPDATE/DELETE in another module's tables.
3. **A consumer may derive a local operational view** but must never silently rewrite the owner's record.
4. **Do not duplicate source-of-truth facts.** If Inventory owns stock quantity, do not maintain a separate quantity column in another module.
5. **Handoffs must be explicit.** Trigger, inputs, output, idempotency, ownership, failure mode, and audit record must be clear.
6. **Property isolation is mandatory.** Every cross-domain query must be scoped to the correct property.

## Prohibited cross-domain shortcuts

- No direct SQL joins across module ownership boundaries without an approved service contract.
- No reading another module's internal state to make operational decisions that belong to that module.
- No mutating another module's tables during a local operation.
- No bypassing approved service/event contracts to "save time."
