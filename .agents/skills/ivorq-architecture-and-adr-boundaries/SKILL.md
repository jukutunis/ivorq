---
name: ivorq-architecture-and-adr-boundaries
description: |
  IVORQ architecture boundaries and ADR-respect discipline. Use before proposing
  or implementing changes that cross modules, affect ownership, alter shared
  primitives, touch approved architecture, or create a new dependency.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Architecture & ADR Boundaries

## Purpose

IVORQ is a modular hospitality platform. Preserve approved decisions and make a narrow change within the correct owner boundary.

## Before changing anything

1. Identify the module, operation, user role, property/tenant scope, data owner, and integration edge.
2. Inspect applicable ADRs and existing module conventions before proposing a new pattern.
3. Prefer the existing approved boundary over a convenient cross-module shortcut.
4. Escalate when a request would change data ownership, tenant/property isolation, auditability, ledger source of truth, approval authority, or shared interaction primitives.

## Boundary rules

- Do not read or write another module’s internal tables as a shortcut when an approved service, event, contract, or workflow boundary exists.
- Do not make a shared primitive or a cross-domain abstraction for one local use case without evidence and approval.
- Keep module-specific policy in the owning module.
- Make integration contracts explicit: trigger, inputs, output, idempotency behavior, ownership, failure mode, and audit record.

## ADR discipline

- Existing approved ADRs are mandatory architecture constraints.
- Do not edit ADRs, create new ADRs, or reinterpret decisions beyond their stated scope unless explicitly authorized.
- When a task potentially conflicts with an ADR, stop and state the conflict rather than silently implementing around it.

## Decision output

For architecture-sensitive work, state the module owner, affected boundary, approved decision relied upon, and whether the change remains local or needs owner approval.
