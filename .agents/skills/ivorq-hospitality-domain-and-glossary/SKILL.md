---
name: ivorq-hospitality-domain-and-glossary
description: |
  IVORQ hospitality vocabulary, operational concepts, and Enterprise → Tenant →
  Property hierarchy. Use when defining user-facing terms, workflows, roles,
  workspaces, modules, reports, and cross-team communication.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Hospitality Domain & Glossary

## Purpose

Use familiar hospitality language in IVORQ. The platform should feel native to hotel operations, not like generic ERP or generic CRUD software.

## Hierarchy

- **Enterprise** — IVORQ platform-level administration, subscriptions, tenant registry, and platform operations.
- **Tenant / Cloud Name** — customer or owner group.
- **Property** — an individual hotel, villa, resort, or operational unit.

Cloud Name maps to the Tenant. A user may have access to one or more properties according to approved access controls.

## Hospitality Familiarity Rule

Use business-facing terms such as:

- Front Desk, Reservations, Guest Profile, Room Status, Housekeeping, Engineering;
- General Cashier, Night Audit, Finance, Purchasing, Inventory, Cost Control;
- Sales & Event Management, Banquet Event Order, Guest Request, Shift Handover.

Do not expose technical/database names as primary user-facing language.

## Naming separation

- User interface: familiar hospitality terminology.
- Domain/business layer: business terms that precisely express the operation.
- Database: stable technical names.
- Service/application layer: engineering names that show responsibility.

## Operational behavior

- Prefer workspaces that support a role’s next operational action.
- Treat Property context as operationally meaningful, not as a cosmetic filter.
- Do not invent hotel policy, accounting treatment, guest procedure, or approval authority when it is not documented or approved.
- Preserve the distinction between operational facts, user-entered requests, calculated values, and audited decisions.
