# ADR-089: Atomic Hospitality Checkout Execution Orchestration and Attestation Boundary

## ADR Metadata

* **ADR Number:** ADR-089
* **ADR Title:** Atomic Hospitality Checkout Execution Orchestration and Attestation Boundary
* **Date:** 2026-07-19
* **Status:** Proposed
* **Related ADRs:** ADR-001, ADR-002, ADR-004, ADR-029, ADR-034, ADR-040, ADR-066, ADR-067, ADR-084, ADR-085, ADR-086, ADR-087, ADR-088
* **Accepted predecessor:** FD-B13 Checkout Execution Readiness Review at `fbb289abf4bbfeb2f3ae801e05e98619a61f7814`
* **Runtime implementation:** Not authorized

## Status

Proposed

## Context

FD-B13 accepted the verdict:

```text
CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES
```

FD-B13 also recorded the architecture trigger:

```text
NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION
```

The current repository proves strong read-only checkout readiness foundations, but those foundations are not execution authority. GLF-D and GC-A1 are top-level `REPEATABLE READ, READ ONLY` projections, reject nested transaction participation through `GLF_D_REQUIRES_TOP_LEVEL_READ_TRANSACTION` and `GC_A1_REQUIRES_TOP_LEVEL_READ_TRANSACTION`, and return source fingerprints for review/display evidence. They do not lock owner-domain rows for a Front Desk checkout write transaction and do not create terminal financial or cashier attestations.

The current Front Desk checkout execution boundary still returns `can_execute=false`. Source inspection proves no checkout execution write route, execute permission, sensitive confirmation intent, terminal stay state, checkout evidence table, checkout command, checkout migration, or checkout handoff/outbox contract exists.

## Source-Proven Topology

Classification:

```text
SAME_DATABASE_TRANSACTION_PROVEN
```

Source evidence:

- Front Desk, PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date / Night Audit, Housekeeping, Engineering, Authorization, Audit, and Outbox code all live inside the same Laravel application and repository.
- `config/database.php` defines one default connection chosen by `DB_CONNECTION`; PostgreSQL is the source-proven validation/runtime target through `pgsql`.
- Domain models and services do not declare separate runtime database connections for Front Desk, PMS Guest Ledger, General Cashier, Business Date, Night Audit, or Housekeeping. Test-only disposable PostgreSQL connections are used for concurrency proof and migration proof, not production domain isolation.
- Current operational commands use Laravel `DB::transaction()` and PostgreSQL row locks through `lockForUpdate()`.
- `config/queue.php` has `after_commit` set to `false` for queue connections; durable after-commit behavior is not a general source-proven platform primitive.
- The current `outbox_messages` table is inventory-shaped through `source_inventory_transaction_id`; it is not a general checkout handoff outbox.
- No current source proves a separate checkout database, service, queue-only boundary, external API, or distributed transaction participant.

Because same-database participation is source-proven, future checkout execution may be designed as one PostgreSQL transaction that calls owner-domain participating attestation ports. This ADR does not implement those ports.

## Decision Drivers

- Atomicity: one checkout outcome or no checkout outcome.
- Domain ownership: Front Desk orchestrates but does not own foreign-domain facts.
- Stale-evidence prevention: read-only projections cannot authorize a later write.
- Night Audit serialization: checkout and Night Audit start must share authoritative lock ordering.
- Financial and cashier consistency: PMS Guest Ledger, PMS Cashiering, and General Cashier must make terminal execution-time determinations.
- Idempotency: response-loss retry must return the committed outcome without duplicate stay closure.
- Auditability: immutable checkout evidence must be server-generated and source-backed.
- Failure recovery: rollback must leave no partial stay closure or foreign-domain repair.
- Room-turnover handoff: Housekeeping must receive a durable transactional handoff without Front Desk mutating Housekeeping readiness.
- Future service extraction: current architecture should not block later Go/Python extraction, but current source does not justify distributed transaction semantics.

## Considered Alternatives

### Alternative A - Shared PostgreSQL transaction with owner-domain participating ports

Front Desk owns checkout orchestration. Owner-domain services join the already-active approved checkout transaction, use the same database connection, lock only their own authoritative rows, calculate terminal readiness independently, return minimized server-generated attestation evidence, and perform no Front Desk mutation.

Assessment: best atomicity in the current Laravel/PostgreSQL topology; prevents stale evidence while preserving ownership; requires strict global lock ordering and short transactions; compatible with current transaction and row-lock patterns; future service extraction remains possible by replacing participating ports with durable reservation/freeze contracts later.

### Alternative B - Durable reservation/freeze and saga orchestration

Owner domains create short-lived checkout holds or durable attestations, followed by orchestration and compensation/release.

Assessment: useful if domains become separate services or databases, and it improves long-running recovery visibility. It adds operational complexity now, requires new hold/release schema and compensation semantics, and is unnecessary while the participating domains are in one PostgreSQL transaction.

### Alternative C - Read projections followed by Front Desk commit

Front Desk reads GLF-D, GC-A1, BD-A1, and NA-A1 projections, then later commits a Front Desk checkout write.

Assessment: rejected. FD-B13 already proved this produces stale-evidence races. A financial, cashier, Business Date, or Night Audit source can change between read-only projection and commit. A fingerprint is evidence, not a lock.

### Alternative D - Distributed transaction / two-phase commit

Coordinate multiple databases or services through distributed transaction semantics.

Assessment: rejected for current source. No participating checkout domain is isolated behind another production database, service, or external API. Distributed transaction semantics would add complexity without a source-proven need and would not align with current Laravel/PostgreSQL patterns.

## Decision

Selected architecture:

```text
SINGLE_POSTGRESQL_TRANSACTION_WITH_OWNER_DOMAIN_PARTICIPATING_ATTESTATION_PORTS
```

Future checkout execution must run as one approved Front Desk-owned orchestration transaction on the same PostgreSQL connection. Front Desk may call owner-domain participating attestation ports inside that transaction, but those calls do not transfer lifecycle ownership. Readiness projections remain display/review evidence; execution-time participating attestations are transaction-bound evidence while authoritative locks remain held.

This ADR is governance only. It does not authorize runtime implementation, migrations, routes, permissions, services, ports, jobs, events, tests, baseline restamps, or enum changes.

## Ownership Matrix

| Domain | Checkout role | Ownership preserved |
|---|---|---|
| Front Desk | Owns checkout command, terminal stay transition, checkout execution evidence, idempotency identity, orchestration order, and immutable successful outcome | Must never directly update PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date, Night Audit, Housekeeping, Engineering, Accounting, GL, AR, tax, or revenue tables |
| PMS Guest Ledger | Provides execution-time terminal financial attestation under PMS-owned locks | Owns folios, folio items, guest ledger balance, settlement readiness, settlement evidence, and folio closure boundary |
| PMS Cashiering | Participates through PMS financial attestation where guest payment allocation, deposit, refund, reversal, and payment lifecycle facts are evaluated | Owns guest payment allocation, tender transaction lifecycle, deposits, refunds, and reversals |
| General Cashier | Provides execution-time terminal cashier obligation attestation under General Cashier-owned locks | Owns cashier sessions, cash custody, cashier accountability, handover, count, close, and reconciliation |
| Business Date / Night Audit | Owns Property Business Date state, Night Audit active-run scope, and close-lock serialization | Does not own checkout, folio, payment, cashier, tax, revenue, GL, AR, or Housekeeping state |
| Housekeeping | Consumes durable checkout handoff and owns room-turnover lifecycle | Front Desk must not mutate Housekeeping room readiness directly |
| Engineering | Optional read-only or future consumer only when separately authorized | Engineering availability is not a mandatory guest-departure gate |
| Accounting / AR | Owns receivable lifecycle after accepted transfer | Front Desk must not mutate AR |
| Accounting / GL | Owns journal entries, revenue recognition, tax posting, and financial-period control | Front Desk checkout is not direct GL posting authority |
| Finance | Governs, reviews, configures, and consumes financial outcomes | Does not own operational checkout execution |

## Transaction Participation Contract

A future participating attestation service must:

- require an already-active approved checkout transaction;
- reject use outside that controlled transaction unless it exposes a separate read-only API;
- use the same database connection as the checkout transaction;
- lock only its own relevant authoritative rows;
- independently re-resolve property, stay, reservation, guest, folio, cashier, and source relationships as applicable;
- fail closed on missing, stale, conflicting, unavailable, or cross-property evidence;
- return a minimized immutable value object or result;
- return status plus source fingerprint/hash;
- expose no browser-controlled trusted state;
- perform no Front Desk mutation;
- never silently call GLF-D or GC-A1 top-level read-only projections inside the write transaction.

Readiness projection means display/review evidence before execution. Participating attestation means transaction-bound execution evidence while authoritative locks remain held.

## Global Lock Order

All future checkout-related commands and Night Audit start coordination must obey one compatible Property and Business Date lock order.

High-level global lock order:

1. Active `properties` row.
2. Current `property_business_dates` row.
3. Front Desk stay row.
4. Checkout idempotency/execution identity.
5. Night Audit active-run scope.
6. PMS Guest Ledger owner-domain rows.
7. General Cashier owner-domain rows.
8. Immutable Front Desk checkout evidence identity.
9. Terminal stay transition.
10. Transactional handoff/outbox record.

The global order controls cross-domain acquisition. Owner domains retain their internal lock order after the orchestrator reaches that owner-domain step. Immutable evidence reads may use source identity verification without row locks when source immutability is PostgreSQL-proven; mutable source rows that can affect terminal readiness must use `FOR UPDATE`. A service must not acquire an earlier global lock after it has acquired a later global lock.

Future runtime packages must define lock timeout and deadlock/serialization retry policy. The allowed default is a short transaction with bounded retry for PostgreSQL deadlock or serialization failures, never an unbounded automatic retry loop. A retry must re-enter through the same idempotency identity and revalidate all authoritative state. Lock timeout fails closed with no partial stay closure.

## Attestation Contract

### PMS Financial Attestation

Decision:

```text
transaction-bound value evidence only
```

The repository already uses immutable Front Desk evidence, source hashes, and projection fingerprints, but no durable PMS owner-domain checkout attestation table exists. Creating durable owner-domain evidence is runtime scope and is not authorized here. The future PMS participating port must return transaction-bound value evidence that Front Desk persists by reference/status/hash in immutable checkout execution evidence.

The PMS-owned execution-time terminal financial attestation must determine under owner-domain locks:

- relevant folios;
- current fresh and cached totals;
- zero-balance requirements;
- payment allocation state;
- deposit application/reversal state;
- refund state;
- payment reversal state;
- accepted AR transfer when applicable;
- settlement holds;
- posting completeness;
- completed-settlement conflict;
- relationship consistency;
- property and stay identity.

It must not transfer folio, payment, deposit, refund, reversal, AR, or settlement ownership to Front Desk.

### General Cashier Attestation

The General Cashier-owned execution-time terminal obligation attestation must determine under owner-domain locks:

- guest, stay, and reservation identity;
- cashier session obligations;
- cash-related guest payment lifecycle;
- refunds;
- deposits;
- reversals;
- unresolved cashier accountability;
- source relationship conflicts;
- source fingerprint and minimized status.

Front Desk receives only the result and fingerprint required for checkout evidence, not cashier internals.

## Front Desk Terminal Outcome

Future runtime packages must define one canonical terminal stay state and one immutable successful checkout outcome per stay. This ADR does not select a final enum name. The implementation package must choose a bounded, repository-consistent terminal name after checking current enum and hospitality naming evidence.

Future immutable checkout execution evidence must include:

- `property_id`;
- Front Desk stay identity;
- idempotency key with `property_id + idempotency_key` uniqueness;
- successful terminal stay uniqueness;
- server-owned actor and timestamps;
- latest FD-B7 reference;
- Business Date identity;
- Night Audit source status/fingerprint;
- PMS financial attestation status/fingerprint;
- General Cashier attestation status/fingerprint;
- source hashes/fingerprints;
- audit relationship;
- deterministic replay data for response loss.

Successful evidence must be immutable at application level and PostgreSQL update/delete level.

## Night Audit Coordination

Checkout and Night Audit start must serialize through the same authoritative high-level locks or an equivalent approved shared primitive. The accepted source-proven path is the same `properties` then `property_business_dates` lock order already used by `NightAuditRunStartService`.

The design must guarantee:

- Night Audit cannot become active between checkout final close-lock validation and checkout commit;
- checkout cannot commit against a changed Business Date;
- Night Audit start either waits for checkout or checkout waits for Night Audit;
- the winner revalidates full authoritative state after obtaining locks;
- no read-only fingerprint alone is treated as a lock.

This ADR does not authorize Business Date close, advance, reopen, or Night Audit checkpoints.

## Housekeeping Handoff

Future checkout success and room-turnover handoff record must persist in the same transaction. Housekeeping remains the room-turnover owner. Front Desk must not update Housekeeping readiness directly.

The handoff payload must contain minimized identifiers only: property, stay, reservation/canonical stay reference, checkout execution id, business date, occurred_at, and idempotency/correlation key. It must not include raw guest PII or raw financial snapshots.

Decision: future runtime should supplement the current inventory-shaped outbox with a Front Desk-specific checkout handoff/outbox or owner-domain handoff table unless a separately approved shared outbox architecture exists by then. The current `outbox_messages` table must not be generalized by this package.

The consumer must be idempotent and retryable. Delivery failure cannot reopen checkout, duplicate checkout, or mutate foreign-domain state as repair. Engineering consumption is optional and separately authorized.

## Authorization and Confirmation

Future execute permission is frozen as:

```text
frontdesk.checkout-execution.execute
```

Future Sensitive Action Confirmation intent is frozen as:

```text
frontdesk-checkout-execution
```

Both remain unimplemented and unauthorized in this package.

Future execution ordering:

1. Resolve authenticated server context.
2. Authorize execute permission before querying or resolving the requested stay.
3. Resolve same-property stay after authorization.
4. Return non-disclosing 404 for unknown or cross-property stay.
5. Validate a fresh sensitive confirmation bound to actor, company, property, intent, and session.
6. Enter the controlled transaction.

Confirmation expiry must be revalidated immediately before transaction entry. Successful checkout must consume/invalidate the confirmation. Idempotent replay after response loss may return the already-committed checkout outcome with the same idempotency identity without requiring a fresh confirmation, because no new mutation occurs. A new checkout attempt, changed idempotency identity, or uncommitted retry requires a valid fresh confirmation. Broad administrators require explicit operational assignment or break-glass policy, not implicit checkout authority.

## Failure Recovery

Required semantics:

- validation failure before transaction: fail closed, no success evidence;
- authorization failure: controlled failure before stay lookup;
- sensitive confirmation failure: fail before mutation;
- lock timeout: rollback and fail closed;
- deadlock/serialization: rollback and retry only through bounded idempotent policy;
- changed Business Date: rollback and fail closed;
- Night Audit activation: rollback and fail closed;
- changed financial evidence: rollback and fail closed;
- changed cashier evidence: rollback and fail closed;
- duplicate idempotency key with same identity: replay committed outcome or in-flight controlled result;
- duplicate idempotency key with conflicting identity: fail closed without object disclosure;
- already checked-out stay: return terminal duplicate/blocked outcome without another mutation;
- transaction rollback: no success evidence and no terminal stay transition;
- response loss after commit: idempotent replay returns committed evidence;
- outbox delivery failure: checkout remains immutable and handoff remains pending/failed for retry;
- partial infrastructure outage: fail closed unless already committed.

The invariant is:

```text
one transaction
one terminal outcome
no partial stay closure
no foreign-domain repair
no fabricated readiness
idempotent replay
controlled non-disclosing failure
```

## Security Consequences

- Tenant/property isolation: every participating service must re-resolve property scope server-side.
- Query suppression: execute authorization occurs before stay lookup; cross-property stays receive non-disclosing 404 only after authorization.
- Stale evidence: read-only projections and browser-supplied readiness are not trusted.
- Replay: idempotency controls response loss and duplicate submission without duplicate closure.
- Confused deputy: browser cannot supply actor, property, permission, source status, or attestation result.
- Privilege escalation: boundary-view permission does not imply execute permission; broad roles do not receive checkout execution by default.
- Cross-domain mutation: Front Desk may call owner-domain ports but may not write foreign-domain tables.
- PII minimization: handoff payloads and Front Desk evidence store references/statuses/hashes, not raw guest PII or raw financial internals.
- Audit forgery: actor, timestamps, evidence hashes, and source identities are server-owned.
- Deadlock denial-of-service: global lock order, short transactions, lock timeout, and bounded retry are mandatory.
- Lock-duration risk: future implementation must keep the transaction narrow and avoid external calls inside the transaction.

## Consequences

Positive:

- Selects an atomic, source-consistent architecture for checkout execution.
- Preserves PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date / Night Audit, Housekeeping, Engineering, Accounting, and Finance ownership.
- Resolves the Night Audit clear-then-start race through shared authoritative locks.
- Distinguishes readiness projection from execution-time attestation.
- Defines the prerequisite runtime package sequence without authorizing implementation.

Negative:

- Checkout execution remains blocked until several runtime prerequisite packages exist.
- Participating attestation ports require careful lock ordering and focused concurrency tests.
- Future extraction to services may require a later durable hold/saga architecture.

## Rejected Behavior

- Read-only projection followed by unrelated write.
- Direct foreign-domain mutation by Front Desk.
- Browser-supplied readiness, financial state, cashier state, Business Date state, Night Audit state, actor, property, or source fingerprint.
- Distributed two-phase commit without proven need.
- Checkout without transactional Housekeeping handoff.
- Checkout during active Night Audit.
- Checkout implementation before prerequisite packages.
- Treating `can_execute=false` as optional.

## Implementation Sequence

Runtime prerequisite categories must remain locked until ADR-089 is independently reviewed, Owner-accepted, and merged.

1. ADR-089 accepted and merged.
2. Shared checkout transaction-participation and Property/Business Date/Night Audit concurrency foundation.
3. PMS Guest Ledger execution-time terminal financial attestation.
4. General Cashier execution-time terminal obligation attestation.
5. Front Desk terminal stay state and immutable checkout execution evidence foundation.
6. Transactional Housekeeping room-turnover handoff/outbox.
7. Checkout Sensitive Action Confirmation intent and execute permission.
8. Final Front Desk checkout execution command and interaction layer.

The final checkout command remains last. Runtime package codes are not assigned by this ADR.

## Validation Notes

Source inspection for this ADR verified:

- ADR-089 number was free before creation.
- `origin/ivorq-enterprise-core` was `fbb289abf4bbfeb2f3ae801e05e98619a61f7814`.
- same Laravel/PostgreSQL topology is source-proven.
- GLF-D and GC-A1 are read-only top-level projections and not participating execution attestations.
- Night Audit start locks `properties`, then `property_business_dates`, then active Night Audit run scope.
- `can_execute=false` remains canonical in current Front Desk runtime.
- no checkout execution write route exists.
- no `frontdesk.checkout-execution.execute` permission exists.
- no `frontdesk-checkout-execution` sensitive intent exists.
- no Front Desk checkout terminal enum exists.
- no runtime checkout execution evidence table exists.

No runtime source is changed by this ADR.
