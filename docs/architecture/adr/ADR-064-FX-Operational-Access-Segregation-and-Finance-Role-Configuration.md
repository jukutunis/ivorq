# ADR-064: FX Operational Access Segregation and Finance Role Configuration

**Status:** Accepted for architecture boundary and controlled configuration
**Date:** 2026-07-05

## Context

Sprint 22 introduced the realized supplier-payment FX candidate lifecycle and the Finance FX Control Workspace. ADR-062 established that candidate generation is a dedicated authority, source-owned, server-calculated, and separated from review, draft materialization, finalization authorization, and posting. ADR-063 preserves Laravel as the authoritative application boundary for Finance, General Ledger, approvals, authorization, audit, and system-of-record state transitions.

The repository's production authorization configuration is centralized in `Modules/Foundation/Authorization/database/seeders/RoleSeeder.php`. Roles are created with `Role::firstOrCreate` using the `web` guard and `property_id = null`, and permissions are assigned with `syncPermissions`. The repository's static permission list is centralized in `PermissionSeeder`, which already registers every permission required by this decision.

This ADR records the approved operational Finance role model for realized FX access without changing the existing broad bootstrap roles.

## Realized FX lifecycle summary

The accepted realized FX lifecycle is:

1. A user with candidate initiation authority requests candidate creation from a server-owned AP settlement allocation reference.
2. The candidate service validates property access, source evidence, idempotency, full-settlement scope, mappings, approved rate evidence, and decimal controls.
3. A separate reviewer approves or rejects the pending candidate.
4. A separate Finance actor materializes an approved candidate into a draft journal.
5. A separate Finance actor authorizes draft finalization.
6. A separate GL actor posts the authorized draft through the accepted posting service.

No browser request may supply property, amount, rate, currency, debit, credit, account, mapping, journal, snapshot, or lifecycle status.

## FX Finance Control Workspace summary

The FX Finance Control Workspace exposes a current-property operational view of realized FX candidates, drafts, authorization states, and posted history. Workspace visibility uses `finance.fx-adjustment.view`. Action controls remain server-capability gated, and service-level authorization remains authoritative for all lifecycle transitions.

## Current broad-role limitation

The existing `super-admin` and `property-admin` roles use broad bootstrap authority through `Permission::all()`. They are not ordinary operational Finance segregation roles. Their existing behavior is unchanged by this package and will be addressed separately through a future break-glass/RBAC hardening decision.

Existing roles `general-manager`, `staff`, `department-head`, and `supervisor` are also preserved unchanged by this decision.

## Decision

Introduce four operational Finance roles in the existing `RoleSeeder` role configuration source:

- `accounts-payable-officer`
- `finance-controller`
- `finance-manager`
- `general-ledger-accountant`

These roles are global role definitions. Tenant and property scope remains enforced by authenticated session context, Spatie's permission team context, controller checks, and service-level property-access checks.

## Exact role-permission matrix

| Role | View | Candidate Create | Review | Materialize | Finalize | Post |
|---|---|---|---|---|---|---|
| accounts-payable-officer | Yes | Yes | No | No | No | No |
| finance-controller | Yes | No | Yes | Yes | No | No |
| finance-manager | Yes | No | No | No | Yes | No |
| general-ledger-accountant | Yes | No | No | No | No | Yes |

`accounts-payable-officer` also requires `finance.payables.ap-settlement.allocate`.

The exact permission assignments are:

- `accounts-payable-officer`: `finance.fx-adjustment.view`, `finance.payables.ap-settlement.allocate`, `finance.fx-adjustment-candidate.create`.
- `finance-controller`: `finance.fx-adjustment.view`, `finance.journal-candidate.review`, `finance.journal-candidate.materialize-draft`.
- `finance-manager`: `finance.fx-adjustment.view`, `finance.journal-entry-draft.authorize-finalization`.
- `general-ledger-accountant`: `finance.fx-adjustment.view`, `finance.journal-entry.post`.

No new operational Finance role may use `Permission::all()` or wildcard assignment.

## Candidate initiation boundary

Only `accounts-payable-officer` receives `finance.fx-adjustment-candidate.create`. Candidate creation remains restricted to a server-resolved AP settlement allocation reference and requires `finance.payables.ap-settlement.allocate` for the same role.

## Candidate creator/reviewer separation

`accounts-payable-officer` must not receive:

- `finance.journal-candidate.review`
- `finance.journal-candidate.materialize-draft`
- `finance.journal-entry-draft.authorize-finalization`
- `finance.journal-entry.post`

The service-level self-review protection remains authoritative even if a future user is assigned multiple roles.

## Review/materialization boundary

`finance-controller` receives review and draft materialization authority only:

- `finance.journal-candidate.review`
- `finance.journal-candidate.materialize-draft`

It must not receive candidate creation, AP settlement allocation, finalization authorization, or posting authority through this role.

## Finalization boundary

`finance-manager` receives draft finalization authorization only:

- `finance.journal-entry-draft.authorize-finalization`

It must not receive candidate creation, AP settlement allocation, review, materialization, or posting authority through this role.

## Posting boundary

`general-ledger-accountant` receives posting authority only:

- `finance.journal-entry.post`

It must not receive candidate creation, AP settlement allocation, review, materialization, or finalization authority through this role.

## View-only and workspace-exposure boundary

Every operational FX role receives `finance.fx-adjustment.view` so that actors with lifecycle work can see the current-property workspace. View authority alone does not grant any lifecycle action.

## Tenant/property scope preservation

The new roles are global role definitions with `property_id = null`, matching the existing role seeding convention. Runtime tenant and property enforcement remains in the authenticated web request, Spatie permission team context, controller property checks, and Finance service property-access checks.

## Service-level enforcement preservation

This decision does not change:

- property scope;
- tenant scope;
- self-review prevention;
- idempotency;
- Financial Period controls;
- Business Date controls;
- posting controls;
- realized FX lifecycle services.

## Direct production user assignment prohibition

Production authority remains role-based only. This ADR does not authorize direct production user permission assignment.

## Technical/system authority prohibition

This ADR does not grant automatic financial action authority to technical/system roles. Existing broad administrator behavior is unchanged in this package and deferred to a future break-glass/RBAC hardening decision.

## Cashier and banking proximity prohibition

Cashier or banking operational proximity is not sufficient to receive realized FX candidate authority. Candidate initiation is assigned only to `accounts-payable-officer` because that role is explicitly approved to hold AP settlement allocation plus FX candidate creation authority.

## Test-only fixture exception

PostgreSQL tests may create users, roles, and permission assignments inside transactional fixtures to prove authorization behavior. This exception does not authorize production direct-user permission assignment.

## Consequences

- Operational FX access is separated across AP initiation, Finance control, Finance finalization, and GL posting roles.
- The FX workspace can be exposed to every operational actor who owns a lifecycle step.
- Existing broad bootstrap roles remain untouched, avoiding an incidental RBAC hardening change inside this package.
- The service layer remains the final enforcement point for property scope, self-review, idempotency, and posting controls.

## Deferred decisions

- Break-glass administrator restriction.
- Role-management UI.
- Role assignment audit workflow.
- Temporary authority.
- Delegated authority.
- Emergency access.
- Approval thresholds.
- Multi-property delegated Finance authority.
