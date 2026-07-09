---
name: ivorq-security-access-and-audit
description: |
  IVORQ identity, tenant/property isolation, authorization, audit, session, MFA,
  and administrative-control guidance. Use for login, access control, roles,
  audit events, sessions, user lifecycle, and sensitive operational actions.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Security, Access & Audit

## Purpose

Access decisions in IVORQ must be explicit, auditable, tenant/property-aware, and enforced on the server.

## Authentication and hierarchy

- The approved login flow is Cloud Name first, then the recognized tenant identity, then email and password.
- Cloud Name identifies the Tenant; access may then be limited to one or more authorized Properties.
- Never trust client-supplied tenant, property, role, actor, or privilege context.

## Authorization

1. Enforce authorization in server-side middleware, policy/gate, or approved service boundaries.
2. Scope data access to the authorized tenant and property before returning or mutating data.
3. Avoid “admin by UI”; a hidden button is not authorization.
4. Treat sensitive actions—posting, close, approval, role change, user disable, session revocation—as explicit controlled operations.

## Audit and session controls

- Audit log and session revocation are Tier 1 go-live foundations.
- Record meaningful security and controlled-business actions with actor, time, scope, action, and target/reference as the approved model requires.
- Do not weaken auditability to simplify a task.
- MFA requirements for privileged roles follow the approved tiering; do not invent exceptions.

## Prohibited shortcuts

- No client-only permission checks.
- No role/tenant/property override from request input.
- No secret, credential, token, or session value committed to the repository.
- No bulk access mutation, user disable, or session revocation without explicit scope and owner approval.
