---
name: ivorq-front-desk-departure-lifecycle
description: |
  IVORQ Front Desk departure operational lifecycle — departure queue, preparation
  events, operational handover, and closure readiness. Use for any Front Desk
  departure-related task. Enforces strict operational-only boundary: no checkout,
  no payment, no folio, no settlement, no Housekeeping/Engineering mutation.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Front Desk Departure Lifecycle

## Purpose

Front Desk departure packages are controlled operational readiness layers. They answer operational questions — "Is the guest ready to depart?", "Has the handover been reviewed?", "Is the departure operationally clear to close?" — without crossing into financial, accounting, or room-lifecycle domains.

## Accepted packages

- **FD-B1** — Departure Queue / Due-Out Preparation: departure queue projection with due-out classification, Housekeeping/Engineering dependency read-only views, and operational readiness signals.
- **FD-B2** — Controlled Departure Preparation Evidence: append-only departure preparation events (DEPARTURE_NOTE_RECORDED, DEPARTURE_TIME_CONFIRMED, LUGGAGE_ASSISTANCE_NOTED, TRANSPORTATION_NOTED, OPERATIONAL_BLOCKER_ACKNOWLEDGED, GUEST_MESSAGE_NOTED).
- **FD-B3** — Controlled Departure Operational Handover: OPERATIONAL_HANDOVER_READY, OPERATIONAL_HANDOVER_BLOCKED, OPERATIONAL_HANDOVER_REVIEWED. Idempotent, append-only, immutable evidence with source_hash.
- **FD-B4** (planned) — Controlled Departure Closure Readiness: CLOSURE_READY, CLOSURE_BLOCKED, CLOSURE_REVIEWED. Depends on FD-B3 handover evidence. Operational readiness signal before final departure closure.

## Forbidden scope (all FD departure packages)

Every Front Desk departure package must NOT implement:

- checkout execution
- payment execution
- folio balance checks
- folio settlement
- invoice posting
- cashier session mutation
- accounting posting
- room status lifecycle mutation owned by Housekeeping
- room out-of-order lifecycle owned by Engineering
- night audit close
- guest profile mutation
- rate / revenue changes
- inventory / POS changes

## Required UI markers

Every Front Desk departure workspace must include:

```
Financial settlement: Not evaluated in Front Desk Package <current package>.
```

## B4-specific rules

For tasks touching FD-B4 closure readiness:

- CLOSURE_READY is operational readiness only — it is NOT checkout, NOT payment, NOT "departed", NOT "settled".
- CLOSURE_READY requires at least one FD-B3 operational handover, and the latest handover must not be OPERATIONAL_HANDOVER_BLOCKED.
- CLOSURE_BLOCKED and CLOSURE_REVIEWED are valid regardless of B3 state.
- Must rely on server-resolved stay, property, and actor — never browser-supplied.
- Must read FD-B3 evidence as a dependency but must never mutate FD-B3 records.

## Architecture rules (all FD departure packages)

- Server-resolved property, reservation, guest, room, stay, actor, and occurred_at.
- Idempotency key required.
- Append-only evidence with PostgreSQL trigger + application-level immutability.
- Source hash / audit evidence pattern.
- Authorization through permission seeder and policy/service checks.
- No broad module coupling — depend only on Front Desk-owned data plus read-only dependency on previous FD packages.
