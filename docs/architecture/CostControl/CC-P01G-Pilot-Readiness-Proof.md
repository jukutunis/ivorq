# CC-P01G Pilot Readiness Proof

## Evidence identity

- Package: `CC-P01G_PILOT_READINESS_FULL_PROOF`
- Contract: `1.22` (unchanged)
- Execution date: `2026-09-12` final validation (migration and concurrency proof began `2026-09-11`)
- Canonical branch: `ivorq-enterprise-core`
- Canonical predecessor SHA: `ba1e8cb39fd8bb26a875061adaafe9dcb66f8e75`
- Proof branch: `codex/cc-p01g-pilot-readiness-proof-final`
- Feature/proof SHA: the commit containing this record; resolve with `git rev-parse HEAD` after publication. The immutable SHA is also returned with the Draft PR evidence.
- Candidate Property: `CC-P01G-TEST-CANDIDATE-01` — one isolated PostgreSQL fixture Property per test transaction; no operational or retained pilot row.

This record is readiness evidence only. It does not authorize a pilot, a production cutover, deferred ownership, a consumer, a worker, a scheduler, or any runtime activation.

## Source and migration integrity

- The proof worktree was created from the exact canonical predecessor.
- The two prior CC-P01G diagnostic worktrees were inspected read-only and preserved.
- All other protected worktrees were preserved.
- Authorized delta: PostgreSQL tests plus this bounded evidence record only.
- Production PHP, migration, configuration, provider, listener, route, model, service, repository, enum, and runtime changes: none.
- Canonical configuration: `phpunit.pg.xml` against the isolated `ivorq_testing` PostgreSQL database.
- Fresh zero-to-HEAD migration: PASS.
- Duplicate migrations: none.
- Latest approval task identity migration: applied exactly once.
- `tasks.approval_request_id`: nullable `char(26)`.
- `tasks.taskable_type`: `varchar(255)`.
- `tasks_approval_request_status_index`: `(approval_request_id, status)`.
- Migration-created production pilot, ownership, cutover, historical InventoryTransaction, or Cost Ledger data: none.

## Ownership and cutover proof

The isolated pilot fixture proves one complete ENROLLED Property + Item authority group with its immutable location snapshots and exactly one initial ownership:

- Delivery mode before cutover: `SYNCHRONOUS`.
- Ownership version: `1`.
- Activated cutover ID: `NULL`.
- Competing ownership: none.
- Missing ownership: fails closed; posting does not fabricate ownership.
- Missing, duplicate, mixed, or cross-Property authority scope: fails closed.

The cutover suite proves the canonical financial-period boundary, quiescence, producer-document, historical-disposition, deferred-disposition, reversal, scope completeness, sequence consistency, and single-pilot prerequisites. Blocked attempts leave ownership synchronous and do not activate a cutover or accept a watermark.

## Sequence and monetary application proof

### True virgin scope

- Initial `CostAvcoState.last_valuation_sequence`: `NULL`.
- Allocator: absent or `last_sequence = 0`.
- Positive-sequence InventoryTransaction history: absent.
- Last synchronously owned sequence sentinel: `0`.
- First deferred owned sequence: `1`.
- The zero sentinel is not persisted into AVCO state.
- Applying sequence 1 advances AVCO from `NULL` to `1` without fabricating a sequence-zero InventoryTransaction or Cost Ledger entry.

Sequence 2 before sequence 1 returns `BLOCKED_SEQUENCE` with expected sequence 1. It appends no Cost Ledger entry, does not advance AVCO, and does not mark the outbox delivered. Automatic retry returns recovery-required without a second attempt. After sequence 1 is delivered, the canonical explicit `BLOCKED_SEQUENCE -> PENDING` recovery transition allows sequence 2 to deliver exactly once; AVCO advances to 2 and the source has exactly one Cost Ledger effect.

### Non-virgin scope

- Allocator 5 / AVCO 5: accepted with watermark `5 / 6`.
- Allocator 5 / AVCO 4: `CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE`.
- Allocator 5 / AVCO `NULL`: `CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE`.
- Allocator absent-or-zero / AVCO 5: `CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE`.
- Divergence produces no ownership change, activated cutover, accepted watermark, or Cost Ledger mutation.

### Cross-cutover source retry and uniqueness

An exact source is first created under synchronous ownership and retried after an isolated simulated cutover with the same producer idempotency key:

- Returned InventoryTransaction: the original source.
- Original `SYNCHRONOUS` ownership stamp: preserved.
- Valuation sequence: preserved.
- Additional InventoryTransaction: 0.
- Additional valuation sequence: 0.
- Additional outbox row: 0.
- Additional Cost Ledger entry: 0.
- Deferred reapplication: 0.

The synchronous and deferred paths both enforce exact source equivalence. Competing or mismatched monetary effects fail closed; exact equivalent retries return the canonical idempotent outcome.

## Atomicity and isolated concurrency

The deferred application tests inject failures after Cost Ledger append, after AVCO transition, and before outbox delivery. Every failure rolls back the monetary/state transition as one PostgreSQL transaction.

The isolated multi-process PostgreSQL proof covers:

- posting-first versus cutover-first ownership lock ordering;
- synchronous invocation versus ownership change;
- same-message competing consumers;
- same-scope distinct message sequence serialization;
- two-scope transfer lock ordering;
- existing synchronous retry concurrent with cutover;
- mixed existing/new document resolution concurrent with cutover.

Result: 9 tests / 96 assertions / PASS. No undefined delivery stamp, double Cost Ledger effect, duplicate AVCO advance, duplicate terminal delivery, or deadlock was observed.

## General Ledger authority proof

Canonical source inspection found these monetary inventory-to-GL paths:

1. `VariancePostingEngine` accepts `InventoryTransaction` adjustment variance only after checking the exact Property + Item enrollment state. ENROLLED authority throws before `JournalCandidate::firstOrCreate`. `JournalCandidateReevaluationService` delegates the same source back to this guarded engine.
2. ENROLLED issue accounting enters `CostIssuePostingEngine` from the canonical CostControl invocation using `CostLedgerEntry` as the JournalCandidate source identity.
3. GRNI candidate generation uses the purchase-backed `InventoryReceipt` accrual identity; it is distinct from direct trust in InventoryTransaction valuation cost.
4. Receipt, adjustment, transfer, and reversal synchronous valuation use CostControl source-equivalence and Cost Ledger boundaries. No additional supported enrolled direct InventoryTransaction monetary JournalCandidate path was found.

Exact PostgreSQL evidence:

| Test | Result |
| --- | --- |
| `VariancePostingEngineEnrollmentGuardTest` | PASS — 6 tests / 16 assertions |
| `ControlledIssueValuationInvocationGLTest` | PASS — 6 tests / 37 assertions |
| `ControlledReceiptValuationInvocationTest` | PASS — 30 tests / 349 assertions |
| `ReceiptEnrollmentGuardTest` | PASS — 7 tests / 40 assertions |
| `InventorySynchronousCostValuationAdapterTest` | PASS — 4 tests / 22 assertions |
| `ControlledAdjustmentValuationInvocationTest` | PASS — 11 tests / 56 assertions |

- ENROLLED adjustment legacy InventoryTransaction variance candidate: blocked.
- ENROLLED issue JournalCandidate authority: `CostLedgerEntry`.
- Unenrolled and approved-but-not-enrolled behavior: remains on its accepted distinct path.
- Cross-Property behavior: isolated.
- Unsafe enrolled InventoryTransaction monetary GL path: none found.
- `GL_AUTHORITY_PATH_PROOF = PASS`.

## Predecessor regression evidence

| Regression | Result |
| --- | --- |
| Original inventory-reversal blocker method | PASS — 1 test / 10 assertions |
| Full `InventoryReversalApprovalRequestServiceTest` | PASS — 5 tests / 17 assertions |
| `ApprovalCancellationLifecycleTest` | PASS — 14 tests / 127 assertions |
| `ApprovalTaskCancellationLifecycleTest` | PASS — 6 tests / 60 assertions |
| `PurchasingApprovalListenerIsolationTest` | PASS — 12 tests / 122 assertions |
| `ControlledAdjustmentValuationInvocationTest` | PASS — 11 tests / 56 assertions; bounded adjustment key and no duplicate Cost Ledger effect |

No Purchasing Task or notification side effect was observed in the inventory-reversal regression.

## CC-P01 proof inventory

| Proof test | Result |
| --- | --- |
| `CostDeliveryModeOwnershipPersistenceTest` | PASS — 6 tests / 31 assertions |
| `CostDeliveryModeOwnershipBootstrapTest` | PASS — 12 tests / 63 assertions |
| `CostDeliveryCutoverServiceTest` | PASS — 11 tests / 42 assertions |
| `CostDeliveryCutoverPersistenceTest` | PASS — 12 tests / 42 assertions |
| `DeferredCostDeliveryConsumerTest` | PASS — 20 tests / 153 assertions |
| `DeferredCostDeliveryEligibilityTest` | PASS — 41 tests / 135 assertions |
| `InventoryCostDeliveryCrossCutoverIdempotencyTest` | PASS — 1 test / 8 assertions |
| `InventoryCostDeliveryModeStampTest` | PASS — 19 tests / 58 assertions |
| `InventoryProducerCostDeliveryModeGateTest` | PASS — 1 test / 26 assertions |
| `CostDeliveryConcurrencyProofTest` | PASS — 9 tests / 96 assertions |

## Registered regression baselines

- Active applicable baseline `inventory-reversal-inherited-debt-v1`: PASS — 8 tests / 72 assertions / 0 failures / exactly 2 documented inherited immutable-trigger errors. Its registered metadata was not changed.
- Candidate diagnostic `inventory-avco-sensitive-baseline-v2-candidate`: MISMATCH — 592 tests / 6,760 assertions / 10 failures / 61 errors. The canonical registry explicitly marks candidate mismatches as diagnostic and non-gating. The result includes stale immutable-fixture assumptions, current business-date/ownership fixture requirements, and two registered class selectors that select zero tests. No failure was reclassified as accepted debt, and no registry or candidate metadata was changed in CC-P01G.
- New failures in required CC-P01G direct gates: 0.

## Static validation

- PHP syntax: PASS.
- Pint on changed tests: PASS.
- `git diff --check`: PASS.
- Production file gate: PASS — no production file changed.

## Activation state

- Runtime activation: `NONE`.
- Deferred consumer activation: `NONE`.
- Production pilot: `NO`.
- Production cutover: `NO`.
- Deferred ownership: `NO` outside isolated tests.
- Owner pilot authorization: `NOT ISSUED`.
- Current mode: `SYNCHRONOUS_TRANSITIONAL_ACTIVE`.

Final evidence status: `CC_P01G_PILOT_READINESS_PROOF_AWAITING_INDEPENDENT_REVIEW`.
