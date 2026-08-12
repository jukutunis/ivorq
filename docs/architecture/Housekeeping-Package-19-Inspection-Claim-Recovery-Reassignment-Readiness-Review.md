# Housekeeping Package 19 Inspection Claim Recovery and Reassignment Readiness Review

## Review Status

- Review date: 2026-08-13
- Governance package: `PACKAGE_18_POST_PACKAGE_17_HOUSEKEEPING_GOVERNANCE_SYNCHRONIZATION`
- Contract version: 1.20
- Canonical branch: `ivorq-enterprise-core`
- Canonical SHA reviewed: `37750626f9e0614d26d628a4707bcb205508ae03`
- Accepted Package 17 PR: #51
- Accepted Package 17 feature HEAD: `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`
- Package type: governance-only source review
- Runtime implementation: not authorized in Package 18

## Verdict

```text
PACKAGE_17_INSPECTION_CLAIM_AND_SEGREGATION_ACCEPTED
PACKAGE_18_POST_PACKAGE_17_GOVERNANCE_SYNCHRONIZATION
PACKAGE_19_INSPECTION_CLAIM_RECOVERY_BOUNDARY_FROZEN
PACKAGE_19_RUNTIME_LOCKED_PENDING_PACKAGE_18_MERGE
NO_NEW_ADR_REQUIRED
```

Next runtime package identifier:

`PACKAGE_19_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_RECOVERY_AND_REASSIGNMENT`

Runtime title:

`Controlled Housekeeping Inspection Claim Recovery and Supervisory Reassignment Foundation`

Package 19 remains locked. Package 18 does not implement or authorize its runtime. Package 19 requires Package 18 independent review, explicit Owner authorization and merge, followed by separate explicit Owner runtime authorization, its own branch and Draft PR, independent review, and Owner-authorized merge.

## Accepted Package 17 Provenance

Package 17 is accepted through PR #51 and canonical at `37750626f9e0614d26d628a4707bcb205508ae03`. Its provenance is append-only and must not be collapsed into one inaccurate source SHA:

| Evidence stage | SHA |
|---|---|
| Original source | `20112b623d04c50655e8701566c1dbd156e6dc53` |
| Original metadata | `de3e131c091f02fbb70cabb41006accecb0ce1bd` |
| Legacy/replay correction | `0120b793a1e10f21ae4b6a235e9e75591b792ee4` |
| Package 13 concurrency fixture alignment | `86a3b9e242bbf427353e07131c42f69d983df6e9` |
| Corrected metadata | `40a6b3959411fd6d4a347e03d617905fc7ad9d5f` |
| PostgreSQL bypass closure | `3055610ebd714f592fe395926a180743a5e945d1` |
| Foundation legacy proof alignment | `b45bba591e32963c2bbe7e03a82cc9f997a5d6c1` |
| Package 13 canonical claim fixture | `55399a7c53dc9c5f099ee4570ec1bc1bb6fd757b` |
| Package 13 successor migration isolation | `98ccdeb9be1b9bc60b2df9cda2d31bbe9aed4a59` |
| Final metadata / accepted feature HEAD | `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4` |
| Canonical merge | `37750626f9e0614d26d628a4707bcb205508ae03` |

The Package 17 full registry aggregate exit code was not captured. This review preserves that validation-evidence limitation and does not claim it was captured.

## Accepted Package 17 Runtime Truth

Package 17 intentionally made claim identity durable and immutable. Current runtime provides:

- one Housekeeping-owned canonical post-cleaning Inspection claim writer;
- claimant identity server-resolved from the authenticated User and recorded in `RoomInspection.supervisor_id`;
- deterministic Property-scoped idempotency;
- server-derived source hash;
- server-owned `claimed_at` timestamp;
- `claim_evidence_version = 1`;
- immutable source and claimant identity at application and PostgreSQL levels;
- rejection when claimant equals `CleaningTask.completed_by`;
- terminal authority restricted to the recorded claimant;
- PostgreSQL protection against pending-to-`in_progress` bypass, legacy-style canonical claim insertion, historical supervisor takeover, claim mutation, and claim deletion;
- historical Package 13 compatibility without fabricated Package 17 evidence.

Only the accepted claimant may decide pass/fail. A Package 17 claimant cannot be silently replaced. Current source defines no claim expiry, release, abandonment, reassignment, takeover, emergency recovery, or alternate effective claimant.

## Problem Statement

An `in_progress` canonical Package 17 post-cleaning Inspection can become operationally stuck if its immutable claimant later ceases to be objectively eligible. Current runtime will continue to require the recorded claimant for terminal authority, but that User may no longer satisfy the accepted authorization or current-Property identity boundary.

Objectively source-resolvable original-claimant ineligibility is limited to:

- User inactive or deleted;
- current-Property membership inactive or missing;
- claimant no longer holds `housekeeping.inspection.conduct`.

Current source cannot prove shift schedules, GPS presence, attendance, leave status, HRIS availability, or a subjective "busy" status. Package 19 must not invent or rely on those conditions.

## Source Findings

| Finding | Result | Source evidence |
|---|---|---|
| Original Package 17 claim identity is immutable | `SOURCE_PROVEN` | `RoomInspection::booted()` rejects changes to `supervisor_id`, `claimed_at`, `claim_idempotency_key`, `claim_source_hash`, `claim_evidence_version`, source identity, and deletion after canonical evidence exists; the Package 17 PostgreSQL migration independently enforces the boundary. |
| Terminal authority is claimant-owned | `SOURCE_PROVEN` | `HousekeepingInspectionClaimService::assertTerminalAuthority()` requires the actor to equal `RoomInspection.supervisor_id`; pass context, confirmation, pass, and fail all invoke it. |
| Cleaner/inspector segregation is enforced | `SOURCE_PROVEN` | Claim and terminal authorization reject an actor equal to `CleaningTask.completed_by`; PostgreSQL also validates canonical claim claimant identity. |
| Ordinary claimant eligibility permission exists | `SOURCE_PROVEN` | `HousekeepingInspectionClaimService::CLAIM_PERMISSION` and `RoomInspectionPolicy::conduct()` use `housekeeping.inspection.conduct`. |
| Supervisory intervention permission already exists | `SOURCE_PROVEN` | `HousekeepingPermissionSeeder` and `RoleSeeder` seed `housekeeping.inspection.approve`. |
| Existing policy does not grant recovery authority | `SOURCE_PROVEN` | `RoomInspectionPolicy` has view/create/update/delete/conduct methods and never uses `housekeeping.inspection.approve` for claim recovery or reassignment. |
| No recovery lifecycle exists | `SOURCE_PROVEN` | Housekeeping services, policy, HTTP projection, routes, and Inspection UI expose claim and claimant-owned terminal actions but no expiry, release, abandonment, reassignment, takeover, or recovery command. |
| Current UI confirms takeover is unavailable | `SOURCE_PROVEN` | The Inspection detail projection states that another inspector owns the Inspection and claim takeover is not available. |

These findings prove a bounded recovery need. They do not authorize Package 19 runtime in Package 18.

## Frozen Package 19 Scope

A future Package 19 may implement only:

- one controlled supervisory recovery command;
- only for an `in_progress` canonical Package 17 post-cleaning Inspection;
- only when the original claimant is objectively no longer eligible under the three source-resolvable conditions above;
- an authenticated, active intervenor resolved in the authoritative current Property with active current-Property membership;
- exact intervention permission `housekeeping.inspection.approve`;
- a replacement inspector resolved by the server, never trusted as browser-supplied identity or authority;
- replacement permission `housekeeping.inspection.conduct`;
- active replacement current-Property membership;
- replacement identity different from `CleaningTask.completed_by`;
- replacement identity different from the original claimant;
- preservation of all original Package 17 claim evidence as immutable;
- one separate, append-only Housekeeping-owned intervention/reassignment evidence record;
- mandatory reason;
- deterministic Property-scoped idempotency and conflicting-replay rejection;
- source hash;
- server-owned timestamp;
- intervenor identity;
- original claimant identity;
- replacement claimant identity;
- PostgreSQL integrity and immutability;
- audit evidence;
- concurrency-safe one-winner behavior;
- a server-authoritative current operational projection;
- ADR-040 controlled-confirmation UX.

The exact future class and table names remain implementation details. This review freezes the logical evidence and authority contract, not a schema name.

## Original Claim Must Not Be Overwritten

`RoomInspection.supervisor_id` and Package 17 fields:

- `claimed_at`;
- `claim_idempotency_key`;
- `claim_source_hash`;
- `claim_evidence_version`;

remain original immutable Package 17 claim evidence. Package 19 must not rewrite them merely to substitute a new User.

Future Package 19 must use a separate append-only Housekeeping-owned recovery/intervention evidence identity. Logical effective claimant:

```text
if no accepted recovery evidence:
    effective claimant = Package 17 original claimant

if one accepted Package 19 recovery exists:
    effective claimant = validated replacement claimant
```

At most one recovery/reassignment is allowed per original claim. A second intervention remains future scope.

## Sensitive Action Determination

Supervisory reassignment changes terminal authority over an accepted Housekeeping Inspection and is therefore a sensitive authority-changing action.

Preferred future intent:

`housekeeping-inspection-claim-reassignment`

Future Package 19 should require both:

- exact permission `housekeeping.inspection.approve`; and
- Sensitive Action Confirmation under the preferred future intent before committed reassignment.

Confirmation is not authorization and grants no permission. Package 18 must not register this intent and must not change `SensitiveActionConfirmationService`. ADR-066 already governs explicit registered intent, actor/current-Property binding, password reauthentication, server expiry, and non-secret confirmation evidence. This is an implementation under existing ADR-066, not a trigger for another ADR.

## Terminal Authority After Recovery

Original claim evidence remains immutable. After one accepted recovery:

- only the effective replacement claimant may pass or fail;
- the original claimant loses terminal authority for that Inspection;
- replacement must still differ from `CleaningTask.completed_by`;
- pass still requires `housekeeping.room-readiness.release-ready` plus the separate `housekeeping-room-release-ready` Sensitive Action Confirmation;
- fail still requires `housekeeping.room-readiness.clean`;
- recovery confirmation does not replace release-ready confirmation;
- Package 19 must not override an existing terminal result.

## Database, Idempotency, Audit, and Concurrency Boundary

A future Package 19 implementation must prove:

- original Package 17 claim evidence is unchanged before and after recovery;
- at most one accepted recovery evidence record exists per original claim;
- Property plus idempotency key produces at most one recovery outcome;
- exact replay returns the same intervention identity and effective claimant;
- changed source, reason, intervenor, original claimant, replacement claimant, or Inspection under a reused key fails closed;
- the source hash binds Property, Inspection, original claim evidence identity, original claimant, replacement claimant, intervenor, reason, evidence version, and other accepted authoritative source facts;
- original claimant ineligibility and replacement eligibility are re-resolved under canonical locks immediately before mutation;
- concurrent eligible interventions produce one accepted winner and no mixed, partial, or orphan state;
- a second intervention is rejected at application and PostgreSQL levels;
- cross-Property attempts are non-disclosing and cause zero Housekeeping mutation;
- audit evidence records the controlled intervention without secrets or browser-supplied authority;
- Package 13 readiness/re-cleaning integrity and Package 17 terminal concurrency behavior remain intact.

## Frozen Non-Goals

Package 19 must explicitly exclude:

- automatic claim expiry;
- scheduler-driven recovery;
- timeout-based takeover;
- claimant self-release;
- release back to pending;
- multiple reassignment chain;
- bulk reassignment;
- drag-and-drop Inspection dispatch;
- takeover while the original claimant remains objectively eligible;
- bypassing cleaner segregation;
- terminal pass/fail override by a supervisor;
- changing an existing Inspection result;
- changing Package 15 task assignment;
- HRIS, roster, or attendance integration;
- GPS or staff tracking;
- notifications;
- queues;
- brokers;
- WebSockets;
- external APIs;
- Engineering Work Orders;
- Inventory consumption;
- another ADR.

## ADR Determination

`NO_NEW_ADR_REQUIRED`

ADR-086 already owns the Housekeeping Inspection workflow, maker-checker identity, Housekeeping readiness, and Inspection authority. ADR-040 owns the operational interaction and controlled-action presentation. ADR-066 owns Sensitive Action Confirmation. A controlled recovery of an already-owned Housekeeping Inspection remains inside the same durable Housekeeping boundary and does not change module ownership or create a new cross-domain source of truth.

Package 18 must not create ADR-090 or any other ADR. Package 19 runtime remains locked.

## Publication Gate

Package 19 may start only after:

1. Package 18 receives independent review;
2. the Owner explicitly authorizes and merges Package 18;
3. the Owner separately and explicitly authorizes Package 19 runtime;
4. Package 19 starts from the then-current exact canonical SHA on its own branch;
5. Package 19 ends in its own Draft PR for independent review.

Until those gates are satisfied:

```text
PACKAGE_19_RUNTIME_LOCKED
PACKAGE_19_NOT_IMPLEMENTED
PACKAGE_19_NOT_STARTED
```
