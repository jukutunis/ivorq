# ADR-090: Operational Installation First-Trust and Bootstrap Owner Authority

## ADR Metadata

* **ADR Number:** ADR-090
* **ADR Title:** Operational Installation First-Trust and Bootstrap Owner Authority
* **Date:** 2026-09-18
* **Status:** Approved
* **Related ADRs:** ADR-001, ADR-002, ADR-029, ADR-030, ADR-063, ADR-066, ADR-067
* **Architecture owner:** Foundation / Authorization
* **Affected boundaries:** Authentication, Authorization, User, Company, Property, Audit, deployment governance, and Property bootstrap provenance
* **Implementation status:** Architecture only; no runtime implementation is authorized by this ADR

## Context

An empty operational IVORQ installation may begin with no Users, Companies, Properties, or Property memberships. The normal application paths cannot establish the first trusted human in that state:

- Company, Property, and User creation require an authenticated permission-bearing User;
- tenant-aware login requires an active Company and an active Property membership;
- Property bootstrap provenance requires an authenticated active human User;
- Business Date and Financial Period initialization require the authenticated actor, active Company and Property context, active membership, and explicit permissions.

This is a first-trust circularity. Resolving it by demo seed data, manual SQL, a public setup page, a persistent service actor, or a fixed administrator credential would create an unaudited or reusable root-authority bypass.

The canonical source also contains important constraints and implementation debt:

- `SuperAdminSeeder`, `FoundationSeeder`, and `DatabaseSeeder` contain demo-style fixed identities, fixed password behavior, or sample records and do not define an installation trust identity or lifecycle;
- `super-admin` and `property-admin` currently synchronize against `Permission::all()` and expand with the permission catalog;
- Spatie teams are enabled with `property_id`, while the current model-role and model-permission primary keys do not include that team/property key;
- `LoginController` writes `active_property_id`, while `CurrentPropertyService` directly reads `current_property_id` before falling back to the default Property;
- the canonical runtime does not yet provide the complete privileged-owner identity-verification and MFA-enrollment activation boundary;
- `PropertyBootstrapProvenanceService` correctly requires a real authenticated active User and must not be weakened to accept a synthetic actor.

ADR-030 remains **Proposed**. This ADR independently adopts a bounded set of first-trust identity-security requirements. It does not change ADR-030's status and does not approve ADR-030's broader deferred scope, including general SSO, organization-wide MFA implementation, or the complete identity lifecycle.

## Decision Drivers

- Establish exactly one trusted operational root without relying on pre-existing application authority.
- Ensure the application database cannot enroll or bless its own root secret.
- Terminate installation trust in a real, verified, MFA-enrolled human identity.
- Preserve tenant and Property isolation from the first authoritative write.
- Make execution durable, immutable, idempotent, replay-resistant, and auditable.
- Serialize concurrent attempts so only one first-trust universe can win.
- Keep the pre-authentication attack surface as small as possible.
- Prevent first trust from becoming Finance, Inventory, pilot, cutover, or break-glass authority.
- Preserve B4C's authenticated-human boundary and all later domain authorization services.

## Considered Alternatives

### Manual database insertion

Rejected. Manual insertion bypasses application validation, durable execution evidence, idempotency, concurrency control, role/team semantics, and normal audit governance.

### Demo or production seeder

Rejected. The current seeders contain fixed/default identities, fixed password behavior, demo/sample records, no externally verified root credential, no immutable installation identity, and no governed replay or recovery lifecycle.

### Public first-run web setup

Rejected for the initial architecture. A pre-authentication HTTP endpoint creates a permanent remotely reachable bootstrap surface and adds routing, CSRF, rate-limiting, session, enumeration, and disablement risks before trust exists.

### Long-lived system, service, or deployment actor

Rejected. A persistent non-human root would conflict with human attribution, privileged-user activation, normal login, and the existing human-actor requirements of B4C, Business Date, and Financial Period services.

### Deployment-controlled one-time CLI with an external trust anchor

Selected. It provides the smallest unauthenticated attack surface, deployment-controlled invocation, hidden credential input, explicit environment isolation, PostgreSQL serialization, and durable execution provenance without creating a reusable runtime endpoint.

## Decision

Operational first trust is a separately governed installation root of trust.

The approved sequence is:

```text
deployment-controlled external trust anchor
-> one-time deployment-time CLI bootstrap boundary
-> durable first-trust execution evidence
-> exactly one initial human owner identity
-> exactly one initial Company
-> exactly one initial Property
-> exactly one active default Property membership
-> narrowly scoped installation-owner role assignment
-> identity verification and mandatory MFA enrollment
-> human normal login
-> normal IVORQ authorization thereafter
```

The installation root is not a reusable authorization bypass. It ends when the initial foundation is durably established and the external bootstrap credential is invalidated. All later actions use ordinary authenticated human authorization, tenant/Property context, permission checks, lifecycle services, and audit controls.

This ADR approves architecture only. It does not authorize a command, service, model, migration, role, permission, route, controller, seeder, verification mechanism, MFA mechanism, tenancy write, membership write, B4C change, or operational bootstrap execution.

## External Trust Anchor

Before the bootstrap CLI executes, the deployment owner must provision all of the following through an independently controlled deployment or secret-management channel outside the IVORQ application database:

- immutable `installation_id`;
- exact environment identity;
- trusted bootstrap-secret verifier;
- verifier expiry and lifecycle policy;
- stable verifier reference identifier.

The CLI receives the candidate secret only through hidden TTY/stdin or an approved one-shot secret channel. It must verify the candidate against the already-provisioned trusted verifier before any domain or first-trust database write.

The CLI must fail closed when the external verifier is absent, malformed, expired, unavailable, environment-mismatched, installation-mismatched, or fails verification.

The CLI must not establish trust by accepting a candidate secret and then hashing or storing that same candidate as its own proof of authority. The application database cannot self-enroll its root credential.

### Secret handling

The raw bootstrap secret must never be stored in IVORQ persistence. The external verifier should remain outside the IVORQ database. The first-trust ledger may retain only non-secret evidence:

- verifier reference identifier;
- verifier fingerprint;
- installation ID;
- environment;
- verification timestamp;
- request fingerprint;
- canonical Git SHA.

The secret and reusable secret-derived material are prohibited from:

- command arguments and process listings;
- URLs and query strings;
- source code and Git;
- committed environment files;
- application and infrastructure logs;
- audit logs;
- exception payloads;
- B4C evidence;
- first-trust evidence.

Completion must permanently invalidate the credential at the external trust source. Ordinary IVORQ operation must not provide a way to reactivate it.

## Installation Identity and Environment Isolation

The stable first-trust identity is:

```text
environment + installation_id
```

`installation_id` is deployment-controlled and immutable for one installation. It must not derive from Company name, Company slug, Property code, Property slug, role name, or owner email.

Operational and Rehearsal must use separate:

- installation IDs;
- secrets and verifier material;
- first-trust ledgers;
- human identities;
- Company and Property records;
- membership and role-assignment evidence;
- B4C and completion evidence.

Rehearsal authority can never be promoted, copied, or reclassified into Operational authority. Environment labels inside shared authority-bearing records are not sufficient isolation; deployment and persistence boundaries must prevent rehearsal credentials and identities from authorizing Operational state.

## One-Time CLI Boundary

The initial implementation boundary must be a deployment-time CLI command.

This choice is mandatory because it provides:

- a smaller unauthenticated attack surface;
- deployment-controlled invocation;
- hidden credential input;
- no permanent pre-authentication HTTP endpoint;
- clearer operational and rehearsal isolation;
- explicit execution provenance and terminal disablement.

ADR-090 does not authorize a first-run web setup flow. A future web-based alternative would require a separate architecture decision and may not weaken any invariant in this ADR.

The command must not expose ordinary bypass options such as `--force`, `--ignore-history`, `--adopt`, or `--reset-owner`.

## Durable First-Trust Ledger

Foundation / Authorization owns a durable, immutable first-trust execution ledger. Its governed lifecycle must include `InProgress`, `Completed`, and `Failed`, or semantically equivalent explicit states.

The ledger must conceptually retain:

- run ULID;
- environment;
- installation ID;
- request fingerprint;
- canonical Git SHA;
- external verifier reference and fingerprint;
- verification timestamp;
- start timestamp;
- completion or failure timestamp;
- resulting User ULID;
- resulting Company ULID;
- resulting Property ULID;
- non-secret completion evidence fingerprint;
- controlled failure code when applicable.

The ledger must not support soft deletion. Identity fields and bound result identifiers become immutable when established. Completed is terminal. Failed is terminal unless a separately governed owner recovery decision authorizes a bounded recovery path.

The ledger is pre-authentication installation evidence. It must not fabricate an authenticated IVORQ User as the actor before that User exists. It may identify the independently controlled deployment principal by a non-secret external reference.

## Idempotency and Concurrency

PostgreSQL-backed serialization is required on `environment + installation_id`.

The database must enforce exactly one first-trust winner. Concurrent attempts must not create two Users, Companies, Properties, memberships, role assignments, or trust universes.

Required behavior:

- acquire the stable installation serialization boundary before evaluating protected history;
- enforce uniqueness for the installation execution identity;
- bind an immutable request fingerprint and canonical SHA;
- create and bind the initial foundation through an atomic transaction or bounded orchestration with equivalent fail-closed guarantees;
- permit an interrupted exact execution to resume only against the same durable identity and matching request evidence;
- make an exact completed retry mutation-free;
- reject ordinary replay after completion.

Any change in secret identity, verifier identity, request fingerprint, canonical SHA, owner email, Company input, Property input, environment, or installation ID must fail closed.

A crash before domain commit must not leave a partial authority universe. A crash after a domain commit but before terminal ledger completion may resume only when the stored identifiers, request evidence, and resulting records match exactly. Any ambiguity or partial inconsistency requires owner-controlled recovery.

## Empty-State and History Rule

Normal first-trust establishment is permitted only when the protected bootstrap state is genuinely empty. At minimum, the serialized check must prove:

```text
users = 0
companies = 0
properties = 0
property_user memberships = 0
completed first-trust records = 0
unexplained administrative identities = 0
```

Unexpected domain authority or history must not be adopted by email, name, Company slug, Property code, Property slug, or role name. Existing demo, legacy, partial, or conflicting rows require separate owner recovery governance.

Failed or partially inconsistent state cannot be silently restarted with a different installation identity, verifier, human identity, or tenancy input.

## Seeder Prohibition

Production first trust must never execute or depend on:

- `SuperAdminSeeder`;
- `FoundationSeeder`;
- `DatabaseSeeder`.

They contain fixed/default identity or password behavior, demo/sample records, no immutable installation trust identity, no externally verified root credential, no durable first-trust lifecycle, and no concurrency or replay governance. Password hashing of a fixed credential does not make that credential an acceptable root of trust.

## Initial Human Identity and Activation

Installation trust must terminate in one real human `User`. It must not terminate in a shared integration identity, long-lived system actor, permanent deployment actor, or service account.

The initial identity contract requires:

- server-generated User ULID;
- canonicalized human email;
- eventual case-insensitive email uniqueness at the database boundary;
- `is_system_admin = false`;
- no direct User permissions;
- password handling only through the canonical hashing boundary;
- no fixed/default password;
- no generated password displayed or logged;
- no arbitrary department, position, employee, or legacy Property coupling.

Identity creation and operational activation are separate states.

Creating the User and tenancy foundation does not grant privileged operational access. The human must remain unable to perform B4C, Business Date, Financial Period, Finance, Inventory, pilot, cutover, or other privileged actions until both are complete:

1. identity verification; and
2. mandatory MFA enrollment for the privileged owner.

The current canonical runtime does not yet provide the complete verification and MFA activation boundary. That boundary is an implementation prerequisite. A future package must not close the gap by stamping `email_verified_at` merely because the CLI succeeded, silently activating a privileged owner without verification, bypassing MFA, invoking break-glass, or issuing a temporary super-admin password.

After activation, the human must authenticate through the normal Cloud Name, email, and password flow and establish normal tenant and Property context.

## Relationship to ADR-030

ADR-030 remains **Proposed**.

ADR-090 independently adopts only these first-trust principles:

- no secret logging;
- tenant-aware human authentication after bootstrap;
- identity verification before privileged activation;
- mandatory MFA before privileged owner use;
- separation of human and non-human identities;
- no permanent authentication bypass.

ADR-090 does not activate general SSO, organization-wide MFA, full identity-state implementation, federation, integration-identity lifecycle, or the other deferred ADR-030 scope. Broader ADR-030 activation requires separate governance.

## Initial Role and Permission Boundary

The default first-bootstrap role must not be `super-admin` or `property-admin`. Both currently synchronize against `Permission::all()` and therefore expand when the permission catalog expands.

A future dedicated role named `installation-owner`, or an equivalently governed name, is required. It must be:

- Property-scoped;
- assigned with `is_system_admin = false`;
- based on an explicit permission allowlist;
- free of direct User permissions;
- prohibited from automatic `Permission::all()` synchronization;
- free of pilot and cutover authority;
- free of CostControl activation authority;
- free of payment and Finance posting authority;
- free of Inventory transaction and posting authority.

Its exact permission allowlist is a future implementation decision constrained by this ADR. Required Business Date or Financial Period permissions must be granted explicitly through ordinary authorization and do not become implicit first-trust powers.

### Role/team schema debt

Spatie teams are enabled with `property_id`, but the current model-role and model-permission primary keys do not include the team/property key. This is source-proven implementation debt.

ADR-090 does not repair that schema. B5A runtime must not assume unverified multi-Property role-assignment semantics. Single-initial-Property establishment may proceed only after a focused implementation review proves the exact assignment behavior. Multi-Property role behavior requires separate bounded hardening when that review shows it is necessary.

## Company, Property, and Membership Authority

The immutable first-authority relationship is:

```text
first-trust run
-> first Company ULID
-> first Property ULID
-> first human User ULID
-> first active default Property membership
```

The Company and Property must be created as part of the governed first-trust transaction or bounded orchestration. Arbitrary existing rows must not be adopted.

A dedicated bounded first-membership authority is required. It must establish exactly one initial membership with the bound User and Property, active status, server-owned join time, and default-Property status. Generic `UserService` membership attachment is not by itself a root authorization boundary.

Company name, Company slug, Property code, Property slug, and owner email are business identifiers or inputs. They are not the immutable installation execution identity.

## Property Context Debt

`LoginController` writes `active_property_id`, while the direct session tier in `CurrentPropertyService` reads `current_property_id` and otherwise falls back to the User's default Property.

ADR-090 does not repair this inconsistency. The first single-Property owner may rely only on source-proven default-Property fallback after normal login and focused implementation proof. Multi-Property B5 operation must not be declared ready while this inconsistency remains unresolved.

No bootstrap command may manufacture a web session, impersonate the new User, or directly inject active Company or Property session state.

## B4C Ordering and Referential Linkage

The required order is:

```text
first trust
-> initial human, Company, Property, and membership
-> identity verification and MFA enrollment
-> human normal login
-> authenticated B4C provisioning provenance
-> Business Date initialization
-> Financial Period initialization
-> later bounded Inventory bootstrap
```

`PropertyBootstrapProvenanceService` must continue requiring a real authenticated active User. It must not accept an unauthenticated deployment principal, synthetic User, service actor, or CLI impersonation.

A future package must make B4C provenance durably reference the completed first-trust run. Referential integrity is preferred; evidence JSON alone is not sufficient root authority. ADR-090 does not modify B4C.

## Failure and Recovery

Completed first trust cannot be reopened, reset, adopted, or replayed.

Failed or inconsistent first-trust state is terminal for ordinary operation. Recovery requires a separately governed, owner-controlled decision with exact scope, immutable evidence, and no reuse of ordinary bootstrap bypass flags.

Recovery must not:

- silently change the first human identity;
- bind arbitrary existing Company or Property rows;
- discard or rewrite first-trust evidence;
- reactivate a consumed external credential;
- convert Rehearsal authority into Operational authority;
- grant additional runtime permissions.

## Explicit Non-Authority

First trust does not authorize:

- B4C completion;
- Business Date initialization;
- Financial Period initialization;
- Inventory provisioning or posting;
- Finance or General Ledger posting;
- payment execution;
- CostControl pilot authorization or execution;
- CostControl cutover;
- DEFERRED activation;
- break-glass access;
- ordinary role or permission administration outside the bounded initial assignment.

Those remain later governed human actions through their owning modules and approved authorization services.

## Implementation Gates

Merging ADR-090 does not authorize runtime implementation or operational B5 provisioning. Future packages must separately close:

1. durable first-trust authority and CLI implementation;
2. privileged-human identity verification and MFA activation;
3. atomic first Company, Property, and membership establishment;
4. narrow `installation-owner` role and permission configuration;
5. B4C first-trust referential integration;
6. Property-context compatibility for the intended scope;
7. the Inventory manifest/collision contract.

Exact implementation package splitting requires separate Owner authorization after ADR-090 reaches canonical closure.

## Consequences

### Positive

- Establishes a trustworthy escape from a genuinely empty operational database.
- Prevents the application database from self-enrolling its root credential.
- Minimizes the pre-authentication attack surface.
- Produces one immutable and auditable installation authority chain.
- Preserves human attribution and normal tenant/Property authorization.
- Prevents demo data, broad administrators, and service actors from becoming production roots.
- Preserves B4C and later lifecycle authorization boundaries.

### Costs and limitations

- Requires external secret-management and deployment ownership.
- Requires new Foundation / Authorization persistence and command work in a later package.
- Requires identity verification and MFA enrollment before privileged use.
- Requires focused proof or correction of role/team and Property-context behavior.
- Requires explicit recovery governance for unexpected history or terminal failure.
- Does not resolve the separate Inventory manifest/collision blocker.

## Governance State Preserved

- B4B remains canonical and closed.
- B4C remains canonical and closed.
- CostControl remains `SYNCHRONOUS_TRANSITIONAL_ACTIVE`.
- Operational and Rehearsal writes remain unauthorized by this ADR.
- Pilot remains unauthorized.
- Cutover remains unauthorized.
- DEFERRED remains inactive.
- The Inventory manifest/collision blocker remains unresolved and preserved.
- No protected worktree or runtime source is changed by this architecture decision.
