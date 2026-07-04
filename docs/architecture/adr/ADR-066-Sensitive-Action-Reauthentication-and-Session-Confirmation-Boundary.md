# ADR-066: Sensitive Action Reauthentication and Session Confirmation Boundary

**Status:** Accepted for architecture boundary and controlled implementation
**Date:** 2026-07-05

## Context

ADR-064 established four operational Finance roles with strict segregation: candidate creation, review, materialization, finalization authorization, and journal posting are each owned by a distinct role. ADR-065 added a controlled assignment-and-revocation workflow with server-owned property/team scope, audit evidence, and a break-glass boundary for broad bootstrap administrators.

ADR-065 also declared that break-glass activation must require source-proven reauthentication or sensitive-session confirmation, bound to actor and current property/team context, with mandatory reason and audit evidence.

This ADR defines the reusable primitive that fulfills that requirement — and also serves every future sensitive action that demands explicit password reconfirmation before execution, without coupling to any specific module, role, or lifecycle.

## Why ordinary authenticated session presence is insufficient

A valid authenticated web session proves identity at session start, but does not prove the current human operator intends to execute a high-consequence action now. Session hijacking, unattended workstations, and confused-deputy scenarios all make reauthentication a standard security control for sensitive operations in enterprise finance and administration systems.

The existing session confirms: the browser holds a valid session cookie. Reauthentication confirms: the human at the keyboard knows the account password right now.

## Reusable primitive scope

This package creates a single reusable Laravel-side security primitive:

- A service that accepts a registered intent, verifies the authenticated actor's current password, and — only on success — writes minimal non-secret confirmation metadata into the server-side session.
- A controller that exposes Inertia confirmation pages and confirmation/invalidation endpoints.
- An Inertia page for password entry and feedback.
- A focused PostgreSQL test suite proving the primitive's security properties.

The primitive does not enforce itself on any route. It provides `hasValidConfirmation` and `requireValidConfirmation` methods that future packages may call before executing a sensitive action.

## Explicit distinctions

| Concept | Definition in this ADR |
|---|---|
| **Authentication** | Proving identity at session start (login). |
| **Authorization** | Checking whether an authenticated actor holds a required permission or role. |
| **Reauthentication** | Proving identity again by supplying the current password during an active session. |
| **Session confirmation** | The resulting server-owned, time-limited metadata proving reauthentication occurred. |
| **Role assignment** | Granting or revoking a Spatie role for a target user in a property/team context. |
| **Break-glass activation** | A future workflow where a broad administrator explicitly activates temporary operational access. This primitive enables break-glass; it does not implement the break-glass workflow. |

## Registered intent boundary

Only a fixed, server-owned allowlist of intent strings is accepted. Arbitrary browser-supplied intent strings must be rejected with controlled, non-revealing feedback.

### Initial registered intents

| Intent | Purpose |
|---|---|
| `finance-role-assignment` | Reconfirm password before assigning or revoking an FX operational Finance role. |
| `finance-approval` | Reconfirm password before executing a Finance approval action. |
| `fx-break-glass` | Reconfirm password before activating broad-administrator break-glass for FX workspace access. |
| `administrative-sensitive-action` | Reconfirm password before any sensitive administrative action. |

Future packages may add another registered intent only through an explicit approved package.

Wildcard, prefix-matching, and free-text intents are prohibited. The allowlist is exhaustive and closed.

## Server-owned actor, company, property, team, and session context

The browser must not supply:

- actor identity;
- tenant;
- company;
- property;
- team;
- session identifier;
- expiry;
- confirmation timestamp;
- audit payload;
- role;
- permission;
- Finance amount;
- rate;
- account;
- mapping;
- journal;
- candidate;
- source transaction.

The service resolves the authenticated actor exclusively from `$request->user()` (web guard). Active company is resolved from `$request->session()->get('active_company_id')`. Active property/team is resolved from `$request->session()->get('active_property_id')`. Current authenticated session identity is bound implicitly through the Laravel session store.

## Password verification boundary

Password verification uses `Hash::check()` against the authenticated actor's stored password hash. This is the same Laravel convention used throughout the repository (`password` cast `'hashed'` on the User model at `Modules/Foundation/User/Models/User.php`).

Password validation never exposes whether an account, password, role, property, company, or target object exists. Wrong password returns a single controlled, non-revealing error.

## Session storage and minimum metadata rule

Only minimum non-secret confirmation metadata is stored in the server-side Laravel session under a single structured key per intent.

Stored metadata includes:

- actor identifier (user ULID);
- registered intent string;
- active company identifier (when available);
- active property/team identifier;
- confirmation server timestamp;
- expiry server timestamp.

Never stored: password, password hash, credentials, tokens, raw audit payload, arbitrary browser object data, role identifiers, permission identifiers, or financial values.

## Confirmation validity duration and expiry

A confirmation is valid for 15 minutes maximum from the server-side confirmation timestamp.

The 15-minute duration is a documented constant within the confirmation service. It is not a configuration file value and not supplied by the browser.

Expiry is evaluated server-side using `now()` / Carbon. Browser timestamps are never trusted.

## Binding contract

A confirmation is bound to all of:

1. **Authenticated actor** — confirmation fails if the authenticated user differs from the confirming actor.
2. **Registered intent** — confirmation fails if the requested intent differs from the confirmed intent.
3. **Active company** — confirmation fails if the current active company differs from confirmation-scope company (when available at confirmation time).
4. **Active property/team** — confirmation fails if the current active property/team differs from confirmation-scope property/team.
5. **Current authenticated session** — confirmation is stored in the Laravel session and is inherently scoped to that session. Session invalidation, logout, or regeneration destroys all confirmations.

## Invalidation and automatic fail-closed behavior

The service supports explicit invalidation of a single intent's confirmation in the current session. Confirmation also fails closed automatically (returns `false` from `hasValidConfirmation`, throws controlled exception from `requireValidConfirmation`) when:

- confirmation metadata is missing from the session;
- metadata is structurally malformed;
- metadata has expired (server time exceeds stored expiry);
- actor identifier does not match the current authenticated user;
- company identifier does not match the current active company;
- property/team identifier does not match the current active property/team;
- intent string does not match the requested intent.

## Audit evidence requirements

Successful confirmation and explicit invalidation are recorded through the source-proven audit mechanism (`Modules\Foundation\Audit\Services\AuditService::log()`).

Audit evidence includes:

- actor (via `auth()->id()`);
- intent string;
- action (`sensitive_action_confirmed` or `sensitive_action_invalidated`);
- current company identifier (when available);
- current property/team identifier (when available);
- server timestamp;
- correlation context (`X-Request-Id` or `X-Correlation-Id` header, when present).

The auditable model for confirmation events is the authenticated User. This follows the existing convention where audit records are anchored to an Eloquent model.

Invalid password attempts and rejected/expired confirmations are not audited through the audit-log mechanism. They fail controlled with non-revealing feedback only.

## Error and feedback boundary

All error responses use the repository's existing controlled feedback conventions:

- Validation errors return redirect with `->withErrors()` for form-level feedback.
- Controlled domain errors (invalid intent, expired confirmation) return redirect with `->with('error', message)`.
- Password rejection returns a single non-revealing message such as "The password is incorrect."
- Expired, missing, or mismatched confirmation returns a controlled non-revealing message.

Never returned to the browser: raw password hash, session identifier, internal metadata structure, user/role/permission existence hints, or financial record existence hints.

## Explicit non-grant

Confirmation alone grants:

- **No permission** — does not call `givePermissionTo`, `syncPermissions`, or any Spatie permission API.
- **No role** — does not call `assignRole`, `syncRoles`, or any Spatie role API.
- **No Finance authority** — does not enable any Finance lifecycle transition, approval, or posting.
- **No lifecycle authority** — does not change the state of any financial or operational entity.
- **No approval authority** — does not authorize, approve, or finalize any record.
- **No break-glass access** — does not activate or deactivate broad-administrator operational access.

The primitive is a prerequisite check. Permission and authority remain governed by the existing Spatie permission system and service-level authorization gates.

## No current enforcement

This package creates the reusable confirmation primitive only. It does not yet require confirmation for:

- FX role assignment;
- Finance approval;
- journal finalization;
- FX workspace access;
- FX broad-admin break-glass;
- any existing route.

Those integrations are deferred to later approved packages with their own ADRs and test coverage.

## No direct user permissions

No direct `model_has_permissions` record is created. No `Permission` is created. No `Role` is created. No `model_has_roles` record is created or modified by this primitive.

## No external identity provider

Password verification uses only the local stored password hash. No external identity provider, OAuth, SAML, LDAP, or WebAuthn integration is introduced.

## No new schema or migration

All confirmation state lives in the existing Laravel session store. No database migration, table, column, or model schema change is required.

## Security consequences

1. **Reduced session-hijack risk**: even with a valid session cookie, sensitive actions require current password knowledge.
2. **Unattended workstation protection**: confirmation expires after 15 minutes, limiting the window for unauthorized use.
3. **No privilege escalation**: confirmation never grants permissions or roles.
4. **Audit trail**: every successful confirmation and explicit invalidation is recorded immutably.
5. **No secret exposure**: password, hash, session identifier, and internal metadata are never returned to the browser.

## Operational consequences

1. **User friction**: operators performing sensitive actions must re-enter their password at least every 15 minutes.
2. **Session dependency**: confirmations are destroyed on logout, session expiry, and session regeneration.
3. **No offline mode**: confirmation requires a live session and active property context.
4. **Intent growth requires governance**: each new intent must be explicitly approved and registered in the allowlist.

## Deferred decisions

| Decision | Status |
|---|---|
| MFA / WebAuthn integration | Deferred — this primitive supports password-only reauthentication |
| External identity provider (OAuth, SAML, LDAP) | Deferred |
| Global sensitive-action policy registry | Deferred — initial allowlist is inline constant |
| Passwordless confirmation (biometric, hardware token) | Deferred |
| Confirmation risk scoring | Deferred |
| Device binding | Deferred |
| Full break-glass workflow (activation, expiration, deactivation) | Deferred |
| Finance approval integration | Deferred |
| FX role-assignment integration | Deferred |
| Administrator `Permission::all()` reduction | Deferred |
