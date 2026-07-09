---
name: ivorq-financial-and-inventory-controls
description: |
  IVORQ controls for inventory movement, valuation, cost posting, transaction
  integrity, business date, idempotency, locking, and auditable operational
  corrections. Use when a change can affect quantities, costs, source ledgers,
  posting provenance, or controlled stock/financial handoffs.
metadata:
  version: v2
  publisher: IVORQ
---

# IVORQ Inventory, Cost & Transaction Controls

## Purpose

Use this skill for operational transactions that can affect stock quantity, stock value, cost, posting provenance, business date, or ledger history. Correctness and traceability outrank convenience.

For General Ledger, General Cashier, accounting period close, financial statements, and finance-domain workflows, use `ivorq-finance-accounting-and-close` as the companion skill. Do not duplicate its ownership here.

## Non-negotiable architecture

- The **Inventory Ledger** is the inventory source of truth.
- Cost processing follows the approved flow: **Inventory Ledger → Cost Posting Engine → Cost Ledger**.
- Initial inventory valuation uses **AVCO**. FIFO is a future extension and must not be introduced implicitly.
- Each Property has a base currency. Amounts and valuations must remain currency-aware.
- A controlled transaction retains the required actor, tenant/property scope, business date, source/provenance, reference, and audit history.

## Controlled transaction rules

1. Make a controlled operation atomic or fail entirely.
2. Use a stable idempotency key when a caller, UI, transport, or integration can cause a duplicate request.
3. Do not create automatic retries unless the approved domain policy permits them.
4. Do not fabricate or backfill provenance, actor, business date, approval, reference, or source values to satisfy a new constraint.
5. Preserve immutable ledger history. Correct through approved compensating or controlled-adjustment flows, never by silently editing historic ledger facts.
6. Do not place external calls, notifications, exports, or asynchronous work inside an open controlled transaction unless an approved contract explicitly requires it.

## Business date, locks, and close safety

- Business-date transitions are server-side, actor-resolved, time-stamped, and fail closed when required context is unavailable.
- Respect approved lock ordering. Do not widen locks, reorder locks, or add unrelated locked resources without approval.
- Use row locks only when real concurrency protection is required, and keep the transaction short.
- Treat deadlock, serialization, and timeout handling as domain policy. Do not invent automatic retry behavior.

## Prohibited shortcuts

- No direct raw-SQL data changes to controlled ledgers or postings.
- No bypass of approved posting services.
- No change to valuation, ledger schema, idempotency behavior, or close behavior without explicit authorization and focused validation.
- No “fixing” balances, quantities, or historical postings by changing records in place.
