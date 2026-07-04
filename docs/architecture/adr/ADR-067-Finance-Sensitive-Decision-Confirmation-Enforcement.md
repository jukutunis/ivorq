# ADR-067: Finance Sensitive Decision Confirmation Enforcement

**Status:** Accepted for controlled implementation
**Date:** 2026-07-05

## Context

ADR-064 established segregated FX operational Finance roles. ADR-065 defined controlled role assignment and a break-glass boundary for broad administrators. ADR-066 created a reusable sensitive-action confirmation primitive with four registered intents, 15-minute maximum validity, actor/intent/company/property/session binding, fail-closed behavior, and immutable audit evidence via `SensitiveActionConfirmationService`.

The `finance-approval` intent was registered in ADR-066 as one of four initial intents. The confirmation primitive was proven in isolation by `SensitiveActionConfirmationTest`. The `finance-role-assignment` intent was integrated into `FxOperationalRoleAssignmentController` and proven by `FxOperationalRoleAssignmentTest`. The `fx-break-glass` intent was integrated into `FxBreakGlassAccessService` and proven by `FxBreakGlassAccessTest`.

This ADR defines the controlled integration of `finance-approval` into every current source-proven Finance web action that performs an approval/rejection/finalization decision. No new intent is created. No new role, permission, or lifecycle behavior is introduced.

## Why authorization alone is insufficient

Authorization checks whether an actor holds a specific Spatie permission. It does not prove the current human operator intends to execute a high-consequence Finance decision right now. Session hijacking, unattended workstations, and confused-deputy scenarios make reauthentication a standard control for approval and finalization actions in enterprise finance systems.

The `finance-approval` confirmation requires the authenticated actor to supply their current password, creating a time-limited, session-bound reauthentication window. This proves the human at the keyboard knows the account password now, not just at session-start.

## Existing `finance-approval` intent scope

The `finance-approval` intent was registered in `SensitiveActionConfirmationService::REGISTERED_INTENTS` on creation. Its scope covers any Finance decision that:

- approves or rejects a Finance-controlled document, candidate, proposal, settlement, adjustment, or exception; or
- authorizes finalization of a Finance/GL journal draft.

It does not cover candidate creation, draft materialization, controlled posting, payment execution, inventory mutation, role assignment, break-glass activation, or actions outside Finance.

## Source-proven action inventory

### Discovery methodology

Bounded `git grep` across `Modules/Finance`, `routes`, and `tests/Postgres/Finance` for approval/review/finalization route/controller/service patterns. Twenty total inspected files included every route, controller, and service hit by the discovery commands.

### Included actions

#### A. Approval / Review Decision

| # | Domain | Action | Route Name | Controller | Permission | Lifecycle Service | Break-Glass? |
|---|---|---|---|---|---|---|---|
| A1 | Realized FX | Review candidate (approve/reject) | `finance.fx-adjustments.candidates.review` | `FxAdjustmentControlWorkspaceController@review` | `finance.journal-candidate.review` | `RealizedFxAdjustmentCandidateReviewService` | Yes |
| A2 | GRNI | Approve candidate | `finance.general-ledger.grni-control.candidates.approve` | `GrniControlWorkspaceController@approve` | `finance.journal-candidate.review` | `JournalCandidateReviewService@approve` | No |
| A3 | GRNI | Reject candidate | `finance.general-ledger.grni-control.candidates.reject` | `GrniControlWorkspaceController@reject` | `finance.journal-candidate.review` | `JournalCandidateReviewService@reject` | No |

#### B. Finalization Authorization

| # | Domain | Action | Route Name | Controller | Permission | Lifecycle Service | Break-Glass? |
|---|---|---|---|---|---|---|---|
| B1 | Realized FX | Authorize journal draft finalization | `finance.fx-adjustments.journals.authorize-finalization` | `FxAdjustmentControlWorkspaceController@authorizeFinalization` | `finance.journal-entry-draft.authorize-finalization` | `RealizedFxAdjustmentFinalizationAuthorizationService` | Yes |
| B2 | GRNI | Authorize journal draft finalization | `finance.general-ledger.grni-control.journals.authorize-finalization` | `GrniControlWorkspaceController@authorizeFinalization` | `finance.journal-entry-draft.authorize-finalization` | `JournalEntryDraftFinalizationAuthorizationService` | No |

### Excluded source-ambiguous actions

| Domain | Action | Reason for Exclusion |
|---|---|---|
| AP GRNI Settlement | Payment proposal create/cancel draft | Creation/cancellation, not approval/finalization decision |
| Supplier Invoice | Approve/reject invoice | No source-proven web route/controller exists; service only |
| Supplier Invoice | Three-way-match exception review | No source-proven web route/controller exists; service only |
| Cost Control | Enrollment approve/reject | No source-proven web route/controller exists; repository only |
| Budget | Version approve/reject | No source-proven web route/controller exists; service only |
| Banking | Reconciliation finalization | No source-proven web route/controller exists; service only |
| All Finance | Controlled posting | Explicitly excluded — posting is a separate authority |
| All Finance | Candidate creation | Explicitly excluded — creation is a separate authority |
| All Finance | Draft materialization | Explicitly excluded — materialization is a separate authority |
| All Finance | Payment execution | Explicitly excluded — separate operational authority |
| Non-Finance | Any operational action | Outside finance-approval intent scope |

## Required action ordering

For every integrated action, the controller must enforce this exact sequence:

1. **Authentication and active-property context** — provided by `auth` + `active.property` middleware.
2. **Action authorization** — existing `authorizeAction()` call; missing authority returns HTTP 403.
3. **FX broad-admin break-glass guard** — where the action is within the FX domain (A1, B1), the existing `guardBreakGlass()` call must remain before confirmation.
4. **Finance-approval confirmation** — `requireValidConfirmation($actor, 'finance-approval', $companyId, $propertyId)`; missing/expired/mismatched → controlled redirect to confirmation page.
5. **Lifecycle service invocation** — existing service call with all current business guards.
6. **Controlled feedback** — existing `redirectingAction()` success/error handling.

## 403 versus controlled redirect behavior

| Condition | Behavior |
|---|---|
| No active property | HTTP 403 |
| Missing action permission | HTTP 403 (`authorizeAction` → `abort(403)`) |
| Missing FX break-glass (broad admin in FX) | Controlled redirect to `finance.fx-break-glass.index` |
| Missing/expired/mismatched finance-approval confirmation | Controlled redirect to `system.sensitive-action-confirmation.index` with `intent=finance-approval` |

The first two conditions remain HTTP 403. The last two are controlled redirects because the actor has valid property access and the required permission but lacks a required runtime session confirmation.

## Confirmation invalidity behavior

When confirmation is missing, expired, actor-mismatched, property-mismatched, company-mismatched, malformed, or of a different intent:

- `requireValidConfirmation()` throws `DomainException`.
- The controller catches and redirects to the confirmation page with a non-revealing message.
- No lifecycle service is invoked.
- No state mutation occurs.
- No approval/finalization audit event is created.
- No user/role/permission data is exposed.

## Audit non-mutation guarantee

Confirmation denial at the controller level occurs **before** any lifecycle service call. The lifecycle service is the sole producer of domain audit events (e.g., `fx_operational_role_assign`, `sensitive_action_confirmed`). Since the service is never invoked on denial, no approval/rejection/finalization audit evidence is created.

The confirmation service's own audit events (`sensitive_action_confirmed`, `sensitive_action_invalidated`) are triggered only by explicit confirmation/invalidation actions through `SensitiveActionConfirmationController`, not by the enforcement check.

## Preservation of existing controls

| Control | Preserved By |
|---|---|
| Self-review prevention | Lifecycle service checks creator ≠ reviewer; runs unchanged after confirmation |
| Property scope | `findScopedCandidate()`/`findScopedJournal()` check `property_id`; runs unchanged |
| Financial Period | Lifecycle service validates period open; runs unchanged |
| Business Date | Lifecycle service validates business date open; runs unchanged |
| Idempotency | Lifecycle service checks duplicate state; runs unchanged |
| Posting authorization | `draft_finalization_authorized_by` must be non-null; runs unchanged |
| Role segregation | Permission checks remain authoritative; no new permissions granted |
| Break-glass (FX) | `guardBreakGlass()` runs before confirmation; broad admin denied early |

## Explicit exclusions

| Action | Status | Reason |
|---|---|---|
| Candidate creation | Excluded | Separate authority; not an approval/finalization decision |
| Draft materialization | Excluded | Separate authority; not an approval/finalization decision |
| Controlled posting | Excluded | Separate authority; posting is a distinct controlled action |
| Payment execution | Excluded | Separate operational authority |
| Payment proposal create/cancel | Excluded | Creation/cancellation, not approval |
| Role assignment/revocation | Excluded | Already covered by `finance-role-assignment` intent |
| Break-glass activation/deactivation | Excluded | Already covered by `fx-break-glass` intent |
| Non-Finance actions | Excluded | Outside `finance-approval` intent scope |

## No automatic continuation / open redirect rule

After confirming `finance-approval`, the post-confirmation redirect uses a fixed server-owned route mapping. No browser-controlled `return_url`, `Referer`, query parameter, or request body field determines the redirect destination. The fixed mapping routes to a safe dashboard/page; no automatic continuation of the interrupted approval/finalization action occurs. The actor must re-initiate the action after confirmation.

## Consequences

1. **Increased operational friction**: Finance approvers and finalizers must re-enter their password before each decision, or at minimum every 15 minutes.
2. **Audit trail enrichment**: Every confirmation is immutably recorded; every denial is prevented before any domain audit event.
3. **No UX regression for unauthorized actors**: 403 remains instant for actors lacking required permissions.
4. **No lifecycle bypass**: Existing business rules run unchanged after confirmation; confirmation does not substitute for them.
5. **No privilege escalation**: Confirmation alone grants zero permissions, roles, or authority.

## Deferred decisions

| Decision | Status |
|---|---|
| Posting confirmation | Deferred — posting is excluded from this package |
| Payment execution confirmation | Deferred — separate operational authority |
| Cash/banking confirmation | Deferred — no source-proven web route exists |
| Generic administrative decision confirmation | Deferred — `administrative-sensitive-action` intent unused |
| MFA / WebAuthn integration | Deferred — password-only reauthentication |
| Policy registry for confirmation thresholds | Deferred |
| Approval threshold / multi-signature policy | Deferred |
| Supplier invoice approval/rejection (AP) | Deferred — no source-proven web route exists |
| Budget version approval/rejection | Deferred — no source-proven web route exists |
| Cost enrollment approval/rejection | Deferred — no source-proven web route exists |
