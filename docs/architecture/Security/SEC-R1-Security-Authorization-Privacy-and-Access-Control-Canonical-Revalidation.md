# SEC-R1 Security, Authorization, Privacy, and Access-Control Canonical Revalidation

**Contract:** IVORQ Package Execution Contract v1.22

**Audit base:** `e56d56fb084b452cf2c34d761a3c386cbd30c9fd` (`ivorq-enterprise-core`)

**Audit branch:** `codex/sec-r1-security-canonical-revalidation`

**Package type:** source-backed gap audit; no runtime implementation

**RoleSeeder blob:** `8519c4618ec614aafb94f2611753bfbad5a147ff`

**Executed tests:** none; committed source and tests were read as evidence

**Expected delta:** one documentation file; production `0`, migration `0`, dependency `0`, test `0`

## 1. Executive verdict

Canonical Security is **not closed**. It contains several strong, accepted control planes: Cloud Name tenant-aware login, installation-owner activation/MFA/session binding, sensitive-action password confirmation, narrow FX role assignment and break-glass, source-owned permissions, domain-service maker/checker controls, and an operational First Trust implementation that remains deliberately disabled. These controls are real runtime, not planning-only claims.

The broader foundation remains mixed. General users do not inherit installation-owner `auth_epoch`, session-row, and Property binding; user and role administration contain unscoped repository paths; the active Property key is split between `active_property_id` and `current_property_id`; generic role administration lacks the governance of the FX-specific path; broad bootstrap roles retain `Permission::all()`; audit evidence is divided among three ledgers; and privacy remains largely governance-only. The highest-value next package is therefore a scoped identity-administration, offboarding, and session-governance consolidation—not another MFA foundation and not a rewrite of accepted domains.

Operational First Trust remains **DISABLED**. No production user, role, session, MFA, AWS, Business Date, Financial Period, pilot, or cutover action is authorized or performed by SEC-R1.

## 2. Current canonical source map

| Source family | Principal evidence | Canonical truth |
|---|---|---|
| Authentication | `Modules/Foundation/Authentication`, authentication routes, `bootstrap/app.php` | Tenant-aware password login and installation-owner high-assurance runtime coexist with a backward-compatible general-user path. |
| Authorization | `Modules/Foundation/Authorization`, `config/permission.php`, permission migrations/seeders | Spatie teams are enabled with Property as team key; source permissions are explicit, but broad global roles and unscoped generic role CRUD remain. |
| User | `Modules/Foundation/User` | User CRUD and Property membership exist; lifecycle is an `is_active` boolean and offboarding is not an authoritative, complete revocation transaction. |
| Audit/activity | `Modules/Foundation/Audit`, `Modules/Foundation/Activity`, Spatie activity log | Custom audit log, legacy activity log, Spatie activity log, and identity-security events overlap without one canonical enterprise ledger. |
| Privileged identity | owner activation/MFA models, services, PostgreSQL migration, tests | Installation-owner enrollment, challenge, recovery, immutable security events, and epoch-bound sessions are implemented and tested. |
| Sensitive actions | `SensitiveActionConfirmationService`, ADR-066/067, integrated domain controllers | Reusable 15-minute, actor/intent/company/Property/session-bound password confirmation is implemented and broadly integrated. |
| Narrow Finance security | ADR-064/065, FX role assignment and break-glass services/controllers/tests | Strong, source-proven role segregation, self-assignment denial, scoped membership checks, reason, reauth, expiry, and audit exist for FX only. |
| Cross-domain authorization | representative Finance, Procurement, Inventory, PMS/Front Desk services/tests | Accepted services resolve the server actor, check permissions and Property scope, and enforce maker/checker within the owning domain. |
| Privacy | ADR-031 plus representative runtime searches | Architecture is detailed but proposed; no shared classification, masking, retention, legal-hold, residency, or privacy-request runtime exists. |
| First Trust | ADR-090 and `Modules/Foundation/Authorization` First Trust runtime | Architecture/runtime is present; live operational activation remains separately gated and disabled. |

Governing ADR status was read from canonical source: ADR-002 is **Active**; ADR-029, ADR-030, and ADR-031 are **Proposed**; ADR-064/065/066 are accepted for controlled implementation; ADR-067 is accepted for controlled implementation; ADR-090 is **Approved**.

## 3. Capability matrix

Every consequence below is an implementation consequence, not authority to implement it in SEC-R1.

| Capability | Classification | Source evidence and reason | Implementation consequence |
|---|---|---|---|
| Cloud Name / Tenant-aware login | COMPLETE | `LoginController` resolves an active Company slug before tenant-scoped credential lookup; `UserRepository::findByEmailAndCompany()` requires an active Property membership in that Company. | Preserve this boundary; regression-proof it when general session controls are consolidated. |
| Login and session creation | PARTIAL | Web/API login, rate limiting, Sanctum tokens, and `user_sessions` records exist. Non-owner API tokens use `['*']` with no expiry and non-owner sessions are not authoritatively revalidated. | Add bounded token/session policy and general-user authoritative validation without replacing the stores. |
| Property context | PARTIAL | Login writes `active_property_id`; `EnsureActivePropertyContext` validates active membership where applied. `CurrentPropertyService` reads `current_property_id` and otherwise falls back to default Property; Foundation user/role/audit routes omit `active.property`. | Establish one canonical session key and require authoritative Property context on security administration routes. |
| Account status and password controls | PARTIAL | Global authenticated security reloads `is_active`; tenant-aware reset and hashed passwords exist. Installation-owner password changes revoke by epoch; general password/reset paths use weaker, different revocation behavior. | Unify password/security-change revocation through one authoritative general-user boundary. |
| Authentication event evidence | PARTIAL | `IdentitySecurityEventService` has a closed schema and immutable PostgreSQL ledger for owner activation/MFA/session events. Ordinary login failure/success/logout coverage is event/listener based and not equivalent. | Extend the closed security-event taxonomy to general authentication without logging secrets. |
| Session inventory and revoke-one | MISSING | `UserSessionRepository` can read/revoke rows, but no authenticated session-center route/controller/UI exposes listing or targeted revocation. | Add actor-scoped session inventory and revoke-one operations over the existing store. |
| Logout-all and security-change revocation | PARTIAL | `SessionRevocationService` deletes tokens, `user_sessions`, and Laravel sessions and increments `auth_epoch`; it is authoritative for installation owners. General deactivation/password paths delete only subsets or use `logoutOtherDevices`. | Make the revocation service safe and authoritative for all users, including current-session rules and audit. |
| Stale-session invalidation | PARTIAL | `AuthenticatedSecurityService` checks epoch, session row, Property, membership, activation, and owner role only when an `OwnerActivation` exists; non-owner users return valid after the active-user check. | Apply epoch/session/Property validation to ordinary authenticated users. |
| Device/session provenance | PARTIAL | `user_sessions` and `user_devices` models/tables capture partial IP/user-agent/channel/token/session facts; device lifecycle and user-facing provenance are absent. | Normalize useful provenance and surface it through the session center; do not create another store. |
| User creation, membership, default Property | PARTIAL | Creation binds the server-resolved current Property and sets an active default membership; department/position validation is Property-scoped. No governed removal/default replacement/orphan prevention workflow exists. | Add membership mutation invariants and an atomic default-Property replacement/removal workflow. |
| User lifecycle and offboarding | PARTIAL | Runtime has `is_active`, soft delete, token/session-row deletion on deactivation, and owner activation states. It lacks the ADR-030 invited/active/suspended/disabled/archived lifecycle for ordinary users and complete role/membership/Laravel-session neutralization. | Implement atomic offboarding/deactivation with preserved attribution, full revocation, and no orphan active authority. |
| Permission and role catalogs | COMPLETE | `PermissionSeeder` is the explicit permission source; `RoleSeeder` and Spatie models define roles; wildcard permissions are disabled in config. | Keep source-controlled catalogs and forbid browser-defined permissions. |
| Property/team-scoped assignments | PARTIAL | Spatie teams use `property_id`; accepted services set team context. The permission migration's `model_has_roles` and `model_has_permissions` primary keys omit `property_id`, preventing the same grant identity across multiple Properties, while generic paths do not consistently scope target roles. | Correct the schema/assignment invariant in a dedicated migration only after compatibility analysis; scope all mutations by Property. |
| Cross-Tenant user/role isolation | PARTIAL | Login and FX assignment are scoped. `UserRepository::paginate/find`, `RoleRepository::find/delete`, and audit `find` are global; policies do not consistently constrain Company/Property targets. | Close enumeration and mutation paths at query, policy, and route layers with negative cross-tenant tests. |
| Direct grants and broad bootstrap roles | LEGACY_ACTIVE | `super-admin` and `property-admin` receive `Permission::all()`; `UserService::assignRole()` is a generic ungated helper; `is_system_admin` has a global `Gate::before` bypass. Accepted ADRs explicitly preserve this legacy behavior. | Do not silently remove it; replace through an approved migration/governance package with break-glass and compatibility evidence. |
| Specialized Finance role segregation | COMPLETE | ADR-064/065 plus `FxOperationalRoleAssignmentService` constrain four roles, deny self/conflict, require active Property membership, a reason, manager permission, confirmation, and audit. | Reuse its control pattern; do not generalize by weakening its narrow boundary. |
| Maker/checker support | COMPLETE | Foundation supplies authenticated actor and permission/Property context; representative Purchase Request/Order, Finance approval, Inventory exception, and Front Desk services compare authoritative maker/actor evidence and fail closed. | Keep maker/checker domain-owned; Security packages must preserve actor integrity and must not introduce a parallel workflow engine. |
| Sensitive-action reauthentication | COMPLETE | ADR-066 semantics are implemented by `SensitiveActionConfirmationService`: registered intent, password verification under lock, actor/intent/company/Property/auth-epoch binding, server session binding, 15-minute expiry, explicit invalidation, minimal metadata, and audit. It is a reusable time window, not a one-shot token, as designed. | Preserve the primitive; separately decide where privileged actions require it and whether MFA/WebAuthn later strengthens it. |
| Installation-owner MFA runtime | COMPLETE | Owner activation, TOTP enrollment/verification, recovery codes, bounded challenges, secret encryption/digests, epoch-bound credentials, immutable events, UI endpoints, and PostgreSQL tests are present. | Do not rebuild this runtime or claim it is operationally activated. |
| MFA coverage for all privileged roles | PARTIAL | Owner runtime is strong, but GM, Finance, Accounting, property admin, and system admin do not universally require MFA. ADR-030 remains Proposed. | Extend privileged MFA policy only after accepted role/lifecycle scope; reuse owner primitives where safe. |
| General privileged-role controls | PARTIAL | FX roles have narrow assignment/reauth/break-glass. Generic role CRUD can alter role permissions without target-Property policy checks, approval, MFA, or a unified privileged-role taxonomy. | Add privileged-role registry, scoped assignment approval, reauth/MFA policy, and immutable evidence without changing accepted domain permissions. |
| General role-assignment governance | MISSING | No general production workflow provides requester/approver separation, target scope, reason, temporary authority, conflict checks, or audited assignment; the FX workflow is intentionally narrow. | Build one governed assignment plane after tenant/session closure; do not expose `UserService::assignRole()` directly. |
| Canonical Audit Log | PARTIAL | Custom `audit_logs` captures actor, Property, action, subject, old/new, IP, user agent, URL, tags, timestamp and has guarded model writes. It lacks Company, session/correlation as first-class columns, database immutability, retention enforcement, exhaustive coverage, and scoped `find`; sanitization excludes only three top-level keys. | Consolidate evidence ownership, add database immutability/scoped reads, and implement schema-aware redaction before expanding coverage. |
| Security-event evidence | PARTIAL | Owner identity events are closed-schema and database-immutable; sensitive confirmation, FX break-glass, and FX assignment use custom audit events. Ordinary auth, user offboarding, generic role/permission changes, and denied events are fragmented or absent. | Define one source-owned security-event catalog and map existing ledgers without discarding accepted evidence. |
| FX break-glass | COMPLETE | `FxBreakGlassAccessService` limits broad roles, requires scoped password confirmation and reason, binds actor/company/Property/session, inherits the 15-minute expiry, audits activation/deactivation, and grants no new permission. | Preserve narrow FX behavior and add revocation-epoch revalidation when session controls are unified. |
| Shared enterprise break-glass | DEFERRED | ADR-065 explicitly defers global break-glass hardening; no shared enterprise activation/approval framework exists. | Require a separate accepted architecture/implementation package; do not create a second ad hoc framework. |
| Runtime privacy controls | MISSING | Source searches found no shared data classification, masking, retention, legal-hold, residency, privacy-request, or restricted-export runtime. Some individual services minimize payloads and secrets. | Do not claim privacy readiness; sequence a source-backed vertical runtime only after governance acceptance. |
| API and privileged-route authorization | PARTIAL | Critical operational routes commonly use `auth` + `active.property` and service permissions; auth APIs use Sanctum + `auth.security` + team middleware. Foundation user/role/audit routes use only `auth` + `verified`, and non-owner tokens are wildcard. | Apply consistent active Company/Property and scoped policy enforcement to Foundation administration and narrow token abilities. |
| Mass assignment and client authority | PARTIAL | Form requests whitelist fields; user creation supplies Property server-side; FX role/property and sensitive actor context are server-owned. `User::$fillable` includes `is_system_admin`, generic services accept role names, and user requests can toggle security state (`is_active`) without a dedicated offboarding command. | Remove security-state authority from generic model updates and use explicit commands with policy, reason, reauth, and audit. |
| Security UI operational readiness | PARTIAL | User, role, permission, audit/activity, sensitive-confirmation, FX assignment, FX break-glass, and owner MFA pages exist. General session center, general privileged assignment, broad MFA administration, privacy operations, and a unified security event viewer do not. | Deliver UI only with authoritative server controls; do not label backend-only tables operationally complete. |
| First Trust architecture/runtime | COMPLETE | ADR-090 and canonical First Trust authority, parser/verifier, empty-state guard, orchestrator, protected-state and concurrency tests are present. | Preserve the accepted implementation and its fail-closed boundaries. |
| Operational First Trust | DEFERRED | Owner authorization explicitly keeps Operational First Trust disabled; no live initialization is performed. | Requires a later explicit owner activation package and live safeguards. |
| Privacy governance implementation | BLOCKED_BY_ADR | ADR-031 is Proposed and contains material policy choices for classification, retention, legal hold, residency, deletion, and disclosure. | Accept/freeze the governance boundary before implementing an enterprise privacy control plane. |
| SSO/federated identity | DEFERRED | ADR-030 identifies SSO as future scope; canonical authentication is local Cloud Name/password plus owner TOTP. | Do not introduce an identity provider in the current Security sequence. |
| Business Date, Financial Period, pilot/cutover | OUT_OF_SCOPE | SEC-R1 is a read-only Security audit and those operational activations are independently governed. | Leave all states unchanged. |
| Audit/activity ledger overlap | DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION | `audit_logs`, legacy `activity_logs`, Spatie `activity_log`, and `identity_security_events` all carry evidence with different schemas and guarantees. | Freeze one canonical security/audit ownership map before adding more ledgers. |
| General auth versus owner high-assurance split | DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION | The owner path enforces epoch/session/Property binding and immutable events; the non-owner path deliberately bypasses those checks for backward compatibility. | Generalize the accepted controls without weakening owner invariants or duplicating stores. |
| Active Property key split | DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION | Authentication and operational controllers use `active_property_id`; `CurrentPropertyService` reads `current_property_id` then falls back to a default. | Migrate to one key with compatibility and negative isolation tests. |

Classification totals: **COMPLETE 8; PARTIAL 18; MISSING 3; LEGACY_ACTIVE 1; DUPLICATE_BOUNDARY_REQUIRES_CONSOLIDATION 3; BLOCKED_BY_ADR 1; BLOCKED_BY_OTHER_DOMAIN 0; DEFERRED 3; OUT_OF_SCOPE 1.**

## 4. Authentication/session findings

Cloud Name tenant resolution and active membership lookup are strong. Login throttling, generic credential feedback, web/API session creation, and global active-user reloading exist. The installation-owner path additionally binds every credential to `auth_epoch`, a `user_sessions` row, active Property membership, activation state, and the Property-scoped installation-owner role.

The security gap is exact: `AuthenticatedSecurityService` returns `true` for any active user without an `OwnerActivation`, so ordinary sessions and tokens are not checked against epoch, authoritative session row, active Property, or current membership. General tokens are wildcard and non-expiring. `SessionRevocationService` already provides the correct full revocation shape, but ordinary deactivation and password flows do not consistently use it. The existing session tables must be consolidated, not replaced.

## 5. User lifecycle/offboarding findings

User creation is server-bound to the current Property and creates an active default membership. Runtime ordinary lifecycle is nevertheless a boolean `is_active`, not ADR-030's invited/active/suspended/disabled/archived model. Deactivation deletes Sanctum tokens and `user_sessions` rows, but does not increment epoch, delete Laravel sessions, remove scoped roles, close memberships, or guarantee a replacement default. Soft delete has no explicit access-neutralization transaction. Global list/find paths also precede policy evaluation and permit cross-tenant enumeration risk.

Offboarding is therefore PARTIAL and must be an explicit, atomic command that preserves immutable attribution while neutralizing all active authority.

## 6. RBAC and privileged-role findings

The permission catalog is explicit and wildcard matching is disabled. Spatie team context provides a useful Property boundary, and the FX control plane demonstrates strong assignment governance. However, generic user/role paths do not meet that standard: role targets are globally loaded, policy checks only permission names, broad roles retain all permissions, and a system-admin boolean bypasses every Gate.

The team pivot primary keys omit `property_id`; therefore a user cannot safely hold the same role or direct permission identity in more than one Property. This is a schema limitation, not evidence of isolation completeness. Direct production user permissions remain prohibited by ADR, but the database/model capability exists and no universal runtime guard enforces the prohibition.

Representative accepted Finance, Procurement, Inventory, and PMS/Front Desk services correctly treat the authenticated server actor as authoritative and enforce maker/checker in the owning service. Foundation Security should protect that actor and scope; it should not centralize or rewrite domain decisions.

## 7. MFA findings

Installation-owner MFA is real and complete as a runtime foundation: activation lifecycle, enrollment, TOTP verification, recovery codes, replay counter, bounded challenges, encrypted/digested secrets, session/epoch binding, event evidence, endpoints, and committed PostgreSQL tests exist. This is not merely tables/models.

MFA is not enterprise-wide. GM, Finance, Accounting, property-admin, and system-admin authority is not universally MFA-gated, and there is no generic privileged-user enrollment/administration UI. Operational MFA and Operational First Trust remain disabled unless separately authorized. SEC-R1 does not activate either.

## 8. Sensitive-action/reauth findings

The ADR-066 primitive is complete for its designed password-only confirmation window. Confirmation state is held in the server-side session and bound to actor, registered intent, Company, Property, `auth_epoch`, confirmation/expiry timestamps, and optional commercial-evidence hash. It expires after 15 minutes, fails closed on mismatch/inactivity/epoch change, can be explicitly invalidated, and emits minimal custom audit evidence.

The confirmation is intentionally reusable within its validity window; it is not a one-use token. It grants no permission and does not replace authorization or maker/checker. MFA/WebAuthn strengthening is deferred, not an implementation defect in the accepted password primitive.

## 9. Break-glass findings

FX break-glass is a narrow, complete implementation for `super-admin` and `property-admin` access to FX operations. It requires the actor's existing broad authority, current Property scope, a valid `fx-break-glass` confirmation, a reason, server session state, expiry, and activation/deactivation audit. It never assigns a role or permission.

There is no shared enterprise break-glass control plane, approval chain, or privileged access inventory. ADR-065 explicitly defers that work. The FX implementation must be reused as evidence and preserved, not copied into a second framework.

## 10. Audit/security-event findings

The custom Audit Log is append-only by convention at the Eloquent layer, but the database has no update/delete trigger. Read-listing is Property-filtered only when current Property resolves; `find(id)` and `forModel()` are unscoped. It has no Company column and no first-class session/correlation field. Its shallow sanitizer removes `password`, `remember_token`, and `updated_at` only at the top level, while generic observers may pass broad model attributes and the logger stores full URLs. This does not satisfy ADR-002/031 secret and restricted-data guarantees.

The installation-owner `identity_security_events` ledger is materially stronger: closed event/context/metadata schema, bounds, database constraints, and an immutable update/delete trigger. Coverage is narrow. Role changes, generic offboarding, general authentication, and permission changes are fragmented across custom audit, legacy Activity, Spatie activity, and ordinary Laravel events. Consolidation must define ownership and compatibility rather than deleting history.

## 11. Tenant/Property isolation

The foundational isolation model is strong in Cloud Name authentication, active-Property middleware, accepted operational services, owner session binding, and FX assignment. It is not proven end-to-end for Foundation administration. A permitted Company A administrator can reach global user pagination before per-record policy, role IDs are globally resolved for update/delete, Audit Log detail is globally found by ID, and the Property context key can fall back to a default different from the session's `active_property_id`.

SEC-P01 must prove negative isolation for enumeration and mutation of users, roles, memberships, sessions, and privileged assignments. Company/Property IDs must remain server-resolved; route parameters may identify a target only after scoped lookup.

## 12. Security UI readiness

Operational pages exist for user CRUD, role CRUD, permission viewing, audit/activity viewing, sensitive confirmation, FX role assignment, FX break-glass, and installation-owner MFA flows. UI readiness is PARTIAL because there is no general session/security center, targeted session revocation, ordinary MFA administration, governed general privileged-role assignment, unified security-event view, or privacy operations workspace.

UI gaps must not be closed with client-side-only controls. Each workspace follows an authoritative route → request/policy → service → scoped repository/model → database invariant → audit/test chain.

## 13. Legacy/duplicate boundaries

1. `audit_logs`, legacy `activity_logs`, Spatie `activity_log`, and `identity_security_events` overlap.
2. Installation-owner security and general-user authentication use different assurance models over the same users/tokens/sessions.
3. `active_property_id` and `current_property_id` split Property context.
4. Narrow FX role governance coexists with broad global bootstrap roles and generic role helpers.
5. Custom Audit observers and `LogsActivity` may duplicate mutation evidence with different redaction and immutability guarantees.

These are consolidation boundaries, not authorization to delete legacy data or break accepted runtime.

## 14. Explicit deferred/blocked boundaries

- **DEFERRED:** enterprise-wide break-glass; SSO/federated identity; Operational First Trust.
- **BLOCKED_BY_ADR:** enterprise privacy classification, retention, legal hold, residency, disclosure, and deletion runtime until ADR-031 is accepted/frozen for implementation.
- **OUT_OF_SCOPE:** AWS/live changes, production users/roles/sessions, Business Date, Financial Period, pilot, cutover, and accepted business-domain rule revalidation.
- No current item is classified `BLOCKED_BY_OTHER_DOMAIN`; cross-domain services were sampled only as consumers of Security primitives.

## 15. Implementation package sequence

Only two coherent implementation packages are frozen:

1. **SEC-P01 — Tenant-scoped identity administration, offboarding, and session-governance consolidation.** Close the immediate isolation and revocation gaps over existing stores.
2. **SEC-P02 — Privileged access, MFA-policy, role-assignment, break-glass, and audit/privacy operations consolidation.** Generalize privileged controls only after SEC-P01 establishes reliable identity, session, and Property authority. Privacy runtime remains limited to ADR-accepted scope; if ADR-031 is still Proposed, privacy implementation stays blocked rather than being invented.

No SEC-P03 is manufactured. If later ADR acceptance produces a coherent privacy/residency control plane too large for SEC-P02, the Owner may authorize a separately scoped package then.

## 16. Selected SEC-P01 exact scope

**Selected next package:** `SEC-P01_TENANT_SCOPED_IDENTITY_OFFBOARDING_AND_SESSION_GOVERNANCE`

In scope:

- choose `active_property_id` as the canonical authenticated session Property key and provide a bounded compatibility migration from `current_property_id`;
- require active Company/Property context on user, role, permission, audit, session, and privileged-administration routes;
- scope user, role, audit-detail, membership, session, and assignment queries before authorization, with cross-Company and cross-Property negative tests;
- make ordinary authenticated web/API credentials validate `auth_epoch`, authoritative session row, current active membership, Company/Property binding, and bounded token expiry/abilities using existing stores;
- make password/security changes, deactivation, soft-delete/offboarding, and logout-all use one atomic revocation boundary that increments epoch and removes tokens, `user_sessions`, Laravel sessions, and sensitive confirmations;
- implement explicit ordinary-user lifecycle transitions needed for safe activation/deactivation/offboarding while preserving audit attribution; do not implement speculative HR lifecycle;
- govern Property membership removal/default replacement so no active identity retains orphan authority or loses its only default through an invalid transition;
- provide an actor-scoped session center with session inventory, current-session marker, revoke-one, and logout-all;
- emit closed-schema, secret-minimized security events for the new transitions and prove no new audit ledger is created;
- preserve installation-owner invariants, FX assignment/break-glass, all accepted domain maker/checker rules, and RoleSeeder/PermissionSeeder contents unless a later explicit correction authorizes them.

Out of scope:

- MFA expansion or production activation;
- generic privileged-role assignment redesign;
- removal of broad roles or `Permission::all()`;
- enterprise break-glass;
- privacy classification/retention/residency runtime;
- First Trust activation, AWS/live actions, Business Date, Financial Period, pilot, or cutover;
- modifications to accepted Finance, Procurement, Inventory, PMS/Front Desk, Housekeeping, or CostControl lifecycles.

## 17. Operational safety declaration

SEC-R1 is documentation-only. Tests were not executed because the classification was determinable from canonical source and existing committed tests. Production code, migrations, dependencies, fixtures, seeders, permissions, roles, users, sessions, MFA state, and AWS/live state were not changed. `RoleSeeder` remains blob `8519c4618ec614aafb94f2611753bfbad5a147ff`.

- Operational First Trust = **DISABLED**.
- Operational MFA activation = **NOT PERFORMED**.
- Business Date = **NOT STARTED**.
- Financial Period = **NOT STARTED**.
- Pilot/cutover = **NO/NO**.
- SEC-P01 = **SELECTED, NOT ACTIVATED**.
