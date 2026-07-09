---
name: ivorq-module-integration-and-operational-workflows
description: |
  IVORQ cross-module workflow, handoff, contract, and operational ownership guidance.
  Use when a change touches more than one module or moves an operational fact,
  request, status, approval, or posting across a boundary.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Module Integration & Operational Workflows

## Purpose

Cross-module work must express an operational handoff, not merely pass data. Keep responsibility, source of truth, timing, failure behavior, and audit evidence clear.

## Required questions

Before implementing a cross-module change, identify:

1. Which module owns the source fact?
2. Which module consumes it, and for what operational purpose?
3. What triggers the handoff: user action, approved workflow step, event, posting, or scheduled process?
4. What identifiers, tenant/property scope, actor, business date, and correlation/idempotency information travel with it?
5. What happens on failure, duplicate delivery, partial failure, or late arrival?
6. What remains visible to the operational user and what is auditable?

## Integration rules

- Do not duplicate source-of-truth facts merely for convenience.
- Prefer explicit contracts and approved service/event boundaries.
- Do not convert a local task into a platform-wide event architecture without approval.
- Cross-module workflows must preserve tenant/property scope and authorization.
- A consumer may derive a local operational view, but must not silently rewrite the owner’s record.
- Do not dispatch notifications, external calls, export work, or asynchronous jobs inside an open controlled transaction unless the approved contract explicitly supports it.

## Hospitality operational examples

Examples of cross-domain thinking include:

- a reservation operationally affecting Front Desk arrival work;
- room readiness handoff between Housekeeping and Front Desk;
- an approved inventory movement affecting cost posting;
- an event order operationally affecting distribution and task execution.

These examples are not permission to invent implementation details; inspect the approved module contracts first.
