# ADR-089: Atomic Hospitality Checkout Execution Orchestration and Attestation Boundary

## ADR Metadata

* **ADR Number:** ADR-089
* **ADR Title:** Atomic Hospitality Checkout Execution Orchestration and Attestation Boundary
* **Date:** 2026-07-19
* **Status:** Approved
* **Related ADRs:** ADR-001, ADR-002, ADR-004, ADR-029, ADR-034, ADR-040, ADR-066, ADR-067, ADR-084, ADR-085, ADR-086, ADR-087, ADR-088
* **Accepted predecessor:** FD-B13 Checkout Execution Readiness Review at `fbb289abf4bbfeb2f3ae801e05e98619a61f7814`
* **Runtime implementation:** GLF-E accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 accepted and true fast-forward merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. The savepoint rollback lock-continuity defect is corrected. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is the current authorized bounded runtime prerequisite. Checkout execution remains unauthorized.

## Status

Approved

Accepted and fast-forward merged at:

```text
1682dec0fb7f654e77888a476b4ec55a1507610b
```

NA-A2 is the first runtime prerequisite slice after ADR-089 approval. It implements only shared Property / Business Date locking and Night Audit transaction participation. NA-A2 is accepted and fast-forward merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. A savepoint rollback lock-continuity defect was source-proven: an attestation could be issued inside a nested savepoint, the savepoint rolled back releasing PMS row locks, and the retained exact PHP attestation object would incorrectly pass validation because it shared the same backend PID and outer transaction ID. GLF-E-S1 is accepted and true fast-forward merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. The savepoint rollback lock-continuity defect is corrected. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is the current authorized bounded runtime prerequisite. Checkout execution remains unauthorized.

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

This ADR is approved architecture. Approval permits separately authorized prerequisite packages to implement bounded owner-domain participation. It does not authorize checkout execution, migrations outside an approved package, routes, permissions, jobs, events, or enum changes. NA-A2 is authorized only for shared Property / Business Date operational locking and Night Audit checkout concurrency participation.

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
6. PMS Guest Ledger / PMS Cashiering owner-domain rows.
7. General Cashier owner-domain rows.
8. Immutable Front Desk checkout evidence identity.
9. Terminal stay transition.
10. Transactional handoff/outbox record.

The global order controls cross-domain acquisition. Owner domains retain their internal lock order after the orchestrator reaches that owner-domain step. Immutable evidence reads may use source identity verification without row locks when source immutability is PostgreSQL-proven; mutable source rows that can affect terminal readiness must use `FOR UPDATE`. A service must not acquire an earlier global lock after it has acquired a later global lock.

NA-A2 implements only steps 1, 2, and 5. Future Front Desk checkout orchestration must explicitly acquire its approved Front Desk stay and checkout identity locks between the shared Property / Business Date locks and the Night Audit active-run scope. No convenience method may jump directly from Business Date to Night Audit for future checkout.

PMS-owned financial locks remain held while General Cashier validates its owner-domain obligations. General Cashier consumes PMS attestation references after the PMS lock step and must not reacquire earlier PMS locks after the General Cashier step. This preserves PMS Cashiering ownership, prevents General Cashier from becoming a second source of truth for guest payment facts, and avoids lock inversion.

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
- checkout-relevant settlement rows and facts;
- settlement holds;
- posting completeness;
- completed-settlement conflict;
- relationship consistency;
- property and stay identity.

The PMS participating attestation runs before General Cashier participation. It must lock and evaluate PMS Guest Ledger and PMS Cashiering-owned payment, allocation, deposit, refund, reversal, AR, folio, and settlement rows needed for terminal checkout evaluation; return only the minimized terminal financial result required by Front Desk; return approved cash-linked transaction and cashier-session references required by General Cashier; and keep the PMS owner-domain locks held until checkout commit or rollback.

It must not transfer folio, payment, deposit, refund, reversal, AR, or settlement ownership to Front Desk or General Cashier.

A transaction-bound attestation must prove not only PHP object identity, backend PID, and outer transaction ID, but also that the owner-domain locks represented by the attestation were not released by savepoint rollback. The required PostgreSQL transaction-local capability pattern uses `SELECT set_config('ivorq.glf_e_attestation_capability', <server-generated-secret>, true)` — a server-controlled, cryptographically random token installed with transaction-local scope after all PMS locks and terminal evaluation have succeeded. Only the SHA-256 hash is retained in the issuance WeakMap. Validation resolves `pg_backend_pid()`, `txid_current()`, and `current_setting('ivorq.glf_e_attestation_capability', true)` in a single query and verifies with `hash_equals`. Savepoint rollback restores the previous capability state, invalidating any attestation issued inside the rolled-back savepoint.

### General Cashier Attestation

**GC-A2 activation note (2026-07-20):** GC-A2 is authorized only to implement the General Cashier-owned execution-time terminal obligation attestation. It must consume an exact, transaction-bound GLF-E attestation. It must independently re-resolve Property, stay, reservation, and approved cashier-session relationships from minimized PMS references. It may lock only General Cashier-owned rows after the PMS lock step. It must fail closed when required PMS cash-linked references are missing or invalid. It must return minimized transaction-bound value evidence. It must perform zero PMS and Front Desk mutation. It does not authorize checkout execution.

The General Cashier-owned execution-time terminal obligation attestation must consume the PMS terminal financial attestation before participating. It must independently re-resolve the property, stay, reservation, and approved cashier-session relationships from the minimized PMS references; lock only General Cashier-owned cashier-session and accountability rows; determine unresolved cashier custody, handover, count, close, reconciliation, and accountability obligations; and return only the minimized status and fingerprint required for checkout evidence.

General Cashier must perform no PMS mutation, must not independently re-own or recalculate PMS payment, allocation, deposit, refund, reversal, AR, folio, or settlement lifecycle facts, and must not acquire PMS rows outside the PMS owner-domain lock step. If PMS attestation lacks the approved cash-linked transaction or cashier-session linkage evidence required for General Cashier evaluation, General Cashier must fail closed.

When General Cashier needs additional PMS-owned facts, the PMS attestation contract must be extended in a later approved package. General Cashier must not bypass ownership boundaries by creating a second financial source of truth.

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
2. Authorize execute permission before stay lookup.
3. Resolve same-property stay.
4. Return non-disclosing 404 where applicable.
5. Perform pre-transaction confirmation validation.
6. Enter the controlled PostgreSQL transaction.
7. Acquire Property, Business Date, stay, idempotency, Night Audit, PMS, and General Cashier locks in the approved order.
8. Obtain and validate all execution-time attestations.
9. Revalidate the same confirmation identity immediately before mutation.
10. Lock or atomically claim the confirmation identity as unconsumed and bind it to this property, stay, and idempotency identity.
11. Persist immutable checkout evidence, including the confirmation identity or safe fingerprint.
12. Persist the terminal stay transition.
13. Persist the transactional Housekeeping handoff/outbox record.
14. Commit the transaction.
15. Perform idempotent session confirmation cleanup after commit.

The pre-transaction confirmation validation prevents unnecessary lock acquisition for stale or unauthorized confirmation attempts. The final validation occurs after lock waits and immediately before the first persistent checkout mutation. If the confirmation expires while waiting for locks or attestations, the transaction must roll back and fail closed. No checkout evidence, terminal stay transition, or handoff may be persisted when final confirmation validation fails. Broad administrators require explicit operational assignment or break-glass policy, not implicit checkout authority.

Current session-based `SensitiveActionConfirmation` invalidation does not prove atomic one-time successful-use consumption with the PostgreSQL checkout commit. ADR-089 therefore must not claim that the current session `invalidate()` behavior alone guarantees durable atomic consumption for successful checkout execution.

The runtime prerequisite remains frozen inside the Checkout Sensitive Action Confirmation intent and execute permission package as:

```text
CHECKOUT_CONFIRMATION_ONE_TIME_CONSUMPTION_REQUIRED
```

Durable confirmation consumption and session confirmation cleanup are separate concepts.

Durable confirmation consumption must occur inside the same PostgreSQL checkout transaction. It happens after all approved global and owner-domain locks are acquired, after execution-time attestations are obtained, after final confirmation expiry and binding validation, before or atomically with the first persistent checkout mutation, and before transaction commit. The future checkout Sensitive Action Confirmation package must introduce an approved server-generated confirmation identity or nonce and a durable consumption contract. No password may be persisted.

The durable consumption contract must lock or atomically claim the authoritative confirmation identity; verify actor, company, property, intent, session, expiry, and unconsumed state; bind consumption to the checkout `property_id`, stay identity, and idempotency identity; prevent one confirmation identity from authorizing two successful checkout mutations; remain effective after response loss, process termination, or failed session cleanup; roll back the consumption claim when the checkout transaction rolls back; and commit the consumption claim together with immutable checkout evidence, terminal stay transition, and transactional handoff.

Database-enforced duplicate protection is required through a unique successful checkout confirmation identity, a unique safe confirmation fingerprint on successful checkout evidence, or both. The exact schema remains runtime-package scope. Immutable checkout evidence must record the approved confirmation identity or safe fingerprint, confirmation time, and expiry.

Post-commit session invalidation is cleanup only, not durable consumption, and is non-authoritative for successful-use replay defense. Session cleanup may occur after successful commit, but failure of session cleanup must not make the consumed confirmation reusable. Session cleanup must not reopen or roll back a committed checkout, and retrying cleanup must be idempotent.

A same-idempotency replay of an already committed checkout requires and consumes no new confirmation because it returns immutable committed checkout evidence and performs no new mutation. A new checkout attempt, changed stay, changed idempotency identity, or uncommitted retry requires a fresh unconsumed confirmation. An uncommitted retry after rollback may reuse the same confirmation only when the durable consumption claim also rolled back and the confirmation remains unexpired; when it expired after rollback, fresh confirmation is required. A confirmation bound to one checkout idempotency identity must not authorize a different identity.

## Failure Recovery

Required semantics:

- validation failure before transaction: fail closed, no success evidence;
- authorization failure: controlled failure before stay lookup;
- sensitive confirmation failure: fail or roll back before mutation;
- durable confirmation claim or consumption failure because the identity is already consumed: rollback, persist no checkout success, persist no terminal stay transition, persist no handoff, and return a controlled replay/conflict result;
- lock timeout: rollback and fail closed;
- confirmation expiry while waiting for locks or attestations: rollback and fail closed;
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
2. Shared checkout transaction-participation and Property/Business Date/Night Audit concurrency foundation — NA-A2 implemented and merged.
3. PMS terminal financial attestation — GLF-E implemented and merged.
4. GLF-E savepoint lock-continuity correction — GLF-E-S1 accepted and merged at `f91621b58fe5743ed2a60980a70475cae40331bc`.
5. General Cashier terminal obligation attestation — GC-A2 accepted and merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`.
6. Front Desk terminal checkout state and immutable checkout execution evidence — FD-C1 current authorized runtime package.
7. Transactional Housekeeping handoff/outbox — locked.
8. Checkout sensitive confirmation and execute permission — locked.
9. Final checkout command and interaction layer — locked.

GC-A2 is no longer the current package; FD-C1 is now the current authorized runtime prerequisite. FD-C1 does not authorize checkout execution. Full access does not authorize skipping later packages.

The final checkout command remains last. Runtime package codes are not assigned by this ADR.

## FD-C1 Activation Note (2026-07-23)

FD-C1 is now the current authorized bounded runtime prerequisite. It is a Front Desk-owned foundation package only and is constrained to:

- Front Desk-owned terminal checkout/departure stay-state foundation;
- Front Desk-owned immutable checkout execution evidence foundation;
- additive schema only when later runtime implementation is authorized;
- application-level and PostgreSQL update/delete immutability requirements;
- property-scoped idempotency identity design;
- one successful terminal outcome per stay design;
- server-owned actor and timestamps;
- references/statuses/fingerprints only;
- no raw guest PII snapshot;
- no raw financial snapshot;
- no foreign-domain table mutation;
- no PMS, Cashier, Business Date, Night Audit, Housekeeping, Engineering, Accounting, GL, AR, tax, or revenue mutation;
- no Housekeeping handoff/outbox;
- no sensitive confirmation intent;
- no execute permission;
- no route/controller/UI execution action;
- no final checkout orchestration;
- no can_execute=true;
- checkout unauthorized.

The runtime implementation package must source-review and select one repository-consistent terminal stay enum name rather than inventing multiple overlapping terminal states. This governance package does not authorize an enum name unless repository evidence unambiguously proves one existing standard.

The final checkout command remains unauthorized.

## Validation Notes

Source inspection for this ADR verified:

- ADR-089 number was free before creation.
- `origin/ivorq-enterprise-core` was `fbb289abf4bbfeb2f3ae801e05e98619a61f7814`.
- same Laravel/PostgreSQL topology is source-proven.
- GLF-D and GC-A1 are read-only top-level projections and not participating execution attestations.
- confirmation validation is dual-phase: pre-transaction and final immediately before mutation.
- durable one-time confirmation consumption remains a frozen runtime prerequisite, occurs inside the future PostgreSQL checkout transaction, and is not implemented by this ADR.
- post-commit session invalidation is cleanup only and is not durable confirmation consumption.
- successful checkout evidence must include the confirmation identity or safe fingerprint with database-enforced duplicate reuse protection.
- PMS Guest Ledger / PMS Cashiering locks precede General Cashier locks and remain held while General Cashier validates.
- Night Audit start locks `properties`, then `property_business_dates`, then active Night Audit run scope.
- `can_execute=false` remains canonical in current Front Desk runtime.
- no checkout execution write route exists.
- no `frontdesk.checkout-execution.execute` permission exists.
- no `frontdesk-checkout-execution` sensitive intent exists.
- no Front Desk checkout terminal enum exists.
- no runtime checkout execution evidence table exists.

No runtime source is changed by this ADR.
