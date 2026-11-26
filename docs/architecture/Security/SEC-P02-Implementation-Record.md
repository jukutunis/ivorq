# SEC-P02 Enterprise Privileged Access implementation record

**Status:** Implementation record for independent review; not an ADR or operational activation.

**Contract:** IVORQ Package Execution Contract v1.22.

**Canonical predecessor:** SEC-P01 `f229508866cd4666845717303ea18e2851ba0029` (tree `dd271894c3d89297eab122d5e7e4d6fbc3012141`). SEC-P01 was true fast-forwarded to `ivorq-enterprise-core`; PR #99 was merged at that exact commit. Record: `SEC_P01_CANONICAL_FAST_FORWARD_MERGED`.

## Implemented under accepted authority

| Capability | Owner and boundary | Result |
|---|---|---|
| Protected role mutation guard | Foundation Authorization; generic `RoleService` and Foundation User helper | Server-owned protection for source-controlled role names, installation-owner, and the accepted FX permission matrix. Generic role create/edit/delete checks the authenticated actor, active Property membership, canonical Property context, and existing permission. The generic user-role helper fails closed because it has no actor or approval context. The accepted FX role-assignment and owner activation paths remain separate. |
| Security operations read view | Foundation Authentication identity event owner plus Foundation Audit repository read contract | A Property-scoped, `audit.view`-gated read view combines bounded identity-security events with selected existing security audit actions. Both repositories return explicit fields only. Identity metadata and audit old/new values, URLs, IP addresses, tokens, session IDs, MFA secrets, recovery codes, and confirmation material are not projected. No new ledger or permission was created. |
| Interaction surface | Foundation Security Operations page | Read-only event grids, identity-event filter, pagination, clear Property scope, empty state, and permission-aware navigation. No role-grant control is shown. |

## Existing capabilities reused

- SEC-P01 canonical `CurrentPropertyService`, `current_property_id`, `auth_epoch`, `user_sessions`, digest-bound web sessions, bounded Property-bound API tokens, lifecycle and offboarding guards, and credential-aware logout.
- Installation-owner high-assurance enrollment, TOTP, recovery, and session checks under Approved ADR-090.
- Accepted FX assignment segregation and reasoned audit under ADR-064/065. This package does not change the Finance workflow.
- Existing sensitive-action password confirmation under ADR-066/067. It is reauthentication, not MFA, and remains reusable for its accepted bounded window.
- Existing FX-specific break-glass. It grants no role or permission and retains its accepted scope.
- Existing immutable `identity_security_events` ledger and existing Foundation Audit log. Their ownership and historical records remain separate; the new view consolidates only their safe read projections.

## Blocked by unresolved ADR

| Capability | Exact decision needed |
|---|---|
| Enterprise privileged-role taxonomy, universal role conflict matrix, governed request/approval/grant lifecycle, maker/checker authority, delegated approval hierarchy, temporary role grants, and broad bootstrap-role replacement | ADR-029 is **Proposed**. It contains material Tenant/Property/Location scope, toxic permission combinations, emergency role, and waiver choices. The accepted ADR-065 authorizes a narrow FX workflow and explicitly excludes general role management and global break-glass. This package does not invent an enterprise approver or grant rule. |
| General Manager, Finance, Accounting, System Administration, and other non-owner MFA policy enforcement | ADR-029 and identity lifecycle ADR-030 are **Proposed**. There is no accepted role-wide enrollment, recovery, enforcement, or rollout policy. Owner MFA remains enforced; password confirmation is not represented as MFA. |
| Enterprise privacy classification, retention, legal hold, residency, disclosure, deletion, and privacy-request runtime | ADR-031 is **Proposed**. The read view minimizes fields but does not claim an enterprise privacy control plane. |

## Deferred by governance

- Enterprise-wide break-glass, emergency-access approval chain, and global broad-role hardening remain deferred by ADR-065. No production emergency-access grant or indefinite authority was added.
- Operational enterprise MFA rollout, production privileged-role assignment, Operational First Trust enablement, and AWS Production Enablement require separate explicit activation authority.

## Out of scope

- SSO/federated identity, a replacement identity store, another authorization framework, another canonical Property authority, or a new security ledger.
- Finance or Procurement lifecycle changes, CostControl CC-P01A–G, SEC-P03, Business Date, Financial Period, pilot, and cutover.

## Delivery limits

No migration, dependency, RoleSeeder, or PermissionSeeder change. No production/live write or AWS/live action. The security event view is a scoped read surface; the generic role guard protects the accepted FX and source-controlled boundaries. It does not assert that full enterprise privileged access, operational MFA, break-glass, audit, or privacy governance is complete.
