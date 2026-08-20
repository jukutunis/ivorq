# CC-P01 Deferred Cost Delivery Runtime Plan

## Package identity and decision

- Package: `CC-P01_COSTCONTROL_DEFERRED_DELIVERY_RUNTIME_PLANNING`
- Package type: `PLANNING_ONLY / NO_RUNTIME`
- Canonical branch: `ivorq-enterprise-core`
- Exact canonical predecessor: `6e69fb4474a639c7ce06b4dec9145dee1282d0a5`
- Execution Contract: `1.22 UNCHANGED`
- Accepted governance predecessor: `INV-G1_INVENTORY_AUTHORITY_AND_COST_DELIVERY_MODE_GOVERNANCE_FREEZE`
- Accepted INV-G1 PR: `#59`
- Accepted INV-G1 feature HEAD: `358307704852309144812a2d05eb022a4e75268c`
- Accepted INV-G1 canonical merge: `6e69fb4474a639c7ce06b4dec9145dee1282d0a5`
- Runtime authorization: `NONE`
- Deferred consumer activation: `NONE`
- Package 21 activation: `NONE`

`CC_P01_IMPLEMENTATION_PLAN_COMPLETE`

- Independent review verdict received: `CC_P01_INDEPENDENT_REVIEW_CHANGES_REQUIRED`
- Planning correction scope: virgin-sequence semantics, existing-enrollment ownership bootstrap, ADR-043 GL authority proof, and cross-cutover existing-source idempotency
- Correction runtime authorization: `NONE`

This document translates accepted CC-G1, INV-R1, and INV-G1 constraints into an implementation-ready design. It does not implement, register, dispatch, schedule, or activate deferred delivery. Each implementation slice below requires separate Owner authorization and independent review.

## Immutable planning constraints

The implementation must preserve these governing facts:

- `InventoryTransaction` remains the sole cost-delivery source authority.
- `SYNCHRONOUS_TRANSITIONAL_ACTIVE` remains the active mode until a controlled cutover commits.
- Deferred delivery remains inactive until all slices and the pilot proof are accepted.
- Delivery-mode ownership applies to a complete Property + Item enrollment group.
- Valuation sequence and serialization remain Property + Location + Item.
- One source transaction may produce exactly one durable Cost Ledger effect through exactly one delivery mode.
- Cutover is permitted only at a Financial Period boundary, never mid-period.
- Cutover requires quiescence, no relevant producer documents in flight, complete historical disposition, no ambiguous controlled-delivery state, and mode-safe reversal.
- Initial activation is limited to one explicitly authorized pilot Property.
- No new direct Operations/Inventory or Receiving dependency on CostControl implementation classes is permitted.
- `InventoryTransaction` and `InventoryStockMovement` remain parallel bounded workflow universes. No unified-ledger claim, dual write, auto-merge, or monetary promotion of `InventoryStockMovement` is permitted.

## Bounded source findings

The planning inspection was limited to the Owner-named Inventory, Receiving, Foundation Outbox, CostControl, enrollment, General Ledger period dependency, ADR, and governance sources.

1. `InventoryPostingControlCoordinator` already locks Business Date and Financial Period context, locks `InventoryStock`, allocates the Property + Location + Item valuation sequence, appends immutable `InventoryTransaction`, creates a pending minimal-payload outbox record, and updates the mutable transaction-universe stock projection atomically.
2. Current enrolled Receipt, Issue, Adjustment, Transfer, and Receiving paths call CostControl invocation implementations synchronously. This is the frozen existing boundary exception and may not expand.
3. Current reversal creates a new opposite-signed `InventoryTransaction` and valuation sequence, applies CostControl synchronously, and creates no outbox record.
4. `CostAuthorityEnrollmentGroup` and its snapshots durably establish complete Property + Item enrollment and the canonical location scopes. Current preflight does not prove period-boundary cutover or quiescence.
5. `CostAvcoStateRepository` supplies PostgreSQL row locking and strict sequence state transitions. `CostLedgerEntry` is immutable, but the current database does not uniquely constrain `source_inventory_transaction_id`; current mode-specific idempotency keys therefore cannot alone prevent cross-mode double application.
6. Foundation outbox records have only `pending`, `delivered`, and `failed`; message state cannot express historical CostControl disposition or distinguish sequence blocking.
7. `PairedTransferValuationService` is consumer-shaped but uncomposed. It bootstraps state, uses a separate pair lifecycle and idempotency convention, and can leave business decisions pending. It is not safe to activate as the generic deferred boundary.
8. `FinancialPeriod` is calendar year/month scoped and supports Open, Closing, Closed, and Reopened. The general `PeriodControlService::isOpen()` auto-creates missing periods; cutover must not use that method because absence must fail closed.
9. Producer document terminal states are source-specific. The cutover query must treat Receipt `draft`, Issue `draft`, Adjustment `draft|submitted`, Transfer `draft|submitted`, and Receiving `draft|submitted` as in flight for a matching Property + Item. Approved Adjustment and Receiving records and completed/posted records are terminal only when their controlled posting evidence exists.
10. Goods Receipt records in the separate `InventoryStockMovement`-only universe are not Cost Ledger delivery candidates and must not be pulled into this plan's transaction-universe quiescence claim.
11. Enrollment baseline seeding intentionally writes `CostAvcoState.last_valuation_sequence = NULL`: no CostControl transaction has been applied, no N is fabricated, and no Inventory allocator row is auto-created. Separately, `InventoryValuationSequenceRepository` creates its control row at zero and allocates sequence 1 first; `AvcoValuationEngine` also expects sequence 1 when prior state is null.
12. Existing enrolled groups predate delivery-mode ownership persistence. Schema migrations must not insert their business ownership records, so a controlled bootstrap boundary is required before enrolled source stamping becomes mandatory.
13. `InventoryPostingControlCoordinator` resolves and equivalence-checks an existing source by Property + idempotency key before Business Date, stock, or sequence allocation. That immutable existing source must remain authoritative across a later mode cutover.
14. `VariancePostingEngine` fails closed before creating an InventoryTransaction-based legacy variance candidate for an enrolled Property + Item, while `CostIssuePostingEngine` uses `CostLedgerEntry` as the enrolled issue accounting source. ADR-043 requires these and every other applicable supported GL path to be proven before pilot activation.

## Recommended runtime architecture

The recommended design has four cooperating boundaries:

1. A CostControl-owned control plane durably owns pilot authorization, Property + Item delivery mode, activated cutover evidence, per-location sequence watermarks, cutover attempt outcomes, and outbox disposition/outcomes.
2. An Inventory-owned `CostDeliveryModePort` is implemented by a CostControl adapter. Operations code depends only on its own port and decision value object. The adapter locks the CostControl ownership row and returns an immutable posting decision. This changes the existing direct coupling into the permitted application-port composition direction: CostControl implements an Inventory contract.
3. Every newly controlled `InventoryTransaction` is stamped atomically with the resolved mode and cutover provenance. Historical rows remain null and are handled only through explicit disposition; no historical source evidence is fabricated.
4. A CostControl-owned consumer resolves the minimal outbox source identifier, proves eligibility, and applies Cost Ledger/state/outbox effects in one PostgreSQL transaction. A source-transaction unique constraint is the final database barrier against cross-mode double application.

The ownership row is the serialization latch for posting and cutover. A posting that locks synchronous ownership first completes synchronously and is included in the cutover watermark. A cutover that locks first changes ownership to deferred before a later posting can allocate its sequence. No enrolled source can be created with undefined or overlapping ownership.

## A. Durable delivery-mode persistence

Create CostControl-owned `cost_delivery_mode_ownerships`, one row per enrolled Property + Item group.

- Identity: `id`, `property_id`, `item_id`, `enrollment_group_id`.
- Controlled state: `delivery_mode` (`SYNCHRONOUS`, `DEFERRED`), `ownership_version`, `activated_cutover_id`.
- Provenance: `established_by`, `established_at`, `changed_by`, `changed_at`.
- Initial row is always `SYNCHRONOUS`, is created only for an already enrolled complete group, and has version 1.
- The only permitted update is `SYNCHRONOUS -> DEFERRED`, performed in the cutover transaction with `ownership_version + 1` and a matching activated cutover ID.
- `DEFERRED` is terminal in CC-P01. There is no automatic or manual fallback to synchronous.
- Property, item, enrollment group, and established provenance are immutable. Delete is prohibited by model guard and PostgreSQL trigger.

Create immutable `cost_delivery_pilot_properties` with singleton `pilot_slot = 1`. It records the only Property eligible for the first deferred activation plus Owner approval evidence. Expansion, replacement, or deletion requires a later separately authorized package.

At posting time, the Inventory-owned port returns one of:

- `NOT_ENROLLED`: CostAuthority enrollment is absent, so no CostControl delivery ownership exists and the source mode stamp is null;
- `SYNCHRONOUS`: enrolled, ownership locked, synchronous source stamp required;
- `DEFERRED`: enrolled, ownership/cutover/scope watermark locked, deferred source stamp required.

The prior `UNENROLLED` business description means `NOT_ENROLLED` only. Once ownership enforcement is installed, an enrolled group without an ownership row is `ENROLLED_DELIVERY_OWNERSHIP_MISSING` and fails closed. The adapter must distinguish these outcomes and must never downgrade missing ownership to `NOT_ENROLLED` or fabricate a row during posting.

Existing enrolled groups acquire initial ownership only through CostControl-owned `CostDeliveryModeOwnershipBootstrapService`. The service:

- accepts one exact `enrollment_group_id` and requires an active outer transaction;
- locks the enrollment group and requires status `ENROLLED`;
- verifies Property + Item identity and complete immutable canonical scope snapshots;
- checks whether ownership exists, then creates exactly one `SYNCHRONOUS`, version 1 ownership with null activated cutover and actor/provenance when absent;
- returns idempotent success only for an exactly equivalent existing ownership;
- fails closed on mismatched ownership, incomplete snapshots, `DRAFT`, `APPROVED`, `REJECTED`, `SUPERSEDED`, any other non-enrolled lifecycle state, or concurrent conflicting evidence; and
- never creates enrollment, changes AVCO authority, creates pilot authorization, stamps a source, or activates deferred delivery.

After ownership enforcement exists, future enrollment has an atomic authority invariant: a new enrollment may become authority-active only if its initial `SYNCHRONOUS` ownership record is created in the same activation transaction. If that cannot be done, enrollment activation fails closed and the group remains unactivated. CC-P01 planning does not modify current enrollment runtime; the implementing slice must integrate this invariant before enabling future enrollment activation.

Rejected alternatives:

- A configuration flag or environment variable is rejected because it is not Property-scoped, transactionally locked, or auditable.
- A location-level mode flag is rejected because it permits mixed authority within a complete Property + Item group.
- Inferring mode from outbox status or Cost Ledger existence is rejected because both are post-source effects and cannot serialize posting against cutover.
- Adding a direct CostControl service call to each Operations service is rejected because it expands the frozen dependency exception.

## B. Cutover and sequence-watermark persistence

Create immutable `cost_delivery_cutovers` as activated cutover evidence and immutable `cost_delivery_cutover_scopes` as its complete location set.

For each enrollment snapshot, the scope row records:

- `last_synchronously_owned_sequence = N`;
- `first_deferred_owned_sequence = N + 1`;
- Property, location, item, canonical valuation scope, enrollment snapshot, and source sequence-row identity.

The cutover service locks all group sequence control rows in ascending canonical `valuation_scope` order and reconciles each with its locked seeded `CostAvcoState`. A check constraint requires `first_deferred_owned_sequence = last_synchronously_owned_sequence + 1`. A deferred constraint trigger verifies at commit that the scope rows exactly equal the group's enrollment snapshots, the ownership points to that cutover, and no scope is missing or duplicated.

Virgin scope classification is explicit:

`NO_PRIOR_APPLIED_VALUATION_SEQUENCE`

A scope is virgin only when `CostAvcoState.last_valuation_sequence IS NULL` and the Inventory allocator row is absent, or exists with `last_sequence = 0`, and no `InventoryTransaction` for the scope has valuation sequence greater than zero. Cutover may then persist:

- `LAST_SYNCHRONOUSLY_OWNED_SEQUENCE = 0`;
- `FIRST_DEFERRED_OWNED_SEQUENCE = 1`.

Zero is a cutover-boundary sentinel only. It is not a historical `InventoryTransaction`, applied AVCO sequence, enrollment baseline transaction, Cost Ledger sequence, or value to write into `CostAvcoState.last_valuation_sequence`. The AVCO state remains null until sequence 1 is successfully applied. When serialization needs a row, cutover may use the existing Inventory-owned insert-if-absent then `FOR UPDATE` convention to create/lock an allocator control row at zero; that control row does not fabricate historical evidence.

For a non-virgin scope, Inventory allocator `last_sequence > 0` must equal `CostAvcoState.last_valuation_sequence` exactly. Any mismatch fails with `CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE`. This includes allocator 5/state 4, allocator 5/state null, and allocator absent-or-zero/state 5. Null AVCO sequence is valid only for a proven virgin scope.

Rejected alternatives:

- One group-level watermark is rejected because ordering is per Property + Location + Item.
- A timestamp, ULID, outbox ID, or queue position is rejected because ADR-042 requires the Inventory valuation sequence.
- Calculating the watermark dynamically during delivery is rejected because it permits ownership drift and cannot prove the cutover boundary.
- Updating enrollment snapshots is rejected because opening/enrollment evidence is already immutable and has a different lifecycle purpose.
- Coercing or persisting a null AVCO sequence as zero is rejected because it would fabricate an applied valuation baseline.

## C. Historical outbox disposition and processing outcomes

Create CostControl-owned `cost_delivery_outbox_dispositions`. It stores one immutable classification per relevant outbox/source pair and a controlled processing lifecycle.

Classifications are exactly:

- `SYNCHRONOUSLY_SATISFIED_HISTORY`: exact equivalent Cost Ledger effect already exists; `MUST_NOT_APPLY_DEFERRED`.
- `UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY`: source was outside accepted CostControl delivery eligibility; `MUST_NOT_APPLY_DEFERRED`.
- `DEFERRED_OWNED_AFTER_CUTOVER`: source carries a valid deferred stamp and is at or after its scope watermark; it may be processed only through the deferred consumer.

Classification is immutable. Historical classification must resolve `InventoryTransaction` and, for synchronous satisfaction, exact Cost Ledger equivalence. An enrolled historical row with missing or mismatched Cost Ledger evidence remains unclassified and blocks cutover; it must not be silently labeled excluded. Historical rows remain unchanged in Foundation Outbox. INV-G1 explicitly permits a pending/failed message durably classified as one of the two historical exclusions to cease being ambiguous; changing it to delivered would falsely claim deferred delivery.

Controlled processing states are:

- `HISTORICAL_EXCLUDED` for the two historical classes;
- `PENDING` for newly classified deferred work;
- `DELIVERED` terminal;
- `FAILED` with safe code and recoverability flag;
- `BLOCKED_SEQUENCE` with the unresolved expected sequence.

`attempt_count`, `last_attempted_at`, `last_failure_code`, `expected_sequence`, and terminal timestamps are lifecycle fields. No scheduler or automatic retry reads them in CC-P01.

Rejected alternatives:

- Reusing Foundation Outbox status alone is rejected because it cannot prove historical mode ownership.
- Marking all pre-cutover messages delivered is rejected because it fabricates consumer delivery history.
- Treating every pending row as deferred is rejected because it would double-apply synchronously satisfied history.
- Updating the minimal outbox payload is rejected by ADR-041.

## D. CostControl-owned deferred eligibility boundary

`DeferredCostDeliveryEligibilityService` returns either an immutable eligible context or a typed fail-closed outcome. It must verify and, inside apply, reverify:

1. outbox topic is exactly `inventory.transaction.posted` and payload contains only the matching source transaction identifier;
2. immutable `InventoryTransaction` exists and its outbox source identity matches;
3. Property is server-resolved and agrees across source, ownership, enrollment, watermark, disposition, state, and any existing Cost Ledger row;
4. the complete Property + Item enrollment group is still enrolled and every source location maps to its immutable canonical snapshot;
5. ownership is `DEFERRED`, source stamp is `DEFERRED`, source cutover/version matches the locked ownership, and the pilot Property matches;
6. valuation scope equals `property:{property}:location:{location}:item:{item}` and source sequence is greater than or equal to `first_deferred_owned_sequence`;
7. disposition is exactly `DEFERRED_OWNED_AFTER_CUTOVER` and not terminal or historically excluded;
8. source Business Date row is open and source Financial Period row is Open or Reopened when delivery is attempted; closed/missing context fails closed without remapping the period;
9. no Cost Ledger row exists for the source, or the existing row is exactly equivalent;
10. transaction type is supported by its registered handler: receipt, issue, adjustment in/out, transfer out/in pair, return where the existing planner proves it, or reversal. `opening_balance` remains unsupported;
11. for every affected scope, expected source sequence is 1 when `CostAvcoState.last_valuation_sequence IS NULL`; otherwise it is `last_valuation_sequence + 1`. Null is never coerced or persisted as zero; and
12. negative inventory, unsupported FX, missing approval evidence, and unsupported correction semantics remain fail-closed under existing rules.

Eligibility never mutates Inventory, enrollment, period, Business Date, or source evidence. It does not infer facts from `InventoryStock`, `InventoryStockMovement`, current item WAC, timestamps, or queue order.

Rejected alternatives:

- A generic event listener that trusts topic and pending state is rejected as under-specified and unsafe.
- Eligibility at enqueue time only is rejected because period and state eligibility must be revalidated under apply locks.
- Reconstructing historical eligibility from mutable current configuration is rejected because it fabricates source-time evidence.

## E. Cross-mode source equivalence and idempotency

Add a PostgreSQL unique constraint on `cost_ledger_entries.source_inventory_transaction_id`. Before creation, the migration performs a grouped duplicate audit and fails without modifying data if any duplicate source exists. No automatic cleanup is authorized.

`CostLedgerRepository::findBySourceInventoryTransactionId()` and `CostLedgerAppendService` must use structured equivalence. At minimum compare:

- Property and source `InventoryTransaction` identity;
- valuation scope and sequence;
- entry type;
- exact quantity delta, unit cost, and value delta using `AvcoDecimal`;
- currency, Business Date, and occurred-at evidence;
- prior/reversal provenance where applicable; and
- ADR-042 fields `idempotency_key` and `entry_sequence`.

An exact equivalent row is idempotent success and creates no second entry. Any mismatch is a permanent integrity failure. Database conflict exception text is never parsed.

Producer idempotency across cutover follows:

`IMMUTABLE_SOURCE_MODE_STAMP_IS_AUTHORITATIVE_FOR_EXISTING_SOURCE`

When the coordinator finds an exact equivalent existing `InventoryTransaction`, it returns that immutable source under its original stamp before consulting current delivery ownership for a new source. It must never restamp the row, allocate another sequence, create another source/outbox record, or create another Cost Ledger effect. A source stamped synchronous before cutover remains synchronously satisfied after group ownership becomes deferred and cannot enter deferred eligibility. A historical null-stamped source remains governed by its immutable historical disposition. A non-equivalent request using the same source idempotency identity fails closed as an idempotency collision.

Rejected alternatives:

- Mode-prefixed idempotency keys are rejected because synchronous and deferred keys can differ for the same source.
- A consumer-only claim table without Cost Ledger uniqueness is rejected because another write boundary could bypass it.
- Silent acceptance of a source-ID match without full field equivalence is rejected because it can conceal corruption.
- Re-resolving an existing source's mode from current ownership is rejected because it would rewrite immutable posting-time ownership and permit cross-mode duplication.

## F. Deferred consumer and handler boundary

Create `DeferredCostDeliveryConsumer::consume(string $outboxMessageId)`. It is CostControl-owned and imports Inventory/Foundation read models or repositories in the permitted direction. Inventory and Receiving do not import the consumer.

The consumer performs an immutable pre-read only to identify the Property + Item lock key, then enters the apply transaction and re-resolves all facts. It dispatches to CostControl-owned handlers:

- `DeferredSingleTransactionValuationHandler` for receipt, issue, adjustment, return when supported, and reversal;
- `DeferredTransferValuationHandler` for the paired transfer legs.

Transfer processing resolves both immutable source legs by Property, source document, source line, and opposite type. It locks both dispositions/outbox rows and both AVCO states in canonical ascending order; both sequences must independently be next for their scopes. Both Cost Ledger entries, both state transitions, and both outbox delivered transitions commit together. Missing or mismatched partner evidence is a durable failure, not a silent pending result.

`PairedTransferValuationService` must not be registered or activated. Its useful source-proven pairing assertions may be moved into the new handler, but its `bootstrapAndLock` state behavior, silent return paths, separate mode-specific keys, and direct repository append path must not survive. After replacement tests pass, remove the service or leave an explicit throwing compatibility shell until all internal references are proven absent; deletion is preferred in the implementing slice.

No queue worker, listener, scheduler, `afterCommit` dispatcher, automatic retry loop, or replay command is part of the first implementation train. Tests and an explicitly invoked application service prove the consumer; production transport activation is a later Owner decision.

Rejected alternatives:

- Activating `PairedTransferValuationService` unchanged is rejected for the reasons above.
- One listener per source document is rejected because eligibility and ordering must be centralized around `InventoryTransaction`.
- Inventory-owned consumer composition is rejected because it reverses ADR-041 dependency direction.

## G. Atomic apply and global lock order

Success processing uses one outer PostgreSQL transaction. The canonical lock order for all new or modified paths is:

1. pilot Property row when cutover is involved;
2. delivery-mode ownership rows ordered by `property_id, item_id`;
3. activated cutover and scope-watermark rows ordered by canonical valuation scope;
4. relevant Inventory valuation-sequence rows ordered by Property, location, item when posting/cutover needs them;
5. outbox and disposition rows ordered by outbox ULID for consumer work;
6. `CostAvcoState` rows ordered by canonical valuation scope;
7. existing Cost Ledger source row/equivalence check and append.

Existing stock and business-context locks remain inside the producer transaction after ownership acquisition. Multi-line producer services must acquire all ownership rows in Property + Item order before current CostAvcoState locks. No new code may acquire ownership after locking AVCO state.

The consumer success transaction performs:

`ownership/watermark lock -> outbox/disposition lock -> source and accounting-context revalidation -> CostAvcoState lock -> strict-sequence check -> plan -> Cost Ledger append/equivalence -> CostAvcoState update -> disposition DELIVERED -> Outbox delivered`.

Any exception rolls back the entire success transaction. Therefore a crash after append but before delivered rolls back the append and state change too. A caught completed business/infrastructure failure is recorded afterward in a separate short failure transaction only after proving no Cost Ledger/state advancement. A process interruption before that second transaction leaves outbox/disposition pending, exactly as ADR-042 permits for incomplete execution.

Rejected alternatives:

- Appending Cost Ledger and marking delivered in separate transactions is rejected because it creates partial financial state.
- Locking only the outbox row is rejected because it does not serialize AVCO scope order or cutover.
- Automatic retry after failure is rejected because strict sequence requires controlled recovery.

## H. Mode-safe reversal

Select deferred reversal source/outbox participation under the same posting boundary.

`InventoryReversalPostingService` must acquire the Property + Item ownership lock before sequence/state locks. The new reversal `InventoryTransaction` carries its own resolved mode and cutover provenance, receives its own sequence, and creates the same minimal outbox record atomically.

- In synchronous mode, the Inventory-owned valuation port delegates to the existing CostControl reversal planner/apply boundary in the same outer transaction.
- In deferred mode, synchronous apply is prohibited; the outbox/disposition is `DEFERRED_OWNED_AFTER_CUTOVER`, and the single-transaction handler applies the reversal in strict sequence.
- The reversal retains original immutable source linkage and current approved Business Date/Financial Period behavior. It does not rewrite the original period or introduce generic correction/reopen behavior.

This is safer than a separate reversal-only mechanism because every valuation-sequence-producing source uses the same ownership lock, source stamp, outbox producer, source uniqueness barrier, eligibility service, and strict sequence rule.

Rejected alternatives:

- Keeping synchronous reversal after cutover is rejected because it bypasses deferred ownership.
- Reversing the original Cost Ledger entry in place is rejected because source and ledger evidence are append-only.
- A generic correction/replay command is rejected as separately authorized future scope.

## I. Synchronous bypass and application-port composition

Create these Inventory-owned contracts/value objects:

- `CostDeliveryModePort`: locks and resolves document/posting ownership decisions.
- `SynchronousCostValuationPort`: invokes the existing workflow-specific synchronous valuation behavior without an Operations import of CostControl implementations.
- `CostDeliveryPostingDecision`: carries Property, item, mode, ownership ID/version, cutover ID, and allowed scope watermark.

CostControl supplies adapters and binds them in `CostControlServiceProvider`; this preserves the permitted compile-time direction `Finance/CostControl -> Operations/Inventory`.

Every transaction-producing service checks at the beginning of its outer transaction, before CostAvcoState or sequence allocation. The coordinator requires a matching decision for enrolled posting and stamps it into the new `InventoryTransaction` fields. Immediately before synchronous apply, the CostControl adapter revalidates that:

- the source stamp is `SYNCHRONOUS`;
- the locked decision ownership/version matches; and
- no activated cutover owns the sequence.

A deferred decision posts source/outbox but never calls the synchronous valuation port. The deferred consumer rejects a synchronous or null stamp. Holding the ownership row lock until producer commit makes bypass atomic with cutover.

The existing-source idempotency branch is intentionally earlier than new-source mode resolution. When an exact immutable source is found, its stored mode or accepted historical disposition governs the result even if current ownership has since changed. The current ownership lock and mandatory stamp apply only when a new source will be created. Exact retry after cutover therefore returns the original source without another sequence, outbox row, synchronous invocation, or deferred application; mismatched retry fails closed.

The existing direct CostControl imports/container resolutions in Receipt, Issue, Adjustment, Transfer, and Receiving integration are removed rather than copied. Legacy unenrolled behavior remains unchanged and never becomes deferred eligible.

Rejected alternatives:

- Checking mode after `InventoryTransaction` insert is rejected because a source could be stamped ambiguously.
- Checking only inside the consumer is rejected because synchronous apply could already have occurred.
- Feature-flag bypass is rejected because it is not transactionally aligned with the source sequence.

## J. Controlled cutover service and quiescence

Create `CostDeliveryCutoverService::activateGroup(CostDeliveryCutoverRequest $request)`. No UI, route, queue, or general-purpose command is required. The service is callable only by a later explicitly authorized activation boundary and requires actor, approver, Owner approval reference, pilot Property, enrollment group, target Financial Period, and target Business Date.

The service fails closed unless all checks pass:

1. `cost_delivery_pilot_properties` contains the same Property in singleton slot 1.
2. Target group is enrolled, ownership is synchronous, and its immutable snapshots form a complete Property + Item location set.
3. Target Financial Period exists; status is Open, not Reopened; year/month match target Business Date; target date is the first calendar day of that period; and the immediately prior calendar period exists and is Closed.
4. The authoritative Property Business Date exists, is open, and equals the target date.
5. No group `InventoryTransaction` exists in the target Financial Period or on/after the boundary before cutover. This prevents a first-day-but-after-posting switch from being mislabeled a boundary cutover.
6. No relevant transaction-universe producer document is in flight: Receipt draft, Issue draft, Adjustment draft/submitted, Transfer draft/submitted, or Receiving draft/submitted for the Property + Item. Terminal status without matching posting evidence fails closed as ambiguous.
7. Every producer document mutation/post path acquires the same ownership-row quiescence lock. This prevents a phantom in-flight document from appearing between the query and commit.
8. Every group outbox/source row before the candidate watermark has one immutable historical classification; any unclassified, deferred, mismatched, or ambiguous row blocks.
9. No deferred disposition for the group is Pending, Failed, or Blocked Sequence; historical exclusions are resolved and do not become deferred work.
10. Reversal source stamping/outbox and both synchronous/deferred reversal tests are installed and passing.
11. Cost Ledger source uniqueness/equivalence, source mode stamps, synchronous bypass, deferred handlers, and required database triggers are installed.
12. Each locked scope passes either the true-virgin proof (null AVCO sequence plus absent/zero allocator and no positive-sequence source) or exact non-virgin allocator/AVCO equality. True virgin receives only the 0/1 cutover sentinel; any divergence records `CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE`.
13. The accepted CC-P01G readiness record and Owner authorization include `GL_AUTHORITY_PATH_PROOF = PASS` for every applicable supported enrolled transaction path.

After validation, one transaction inserts immutable cutover/scope evidence, updates ownership to terminal deferred, and records an activated attempt. A deferred constraint trigger verifies completeness at commit. If validation fails, the activation transaction rolls back and a separate append-only `cost_delivery_cutover_attempts` row records `CUTOVER_BLOCKED` and a safe reason; no mode or watermark changes.

Financial Period rows are queried and locked directly by identity using fail-closed repository logic. `PeriodControlService::isOpen()` is prohibited because its auto-create behavior is unsuitable for cutover proof.

The GL proof is governance/readiness evidence, not a runtime code-introspection check and not dynamically stored test output. The cutover service consumes only the accepted Owner approval that is prohibited from being issued without a passing CC-P01G record. It does not inspect PHP source or rerun tests during cutover.

Rejected alternatives:

- A UI toggle is rejected because cutover is a controlled accounting operation, not routine configuration.
- Allowing Reopened as a cutover boundary is rejected because it is not the start of a new unposted period.
- Checking only that the period is open is rejected because that permits mid-period activation.
- Queue draining alone is rejected because current historical pending rows are not necessarily deferred work and document/source races still exist.

## K. Observability

Operator-visible read projections must combine, without mutating source ownership:

- Foundation Outbox transport state;
- CostControl disposition classification and processing state;
- expected versus actual valuation sequence;
- ownership mode/version and cutover ID;
- cutover-attempt outcome and safe reason.

Required visible states are mapped as follows:

| Required state | Durable source |
|---|---|
| Pending | deferred disposition `PENDING` plus outbox `pending` |
| Delivered | disposition `DELIVERED` plus outbox `delivered` |
| Failed | disposition `FAILED` plus outbox `failed`, safe code, recoverability |
| Blocked by sequence | disposition `BLOCKED_SEQUENCE`, expected sequence, outbox `failed` |
| Historical excluded | disposition `HISTORICAL_EXCLUDED` plus immutable historical classification |
| Cutover blocked | immutable cutover attempt `CUTOVER_BLOCKED` plus safe reason |

CC-P01 does not require an interaction-layer workspace. A read-only repository/projection and PostgreSQL tests are sufficient for the implementation train. A later UI must remain read-only unless separately authorized.

Rejected alternatives:

- Logs only are rejected because they are not durable business evidence.
- Adding CostControl-specific statuses to the shared Outbox enum is rejected because transport and domain disposition have different ownership and meaning.
- Exposing exception messages is rejected; store stable safe reason codes and separately controlled technical diagnostics.

## L. Recovery boundary

No broad recovery, retry, correction, or replay command is included. The consumer is idempotent when explicitly invoked, but Failed and Blocked Sequence are terminal for this package and block later sequences. Resolution requires a separately authorized recovery package that defines operator authorization, correction provenance, period handling, and replay scope.

An exact duplicate equivalence is not replay; it is the required idempotent completion path. A process-interrupted pending attempt may be invoked again only by the bounded implementation test/application boundary before production transport activation. No scheduler performs that invocation.

Rejected alternatives:

- Automatically retrying pending/failed messages is rejected by ADR-042.
- Folding a general replay Artisan command into pilot activation is rejected because it expands authority and period/correction semantics.

## Database design plan

No migration is created by this planning package. Expected migrations and controls are:

### `cost_delivery_pilot_properties`

- Purpose/owner: immutable CostControl authorization for the one initial pilot Property.
- Columns: `id`, `pilot_slot`, `property_id`, `owner_approval_reference`, `authorized_by`, `authorized_at`, `created_at`.
- Isolation: Property is explicit and server-resolved; all later cutovers must match it.
- Constraints: primary key ULID; unique/check `pilot_slot = 1`; unique Property; non-null approval evidence.
- FK policy: Property FK `RESTRICT`; actor IDs follow existing audit-reference policy.
- Locking: cutover locks singleton row first.
- Immutability/retention: PostgreSQL update/delete blocker and model guards; retained permanently as activation provenance.

### `cost_delivery_mode_ownerships`

- Purpose/owner: CostControl durable mode owner for complete Property + Item enrollment group.
- Columns: identity/provenance and controlled state listed in section A.
- Isolation: redundant Property/item must exactly match enrollment group; trigger enforces it.
- Constraints: unique `(property_id, item_id)`, unique `enrollment_group_id`, check mode values, check deferred requires cutover, check synchronous has null cutover.
- FK policy: enrollment and activated cutover are CostControl FKs `RESTRICT`; Property/item references are `RESTRICT` and never cascade.
- Locking: canonical serialization latch; `FOR UPDATE` before producer, cutover, reversal, and consumer state work.
- Lifecycle: controlled one-way trigger; no delete; deferred terminal.
- Bootstrap: migrations insert no rows. Existing enrolled groups receive version 1 synchronous ownership only through `CostDeliveryModeOwnershipBootstrapService`; uniqueness arbitrates concurrent bootstrap and exact equivalence is the only idempotent result.
- Audit: ownership version and actor/time retained permanently.

### `cost_delivery_cutovers`

- Purpose/owner: immutable activated Property + Item cutover evidence.
- Columns: `id`, ownership/group/Property/item, Financial Period ID, boundary Business Date, owner approval reference, requested/approved/activated identities and timestamps.
- Isolation: Property must match ownership, pilot, enrollment, period, and all scopes.
- Constraints: unique ownership and enrollment group; target period/date not null.
- FK policy: own ownership/group FKs `RESTRICT`; Financial Period FK `RESTRICT`; no cascade delete.
- Locking: created only while ownership and accounting context are locked.
- Immutability: insert-only model and PostgreSQL update/delete blocker.
- Retention: permanent accounting-cutover provenance.

### `cost_delivery_cutover_scopes`

- Purpose/owner: immutable location watermark evidence.
- Columns: cutover, enrollment snapshot, Property/location/item, canonical scope, Inventory sequence-row identity, last synchronous N, first deferred N+1.
- Isolation: all identities must match parent cutover and snapshot.
- Constraints: unique `(cutover_id, location_id)`, unique enrollment snapshot, unique canonical scope per cutover, check N >= 0 and first = last + 1. N = 0 is allowed only for a service-proven virgin scope and is a boundary sentinel, never an applied AVCO sequence.
- FK policy: cutover/snapshot FKs `RESTRICT`; external sequence ID is an opaque immutable reference verified by service to avoid CostControl controlling Inventory retention.
- Locking: source sequence rows locked in canonical scope order before inserts.
- Immutability: insert-only and update/delete trigger; deferred constraint trigger verifies complete snapshot coverage and ownership linkage at commit.
- AVCO integrity: cutover never writes `CostAvcoState.last_valuation_sequence`; null remains null until sequence 1 applies. Non-virgin allocator/state divergence blocks before insert.
- Retention: permanent; never recomputed or compacted.

### `cost_delivery_cutover_attempts`

- Purpose/owner: append-only observable cutover result, including blocked attempts.
- Columns: `id`, unique `request_id`, Property/item/group, target period/date, outcome (`ACTIVATED`, `CUTOVER_BLOCKED`), safe reason, cutover ID nullable, actor/approval/timestamps.
- Isolation: Property always explicit; successful reference must match cutover.
- Constraints: one row per request; activated requires cutover, blocked requires reason.
- FK policy: own successful cutover FK `RESTRICT`; external references non-cascading.
- Locking: success row in activation transaction; blocked row in a new transaction after activation rollback.
- Immutability/retention: append-only trigger; permanent operator evidence.

### `cost_delivery_outbox_dispositions`

- Purpose/owner: CostControl historical classification and deferred processing outcome.
- Columns: outbox/source identity, Property/location/item/scope/sequence, classification, processing state, ownership/cutover references, equivalent Cost Ledger entry, classification provenance, attempts/failure/expected sequence/timestamps.
- Isolation: redundant scope facts are immutable and must exactly match source on creation/revalidation.
- Constraints: unique `outbox_message_id`, unique `source_inventory_transaction_id`, classification/state check constraints, deferred classification requires ownership/cutover, historical classification requires historical-excluded state at creation.
- FK policy: CostControl ownership/cutover/ledger references `RESTRICT`; Foundation outbox and Inventory source IDs are opaque non-FK references verified read-only so CostControl does not govern their retention.
- Indexes: `(property_id, item_id, processing_state)`, `(property_id, valuation_scope, valuation_sequence)`, partial index for `PENDING|FAILED|BLOCKED_SEQUENCE`.
- Locking: row locked after ownership/watermark and with outbox rows in ULID order.
- Lifecycle: classification and source facts immutable; processing state controlled by trigger; Delivered and Historical Excluded terminal; no delete.
- Idempotency/retention: unique source/outbox, attempt count; permanent audit record even if shared outbox retention policy changes later.

### `inventory_transactions` source stamp extension

- Purpose/owner: Inventory-owned immutable posting-time proof of selected delivery mode.
- Columns: nullable `cost_delivery_mode`, `cost_delivery_ownership_id`, `cost_delivery_ownership_version`, `cost_delivery_cutover_id`.
- Historical policy: all existing rows remain null; no backfill. Historical disposition supplies their mode treatment.
- Constraints: mode null requires all provenance null; synchronous requires ownership/version and null cutover; deferred requires ownership/version/cutover. Mode values limited to `SYNCHRONOUS|DEFERRED`.
- FK policy: CostControl identifiers are opaque ULIDs without cross-owner FK; the posting port proves them while holding the owner row lock.
- Immutability: extend existing InventoryTransaction PostgreSQL immutability trigger and model fill/insert boundary; no update path.
- Existing-source rule: an exact idempotent retry returns the immutable original row and its original stamp/disposition before any new-source mode resolution; no restamp or second allocation/outbox/effect is permitted.
- Indexes: `(property_id, item_id, cost_delivery_mode, valuation_sequence)` and `cost_delivery_cutover_id`.
- Retention: same permanent source-evidence retention as InventoryTransaction.

### `cost_ledger_entries` source uniqueness

- Purpose/owner: CostControl final cross-mode double-apply barrier.
- Change: preflight audit followed by unique index/constraint on `source_inventory_transaction_id`.
- Isolation: existing restrictive source relation remains; structured equivalence verifies redundant fields.
- Failure behavior: migration aborts on any pre-existing duplicate; no repair, deletion, or winner selection.
- Locking/idempotency: unique index arbitrates concurrent append; service re-reads by source and compares exact equivalence.
- Retention: append-only behavior and existing mutation trigger remain unchanged.

## PostgreSQL concurrency proof plan

All proofs use `phpunit.pg.xml` against isolated PostgreSQL test databases or the repository-approved `ivorq_testing` protocol. SQLite evidence is unacceptable.

| Race/failure | Two-context setup | Required proof |
|---|---|---|
| Simultaneous cutover attempts | Two transactions request same ownership; first holds ownership row | Exactly one activated cutover/ownership transition; second blocks then records `CUTOVER_BLOCKED`; no duplicate scopes. |
| Simultaneous ownership bootstrap | Two transactions bootstrap the same enrolled group | Unique ownership arbitration yields exactly one version 1 synchronous row; the second returns exact-equivalent idempotent success and no mismatch is hidden. |
| Inventory posting vs cutover | Posting locks synchronous owner while cutover waits, then reverse ordering | Posting-first source is synchronous and included in N; cutover-first source waits then is deferred and gets N+1; no undefined stamp. |
| Same-message consumers | Two consumers pre-read same outbox then contend | One append/state advance/delivery only; second returns exact-equivalent delivered success. |
| Same-scope messages | Two distinct rows for one scope | CostAvcoState row serializes them; only next sequence advances. |
| N and N+1 concurrent | Hold N before state update while N+1 attempts | N+1 becomes `BLOCKED_SEQUENCE`/failed without append or state advance; no silent skip. |
| Crash after Cost Ledger append | Inject exception after append inside outer transaction | Ledger insert, AVCO update, disposition, and outbox delivery all roll back; source remains pending. |
| Crash before Outbox delivered | Inject exception immediately before delivered transition | Same full rollback; no applied-but-pending split state. |
| Synchronous invocation vs ownership change | Pause synchronous producer after owner lock; attempt cutover | Cutover waits; synchronous apply commits before watermark. When cutover owns first, synchronous adapter rejects deferred stamp. |
| Reversal vs consumer | Reversal and consumer contend on same group/scope | Ownership and AVCO lock order prevents deadlock; each exact sequence follows its stamped mode; no synchronous reversal after deferred cutover. |
| Transfer pair consumers | Trigger each leg concurrently | Both outbox rows/states are locked canonically; exactly one atomic paired apply; no one-leg delivery. |
| Cross-Property attempts | Same item/location-shaped IDs in two Properties | Locks and reads remain Property-scoped; no cross-Property owner, watermark, disposition, or ledger match. |
| Document creation vs cutover | Hold ownership lock in line mutation or cutover | A pre-cutover draft is detected and blocks; a post-cutover draft waits and is created only after deferred ownership commits. |
| Virgin first sequence | Cut over a seeded null-state scope with absent/zero allocator, then contend source sequences 1 and 2 | Watermark is 0/1 without AVCO mutation; sequence 1 is eligible, while sequence 2 cannot apply before 1. |
| Existing-source retry after cutover | Create and synchronously value source T, pause exact retry while ownership changes to deferred | Retry returns T unchanged; no sequence, source, outbox, ledger, or deferred effect is added. A non-equivalent retry fails collision. |

Deadlock tests must use bounded lock/statement timeouts, separate PostgreSQL connections/processes, synchronization barriers, deterministic final-state assertions, and teardown that terminates test connections before database removal.

## Focused PostgreSQL test inventory

Expected new classes:

- `tests/Postgres/Finance/CostControl/CostDeliveryModeOwnershipPersistenceTest.php`
- `tests/Postgres/Finance/CostControl/CostDeliveryModeOwnershipBootstrapTest.php`
- `tests/Postgres/Finance/CostControl/CostDeliveryCutoverPersistenceTest.php`
- `tests/Postgres/Finance/CostControl/CostDeliveryHistoricalDispositionTest.php`
- `tests/Postgres/Finance/CostControl/DeferredCostDeliveryEligibilityTest.php`
- `tests/Postgres/Finance/CostControl/CostLedgerSourceEquivalenceTest.php`
- `tests/Postgres/Finance/CostControl/DeferredCostDeliveryConsumerTest.php`
- `tests/Postgres/Finance/CostControl/DeferredTransferDeliveryTest.php`
- `tests/Postgres/Finance/CostControl/CostDeliveryCutoverServiceTest.php`
- `tests/Postgres/Finance/CostControl/CostDeliveryConcurrencyProofTest.php`
- `tests/Postgres/Operations/Inventory/InventoryCostDeliveryModeStampTest.php`
- `tests/Postgres/Operations/Inventory/InventoryCostDeliveryModeGateTest.php`
- `tests/Postgres/Operations/Inventory/InventoryCostDeliveryCrossCutoverIdempotencyTest.php`
- `tests/Postgres/Operations/Inventory/InventoryReversalDeliveryModeTest.php`

Mandatory assertions cover:

- ownership and pilot uniqueness;
- enrolled bootstrap creates exactly one synchronous version 1 ownership; exact repeat is idempotent; mismatch, non-enrolled lifecycle, incomplete snapshots, and concurrent conflict fail closed;
- enrolled-without-ownership fails as `ENROLLED_DELIVERY_OWNERSHIP_MISSING`, while truly non-enrolled resolves `NOT_ENROLLED` without fabricating ownership;
- immutable ownership identity and activated cutover evidence;
- complete group/snapshot coverage and N/N+1 constraint, including virgin null/absent -> 0/1 and null/zero -> 0/1 without AVCO-state mutation;
- allocator positive with null AVCO, unequal positive allocator/AVCO, and absent-or-zero allocator with positive AVCO all block as sequence-state divergence;
- no mid-period or Reopened-period cutover;
- quiescence and in-flight producer-document failure;
- historical synchronous and unenrolled exclusion without outbox-history rewrite;
- exact watermark boundary and rejection below it;
- synchronous source cannot apply deferred and deferred source cannot apply synchronously;
- N+1 blocked by unresolved N;
- first deferred sequence 1 is accepted from virgin null AVCO state, while sequence 2 before 1 is blocked;
- exact duplicate equivalence is success and mismatched duplicate is integrity failure;
- consumer crash atomicity at both injected points;
- mode-aligned reversal and paired transfer;
- one-pilot-Property and cross-Property isolation;
- malformed/missing source, unsupported type, closed Business Date/Period, missing enrollment, and mismatched scope fail closed;
- no InventoryStockMovement monetary/source use;
- no new direct Operations/Receiving import of CostControl implementations.
- exact synchronous source retry after deferred cutover preserves its original stamp and produces no new source, sequence, outbox, Cost Ledger, or deferred apply; mismatched retry fails closed.

Existing focused tests that must remain green include Inventory posting/outbox/sequence/immutability/reversal tests, enrollment persistence/preflight tests, controlled receipt/issue/adjustment/transfer apply tests, Cost Ledger schema/append tests, Foundation Outbox tests, Receiving controlled posting tests, Financial Period/Business Date integration tests, `tests/Postgres/Finance/GeneralLedger/VariancePostingEngineEnrollmentGuardTest.php`, and `tests/Postgres/Finance/CostControl/ControlledIssueValuationInvocationGLTest.php`.

## Dependency-ordered implementation slices

Each slice is separately authorized, produces no activation unless explicitly stated, and stops on any source contradiction.

### CC-P01A — Mode, source stamp, pilot, and cutover evidence foundation

Purpose: establish durable exclusive ownership and immutable source/cutover evidence without changing active synchronous behavior.

Expected production files:

- `Modules/Finance/CostControl/Enums/CostDeliveryMode.php`
- `Modules/Finance/CostControl/Models/CostDeliveryPilotProperty.php`
- `Modules/Finance/CostControl/Models/CostDeliveryModeOwnership.php`
- `Modules/Finance/CostControl/Models/CostDeliveryCutover.php`
- `Modules/Finance/CostControl/Models/CostDeliveryCutoverScope.php`
- `Modules/Finance/CostControl/Models/CostDeliveryCutoverAttempt.php`
- `Modules/Finance/CostControl/Repositories/CostDeliveryModeOwnershipRepository.php`
- `Modules/Finance/CostControl/Repositories/CostDeliveryCutoverRepository.php`
- `Modules/Finance/CostControl/Services/CostDeliveryModeOwnershipBootstrapService.php`
- `Modules/Operations/Inventory/Contracts/CostDeliveryModePort.php`
- `Modules/Operations/Inventory/ValueObjects/CostDeliveryPostingDecision.php`
- `Modules/Finance/CostControl/Adapters/InventoryCostDeliveryModeAdapter.php`
- `Modules/Finance/CostControl/CostControlServiceProvider.php`
- `Modules/Operations/Inventory/Models/InventoryTransaction.php`
- `Modules/Operations/Inventory/Repositories/InventoryTransactionRepository.php`
- `Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php`

Expected migrations:

- `Modules/Finance/CostControl/database/migrations/2026_08_21_000100_create_cost_delivery_pilot_properties_table.php`
- `Modules/Finance/CostControl/database/migrations/2026_08_21_000200_create_cost_delivery_mode_ownerships_table.php`
- `Modules/Finance/CostControl/database/migrations/2026_08_21_000300_create_cost_delivery_cutover_evidence_tables.php`
- `Modules/Operations/Inventory/database/migrations/2026_08_21_000400_add_cost_delivery_mode_evidence_to_inventory_transactions_table.php`

Tests: ownership, controlled bootstrap, trigger, source-stamp, virgin 0/1 watermark, non-virgin divergence, pilot isolation, migration rollback, and PostgreSQL lock tests listed above. Bootstrap proof covers exact repeat idempotency, mismatch rejection, every non-enrolled group status, incomplete snapshots, concurrent attempts, missing ownership under posting enforcement, and truly non-enrolled behavior.

Deployment order is mandatory:

1. **A1:** install schema, models, repositories, Inventory-owned ports, and the controlled bootstrap capability. New columns remain compatible; mandatory enrolled stamping is not enabled.
2. **A2:** through an explicitly authorized invocation of `CostDeliveryModeOwnershipBootstrapService`, create exact version 1 `SYNCHRONOUS` ownership evidence for every existing enrolled Property + Item group. No migration seeds business rows.
3. **A3:** prove every enrolled group has exactly one equivalent synchronous ownership row, with no deferred row, missing group, duplicate, or snapshot mismatch.
4. **A4:** only after A3 passes, enable mandatory source mode stamping for enrolled postings. `ENROLLED + NO OWNERSHIP` fails closed and never resolves as unenrolled.

No source may be stamped `DEFERRED` during CC-P01A. Current production remains `SYNCHRONOUS_TRANSITIONAL_ACTIVE`. The future enrollment activation invariant must be installed before any later enrollment is authority-activated: initial synchronous ownership is created atomically with activation or activation fails closed.

Prerequisites: accepted CC-P01 plan and clean canonical rebase. Non-goals: consumer, disposition, synchronous bypass, cutover service, pilot/deferred activation, or current enrollment runtime mutation in this planning correction. Activation effect: `NONE`; controlled bootstrap records only current synchronous delivery ownership and no pilot/cutover data is inserted by migration. Rollback: schema rollback allowed only before operational data; once evidence exists, forward correction is required.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Finance/CostControl/CostDeliveryModeOwnershipPersistenceTest.php tests/Postgres/Finance/CostControl/CostDeliveryModeOwnershipBootstrapTest.php tests/Postgres/Finance/CostControl/CostDeliveryCutoverPersistenceTest.php tests/Postgres/Operations/Inventory/InventoryCostDeliveryModeStampTest.php
```

### CC-P01B — Historical disposition and observability foundation

Purpose: durably classify historical outbox/source rows without modifying Foundation Outbox history.

Expected production files:

- `Modules/Finance/CostControl/Enums/CostDeliveryDispositionClass.php`
- `Modules/Finance/CostControl/Enums/CostDeliveryProcessingState.php`
- `Modules/Finance/CostControl/Models/CostDeliveryOutboxDisposition.php`
- `Modules/Finance/CostControl/Repositories/CostDeliveryOutboxDispositionRepository.php`
- `Modules/Finance/CostControl/Services/CostDeliveryHistoricalDispositionService.php`
- `Modules/Finance/CostControl/Services/CostDeliveryObservabilityProjection.php`
- `Modules/Finance/CostControl/ValueObjects/CostDeliveryDispositionDecision.php`

Expected migration:

- `Modules/Finance/CostControl/database/migrations/2026_08_21_010100_create_cost_delivery_outbox_dispositions_table.php`

Tests: historical synchronous exclusion, unenrolled exclusion, ambiguous enrolled failure, immutable class, state transitions, Property isolation, and shared-outbox non-mutation.

Prerequisites: CC-P01A. Non-goals: bulk replay, automatic classifier scheduler, consumer, outbox enum change, activation. Activation effect: `NONE`; classification requires explicitly invoked controlled service. Rollback: insert-only classifications remain; incorrect classification requires a separately authorized correction, never update/delete.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Finance/CostControl/CostDeliveryHistoricalDispositionTest.php tests/Postgres/Foundation/Outbox/OutboxMessagePersistenceTest.php tests/Postgres/Foundation/Outbox/OutboxMessageDeliveryStateTest.php
```

### CC-P01C — Eligibility and source-equivalence boundary

Purpose: prove deferred eligibility and enforce one Cost Ledger row per source across modes.

Expected production files:

- `Modules/Finance/CostControl/Services/DeferredCostDeliveryEligibilityService.php`
- `Modules/Finance/CostControl/ValueObjects/DeferredCostDeliveryEligibleContext.php`
- `Modules/Finance/CostControl/ValueObjects/DeferredCostDeliveryFailure.php`
- `Modules/Finance/CostControl/Repositories/CostLedgerRepository.php`
- `Modules/Finance/CostControl/Services/CostLedgerAppendService.php`

Expected migration:

- `Modules/Finance/CostControl/database/migrations/2026_08_21_020100_enforce_cost_ledger_source_transaction_uniqueness.php`

Tests: every eligibility gate, exact duplicate equivalence, mismatch failure, concurrent unique arbitration, historical/null source exclusion, and migration preflight failure on seeded duplicates.

Prerequisites: CC-P01A and B. Non-goals: consumer, handler, recovery, period reopening. Activation effect: `NONE`. Rollback: unique constraint may be removed only before consumer/cutover activation; no data mutation is performed by rollback.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Finance/CostControl/DeferredCostDeliveryEligibilityTest.php tests/Postgres/Finance/CostControl/CostLedgerSourceEquivalenceTest.php tests/Postgres/Finance/CostControl/CostLedgerAppendBoundaryTest.php tests/Postgres/Finance/CostControl/CostLedgerPostgresSchemaContractTest.php
```

### CC-P01D — Mode-safe reversal source delivery

Purpose: ensure reversal cannot create an unstamped or synchronous-bypass sequence after cutover.

Expected production files:

- `Modules/Operations/Inventory/Services/InventoryReversalPostingService.php`
- `Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php`
- `Modules/Operations/Inventory/Repositories/InventoryTransactionRepository.php`
- `Modules/Finance/CostControl/Services/ControlledReversalValuationPlanner.php`
- `Modules/Operations/Inventory/Contracts/SynchronousCostValuationPort.php`
- `Modules/Finance/CostControl/Adapters/InventorySynchronousCostValuationAdapter.php`
- `Modules/Finance/CostControl/CostControlServiceProvider.php`

Migrations: none beyond CC-P01A. Tests: synchronous reversal stamp/apply, deferred reversal stamp/outbox/no-sync, anti-double reversal, strict sequence, open-period behavior, and concurrent reversal/consumer setup.

Prerequisites: CC-P01A-C. Non-goals: generic correction, reopen, replay, activation. Activation effect: `NONE`; current ownership remains synchronous. Rollback: deploy code rollback only before deferred ownership exists; afterward forward fix is mandatory.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Operations/Inventory/InventoryReversalDeliveryModeTest.php tests/Postgres/Operations/Inventory/InventoryReversalPostingServiceTest.php tests/Postgres/Operations/Inventory/InventoryTransactionReversalSchemaContractTest.php tests/Postgres/Finance/CostControl/ControlledReversalValuationPlannerTest.php
```

### CC-P01E — Deferred consumer and transfer-safe atomic apply

Purpose: implement, but do not transport-activate, the CostControl consumer and transaction handlers.

Expected production files:

- `Modules/Finance/CostControl/Services/DeferredCostDeliveryConsumer.php`
- `Modules/Finance/CostControl/Services/DeferredSingleTransactionValuationHandler.php`
- `Modules/Finance/CostControl/Services/DeferredTransferValuationHandler.php`
- `Modules/Finance/CostControl/Services/DeferredCostDeliveryFailureRecorder.php`
- `Modules/Finance/CostControl/Services/ControlledValuationApplyCoordinator.php`
- `Modules/Finance/CostControl/Services/ControlledTransferValuationApplyCoordinator.php`
- `Modules/Finance/CostControl/Repositories/CostAvcoStateRepository.php`
- `Modules/Finance/CostControl/Repositories/CostDeliveryOutboxDispositionRepository.php`
- `Modules/Foundation/Outbox/Repositories/OutboxRepository.php`
- remove `Modules/Finance/CostControl/Services/PairedTransferValuationService.php` after references/tests migrate

Migrations: none. Tests: consumer success/failure mapping, strict sequence, crash injection, same-message concurrency, same-scope concurrency, paired transfer atomicity, source equivalence, and period closure.

Prerequisites: CC-P01A-D. Non-goals: queue, listener, scheduler, afterCommit dispatch, retry, replay, UI, production invocation. Activation effect: `NONE`; service exists but has no production transport caller. Rollback: code can be rolled back before transport/cutover; immutable successful test data remains test-only.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Finance/CostControl/DeferredCostDeliveryConsumerTest.php tests/Postgres/Finance/CostControl/DeferredTransferDeliveryTest.php tests/Postgres/Finance/CostControl/CostDeliveryConcurrencyProofTest.php
```

### CC-P01F — Synchronous ownership gate and cutover coordinator

Purpose: replace frozen direct coupling with Inventory-owned ports, make every new producer source mode-safe, preserve existing-source idempotency across cutover, and implement the non-UI cutover service.

Expected production files:

- `Modules/Operations/Inventory/Contracts/CostDeliveryModePort.php`
- `Modules/Operations/Inventory/Contracts/SynchronousCostValuationPort.php`
- `Modules/Finance/CostControl/Adapters/InventoryCostDeliveryModeAdapter.php`
- `Modules/Finance/CostControl/Adapters/InventorySynchronousCostValuationAdapter.php`
- `Modules/Finance/CostControl/Services/CostDeliveryCutoverService.php`
- `Modules/Finance/CostControl/Services/CostDeliveryCutoverPreflightService.php`
- `Modules/Finance/CostControl/Repositories/CostDeliveryCutoverPreflightRepository.php`
- `Modules/Finance/CostControl/ValueObjects/CostDeliveryCutoverRequest.php`
- `Modules/Finance/CostControl/Services/ControlledReceiptValuationInvocationService.php`
- `Modules/Finance/CostControl/Services/ControlledIssueValuationInvocationService.php`
- `Modules/Finance/CostControl/Services/ControlledAdjustmentValuationInvocationService.php`
- `Modules/Finance/CostControl/Services/ControlledTransferValuationInvocationService.php`
- `Modules/Operations/Inventory/Services/ReceiptService.php`
- `Modules/Operations/Inventory/Services/IssueService.php`
- `Modules/Operations/Inventory/Services/AdjustmentService.php`
- `Modules/Operations/Inventory/Services/TransferService.php`
- `Modules/Operations/Inventory/Services/InventoryPostingControlCoordinator.php`
- `Modules/Operations/Inventory/Repositories/InventoryReceiptRepository.php`
- `Modules/Operations/Inventory/Repositories/InventoryIssueRepository.php`
- `Modules/Operations/Inventory/Repositories/InventoryAdjustmentRepository.php`
- `Modules/Operations/Inventory/Repositories/InventoryTransferRepository.php`
- `Modules/Operations/Receiving/Services/ReceivingService.php`
- `Modules/Operations/Receiving/Services/InventoryReceiptIntegrationService.php`
- `Modules/Operations/Receiving/Repositories/ReceivingRepository.php`
- `Modules/Operations/Receiving/Repositories/ReceivingLineRepository.php`
- `Modules/Finance/CostControl/CostControlServiceProvider.php`

Migrations: none. Tests: all producer mode stamps/bypass paths, removal of direct imports, document mutation quiescence lock, complete preflight, period boundary, historical disposition, watermarks, posting/cutover races, and exact existing-source retry after ownership changes. The retry proof requires unchanged original source stamp and counts for InventoryTransaction, allocator sequence, OutboxMessage, CostLedgerEntry, and deferred application; a mismatched retry must fail closed.

Prerequisites: CC-P01A-E. Non-goals: UI, general command, consumer transport activation, replay, Property rollout. Activation effect: `NONE` until an Owner-approved pilot row and cutover call are supplied; default synchronous behavior remains. Rollback: safe only while all ownership remains synchronous; after a deferred cutover, rollback to code that can synchronously apply is prohibited.

Validation command:

```powershell
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Operations/Inventory/InventoryCostDeliveryModeGateTest.php tests/Postgres/Operations/Inventory/InventoryCostDeliveryCrossCutoverIdempotencyTest.php tests/Postgres/Finance/CostControl/CostDeliveryCutoverServiceTest.php tests/Postgres/Finance/CostControl/CostDeliveryConcurrencyProofTest.php tests/Postgres/Operations/Receiving/ReceivingControlledPostingTest.php
```

### CC-P01G — Pilot readiness and PostgreSQL activation proof

Purpose: run the complete source-integrity, migration, functional, accounting-authority, and isolated-concurrency proof for one candidate Property without activating production.

Expected production files/migrations: none unless an earlier accepted slice has a proven defect; any defect returns to its owning slice and requires review. Expected tests are all new classes listed above plus all directly affected existing PostgreSQL classes.

CC-P01G must produce an accepted readiness record proving all of the following before Owner pilot authorization:

- every existing enrolled group has exactly one version 1 synchronous ownership and no enrolled group is missing ownership;
- virgin scopes produce only the 0/1 sentinel, keep `CostAvcoState.last_valuation_sequence` null, accept sequence 1 first, and block sequence 2 before 1;
- every non-virgin allocator value equals the AVCO state's applied sequence, with every divergence case blocked;
- exact pre-cutover synchronous source retry after deferred cutover preserves the original source and creates no second sequence, outbox row, Cost Ledger entry, or deferred application;
- `GL_AUTHORITY_PATH_PROOF = PASS`.

The GL authority proof must include `tests/Postgres/Finance/GeneralLedger/VariancePostingEngineEnrollmentGuardTest.php` and `tests/Postgres/Finance/CostControl/ControlledIssueValuationInvocationGLTest.php`, plus any other applicable enrolled CostControl-to-GL integration test exposed by implementation source. It proves enrolled adjustment cannot create a legacy InventoryTransaction-based variance candidate, enrolled issue accounting originates from `CostLedgerEntry`, no supported enrolled CC-P01 transaction receives a second monetary GL candidate from independently trusted InventoryTransaction cost, and cross-Property behavior remains isolated. If later source inspection finds another direct enrolled InventoryTransaction-to-GL monetary path, CC-P01G stops and requires source correction before pilot readiness.

Test/source-integrity results are accepted governance evidence in the readiness record. They are not introspected by a runtime service or written dynamically into cutover tables. Owner pilot authorization is prohibited unless that accepted record states `GL_AUTHORITY_PATH_PROOF = PASS`.

Prerequisites: accepted A-F commits, clean fresh database migration, explicit test-only pilot fixture, no unresolved findings. Non-goals: production pilot data, cutover invocation, queue/worker/listener, replay, global rollout, registry-baseline promotion without separate governance. Activation effect: `NONE`; proof ends with a signed readiness record and Owner decision gate. Rollback: drop only the isolated proof database after terminating its connections; do not mutate `ivorq_testing` outside the approved test protocol.

Validation commands:

```powershell
php artisan migrate:fresh --env=testing --database=pgsql
php artisan test --configuration=phpunit.pg.xml tests/Postgres/Finance/CostControl tests/Postgres/Operations/Inventory tests/Postgres/Operations/Receiving/ReceivingControlledPostingTest.php tests/Postgres/Foundation/Outbox tests/Postgres/Finance/GeneralLedger/VariancePostingEngineEnrollmentGuardTest.php
git diff --check
```

The implementing package must use the repository regression-baseline registry to determine any broader required gate. This plan does not promote baseline metadata.

## Activation and rollback invariants

- Database migrations never insert pilot, ownership, disposition, or cutover business data automatically.
- Existing enrolled groups are bootstrapped through the controlled synchronous ownership service before mandatory stamping; enrolled-without-ownership then fails closed.
- Installing A-F leaves current synchronous production active.
- Creating the pilot singleton alone does not change delivery mode.
- Historical classification alone does not activate consumer processing.
- Only the accepted cutover service transaction may change one ownership row to deferred.
- Once any deferred source is posted, rollback to synchronous code/configuration is prohibited. Recovery is forward-only and separately authorized.
- A cutover failure leaves ownership synchronous, creates no watermark, and records a blocked attempt.
- Deferred handler failure never falls back to synchronous valuation.
- Virgin zero is only a cutover sentinel; AVCO sequence remains null until sequence 1 successfully applies.
- Owner pilot authorization requires an accepted CC-P01G record with `GL_AUTHORITY_PATH_PROOF = PASS`.
- Exact existing-source retry always preserves its immutable original mode stamp or historical disposition across cutover.
- No all-Property rollout mechanism exists in this train.

## ADR reconciliation

| ADR | Plan conformance |
|---|---|
| ADR-041 | Inventory retains immutable source/outbox ownership; payload remains transaction ID only; consumer is CostControl-owned; dependency is one-way; no automatic retry or production transport is added. |
| ADR-042 | Exact posting-time sequence, null-to-sequence-1 virgin semantics, zero boundary sentinel without fabricated AVCO history, locked AVCO state, strict N/N+1 barrier, closed-context fail-closed handling, structured equivalence, atomic apply/delivered transaction, durable failures, and no automatic retry are explicit. |
| ADR-043 | Complete Property + Item ownership, controlled synchronous bootstrap, per-location canonical scopes, one pilot Property, Financial Period boundary, no mid-period cutover, quiescence, immutable evidence, GL authority-path proof, and no mixed authority are explicit. |
| ADR-079 | `InventoryStockMovement` remains quantity-only and is not promoted to monetary or Cost Ledger source authority. |
| ADR-080 | Controlled Goods Receipt movement evidence is not conflated with the transaction/valuation universe or AP/GL. |
| ADR-081 | Inventory movement ownership and quantity protection remain unchanged; no unified-ledger or correction claim is made. |
| ADR-082 | The movement-based projection remains `READ_ONLY_EVIDENCE`; durable CostControl state continues to use `InventoryTransaction`. |
| ADR-083 | Immutable receipt commercial/base-currency evidence remains the valuation source where applicable; unsupported FX and historical backfill remain blocked. |

`ADR_VERDICT = NO_NEW_ADR_REQUIRED_FOR_CC_P01_PLAN`

The selected tables, source stamp, zero cutover sentinel, ownership bootstrap, application ports, GL readiness proof, locks, and service boundaries are implementation details required to realize already accepted ADR-041/042/043 and INV-G1 decisions. They do not introduce a new source authority, dependency direction, accounting boundary, retry policy, correction policy, or rollout model. Zero never fabricates historical valuation evidence.

## Explicit package non-goals

- No production PHP, React/TypeScript, migration, model, service, provider, route, command, queue, worker, listener, scheduler, retry, replay, or consumer change in this planning package.
- No runtime or pilot activation.
- No test code and no test execution.
- No ADR or Contract change.
- No registry update; the current registry already records planning eligibility and runtime inactivity.
- No Inventory ledger merge, movement monetary promotion, historical backfill, Cost Ledger repair, generic correction, Financial Period reopen, Business Date reopen, negative inventory, FX expansion, GL/AP expansion, or synchronous fallback after deferred ownership.
- No Package 21.

## Planning closure

- Recommended architecture: CostControl control plane + Inventory-owned mode/application ports + immutable source stamp + source-unique Cost Ledger + CostControl deferred consumer.
- Delivery-mode persistence: `cost_delivery_mode_ownerships` at complete Property + Item enrollment-group scope.
- Existing-enrollment bootstrap: controlled CostControl service creates exact synchronous version 1 ownership before mandatory stamping; missing enrolled ownership fails closed.
- Cutover persistence: immutable cutover and complete per-location N/N+1 scope evidence, with virgin 0/1 sentinel and null AVCO state preserved until sequence 1 applies.
- Historical disposition: immutable CostControl classification separate from shared outbox state.
- Eligibility: one fail-closed CostControl service with under-lock revalidation.
- Cross-mode idempotency: unique source transaction plus exact equivalence.
- Existing-source idempotency: immutable original mode stamp or historical disposition remains authoritative across later ownership cutover.
- Reversal: same source stamp/outbox/mode pipeline as other valuation-sequence-producing transactions.
- Synchronous bypass: ownership lock before sequence/state work and port revalidation before apply.
- Cutover: non-UI controlled application service with one-pilot, boundary, quiescence, document, disposition, watermark, and reversal gates.
- Concurrency: one canonical ownership-first PostgreSQL lock order with isolated two-context proofs.
- GL readiness: accepted CC-P01G source/test record must state `GL_AUTHORITY_PATH_PROOF = PASS` before Owner pilot authorization.
- Observability: durable disposition/outcome and cutover-attempt records; no log-only business state.
- Recovery: separate future authorization; no automatic retry or broad replay.
- Implementation order: `CC-P01A -> CC-P01B -> CC-P01C -> CC-P01D -> CC-P01E -> CC-P01F -> CC-P01G`.

`PRODUCTION_CHANGED = NO`

`TESTS_CHANGED = NO`

`MIGRATIONS_CHANGED = NO`

`CONTRACT = 1.22 UNCHANGED`

`CC_P01_RUNTIME_ACTIVATED = NO`

`DEFERRED_CONSUMER_ACTIVATED = NO`

`PACKAGE_21_ACTIVATED = NO`

`FINAL_STATUS = CC_P01_IMPLEMENTATION_PLAN_COMPLETE`
