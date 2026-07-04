# ADR-065: FX Operational Activation, Role Assignment, and Break-Glass Boundary

**Status:** Accepted for architecture boundary and controlled implementation
**Date:** 2026-07-05

## Context

Sprint 22 now has the realized supplier-payment FX lifecycle, the FX Finance Control Workspace, and the four operational Finance roles defined by ADR-064. The repository keeps static permissions in `Modules/Foundation/Authorization/database/seeders/PermissionSeeder.php` and production role definitions in `Modules/Foundation/Authorization/database/seeders/RoleSeeder.php`. Runtime permission checks use the `web` guard and Spatie's property/team context.

This ADR defines the remaining operational readiness boundary: how approved FX roles may be assigned or revoked for users, what evidence must be captured, and how broad bootstrap administrators are separated from normal daily Finance operation.

## Current FX lifecycle and workspace state

The accepted FX lifecycle remains realized supplier-payment FX only:

1. Candidate creation from a server-owned AP settlement allocation reference.
2. Candidate review.
3. Draft materialization.
4. Finalization authorization.
5. Journal posting.

The FX Finance Control Workspace is exposed through the authenticated Finance route and requires `finance.fx-adjustment.view`. The browser may not provide property, amount, rate, currency, debit, credit, account, mapping, journal, snapshot, or lifecycle status. Service-level property scope, self-review prevention, idempotency, Financial Period, Business Date, and posting controls remain authoritative.

## Approved operational FX roles

Only these operational FX roles are approved for assignment through the controlled workflow:

| Role | View | Candidate Create | Review | Materialize | Finalize | Post |
|---|---|---|---|---|---|---|
| accounts-payable-officer | Yes | Yes | No | No | No | No |
| finance-controller | Yes | No | Yes | Yes | No | No |
| finance-manager | Yes | No | No | No | Yes | No |
| general-ledger-accountant | Yes | No | No | No | No | Yes |

`accounts-payable-officer` also holds `finance.payables.ap-settlement.allocate`.

## Role definition, assignment, and direct permissions

Role definition is production seeding of the role names and their permission matrix. User-role assignment is the controlled operational act of granting or revoking one approved role for one target user in the current property/team context. Direct production user permission assignment is prohibited.

No initial production user receives an FX operational role during seeding, deployment, commit, or test setup outside transactional fixtures.

## Assignment and revocation workflow boundary

The workflow may assign or revoke only:

- `accounts-payable-officer`
- `finance-controller`
- `finance-manager`
- `general-ledger-accountant`

It must not assign broad bootstrap roles, legacy operational roles, arbitrary browser-supplied roles, or direct permission identifiers. It may add or remove only the selected FX operational role and must preserve all unrelated target-user roles.

Assignment and revocation require an authenticated manager actor, source-proven authority, current property/team scope, an in-scope target user, and a non-empty operational reason.

## Property/team and conflict boundary

A user may hold at most one approved operational FX role for the same applicable property/team context. The workflow must reject self-assignment, self-revocation, conflicting operational FX roles, assignment of an already assigned role, and revocation of a role not assigned.

The browser must not provide property or team identifiers. Current tenant/property scope is resolved server-side and bound to Spatie's permission team context.

## Audit requirements

Every assignment and revocation must create immutable audit evidence using the existing audit convention. Evidence must include actor, target, role, action, property/team context, non-empty reason, timestamp, and request correlation context where the current source supports it.

Browser-supplied raw audit payload is not accepted.

## Broad administrator limitation

`super-admin` and `property-admin` keep their existing broad bootstrap `Permission::all()` behavior in this package. They are not ordinary operational Finance segregation roles, and this ADR does not remove or weaken their broad bootstrap behavior globally.

Existing broad roles are not the daily operating model for realized FX.

## FX-specific break-glass boundary

Broad administrators must be treated as break-glass users for FX workspace access and FX lifecycle actions. A broad administrator must complete explicit temporary operational activation before using the FX workspace or FX actions through broad bootstrap authority.

Break-glass activation must require source-proven reauthentication or sensitive-session confirmation and a mandatory reason. It must produce audit evidence and expire according to an existing source-proven session or confirmation convention. Activation must be bound to actor and current property/team context.

Normal approved operational FX-role holders do not require break-glass and continue through the existing permission and service gates.

## No arbitrary role-management UI

This ADR does not authorize a general role-management UI, arbitrary role assignment, broad administrator hardening outside FX routes, or organization-wide RBAC redesign. The implementation boundary is a narrow FX operational role assignment workflow and, when source-proven, an FX-specific break-glass guard.

## Service-level financial controls remain authoritative

This decision does not change:

- property scope;
- tenant scope;
- lifecycle permissions;
- self-review prevention;
- idempotency;
- Financial Period controls;
- Business Date controls;
- controlled posting;
- realized FX source and evidence restrictions.

## Test-only fixture exception

PostgreSQL tests may create users, roles, and permission assignments inside transactional fixtures to prove authorization behavior. This exception does not authorize production direct-user permission assignment or production initial role assignment.

## Consequences

- Daily FX authority is separated from broad bootstrap administration.
- User-role changes become explicit, reasoned, scoped, and auditable.
- Existing broad administrator behavior remains unchanged outside FX-specific controller and route gating.
- Break-glass implementation is allowed only if the repository proves an existing reauthentication or sensitive-session confirmation convention and an existing expiry convention.

## Deferred decisions

- Global break-glass hardening.
- Removal of `Permission::all()`.
- Emergency access approval chain.
- Delegated role administration.
- Multi-property finance delegation.
- Organizational approval thresholds.
- Audit export and reporting.
- Full enterprise role-management workspace.
