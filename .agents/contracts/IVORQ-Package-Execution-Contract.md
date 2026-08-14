# IVORQ Package Execution Contract

Status: APPROVED
Version: 1.22
Created: 2026-07-10
Last amended: 2026-08-15

Amendment note:
  - Version 1.1 was explicitly approved by the IVORQ Owner for ADR-034 activation and controlled Business Date / Night Audit sequencing.
  - Version 1.2 was explicitly approved by the IVORQ Owner for FD-B11 authorization, accepted-baseline synchronization, and baseline-snapshot semantics.
  - Version 1.3 was explicitly approved by the IVORQ Owner for NA-A1 authorization, FD-B11 accepted-predecessor synchronization, and Night Audit run/active-lock foundation sequencing.
  - Version 1.4 was explicitly approved by the IVORQ Owner for FD-B12 authorization, NA-A1 accepted-predecessor synchronization, and Front Desk authoritative Night Audit close-lock read integration.
  - Version 1.5 was explicitly approved by the IVORQ Owner for FD-B12 accepted-predecessor synchronization, FD-B13 Checkout Execution Readiness Review authorization, command-contract freeze review, and continued prohibition on checkout execution.
  - Version 1.6 was explicitly approved by the IVORQ Owner for FD-B13 accepted-predecessor synchronization, ADR-089 architecture package authorization, atomic checkout orchestration decision, owner-domain attestation participation, shared Business Date / Night Audit lock coordination, and continued prohibition on runtime checkout implementation.
  - Version 1.7 is explicitly Owner-authorized for ADR-089 accepted-predecessor synchronization, ADR-089 approval status synchronization, NA-A2 runtime authorization, shared Property / Business Date operational locking, Night Audit checkout transaction participation, and continued prohibition on checkout execution.
  - Version 1.8 is explicitly Owner-authorized for NA-A2 accepted-predecessor synchronization, GLF-E runtime authorization, PMS terminal financial attestation, shared evaluator extraction, participation ports, cash-linked reference contract, exact-object issuance, and continued prohibition on checkout execution.
  - Version 1.9 is explicitly Owner-authorized for GLF-E accepted-predecessor synchronization, canonical SHA synchronization, GC-A2 runtime authorization, General Cashier terminal obligation attestation, consumption of minimized exact GLF-E references, General Cashier-owned lock participation, continued prohibition on checkout execution, and continued locking of later packages.
  - Version 1.10 is explicitly Owner-authorized for GLF-E savepoint lock-continuity correction, transaction-local GLF-E attestation capability, reopening GLF-E acceptance for narrow GLF-E-S1 correction, pausing GC-A2 runtime implementation, continued prohibition on checkout execution, and continued locking of all later packages.
  - Version 1.11 is explicitly Owner-authorized for GLF-E-S1 accepted-predecessor synchronization, canonical SHA synchronization, final GLF-E savepoint-safe evidence synchronization, GC-A2 runtime reactivation, General Cashier terminal obligation attestation sequencing, continued prohibition on checkout execution, and continued locking of all later packages.
  - Version 1.12 is explicitly Owner-authorized for GC-A2 accepted-predecessor synchronization, canonical SHA synchronization to f0635b6c402ea095a1cd21b1a1510008c49e7739, acceptance of the General Cashier transaction-bound terminal obligation attestation, activation of FD-C1 Front Desk terminal checkout state foundation, immutable checkout execution evidence foundation, historical pre-Package-9 continued prohibition on actual checkout execution, continued locking of Housekeeping handoff/outbox, continued locking of checkout sensitive confirmation and execute permission, continued locking of the final checkout command and interaction layer, historical pre-Package-9 continued can_execute=false, historical pre-Package-9 continued checkout unauthorized, and confirmation that full access does not bypass package sequencing.
  - Version 1.13 is explicitly Owner-authorized for FD-C1 accepted predecessor synchronization, canonical SHA synchronization to 233b2407dd3c77e86a007b77e9572d2c0d0ea36e, acceptance of FD-C1 terminal stay state and immutable checkout execution evidence foundation, owner-authorized merge with complete-runner infrastructure exception, Front Desk zero-failure baseline 534 / 4817 / 0 / 0, activation of FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation, dedicated checkout handoff/outbox rather than generalizing inventory outbox_messages, minimized identifier-only payload, Housekeeping lifecycle ownership preservation, no direct Front Desk Housekeeping readiness mutation, historical pre-Package-9 continued prohibition on checkout execution, continued locking of Package 8 Checkout sensitive confirmation and execute permission, continued locking of Package 9 Final checkout command and interaction layer, historical pre-Package-9 continued can_execute=false, historical pre-Package-9 continued checkout unauthorized, and confirmation that full access does not bypass package sequencing.
  - Version 1.14 is explicitly Owner-authorized for FD-C2 accepted-predecessor synchronization, canonical SHA synchronization to 13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2, acceptance of FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation through PR #38, activation of Package 8 - Checkout Sensitive Confirmation, Durable One-Time Consumption, and Execute Permission Foundation, future checkout sensitive intent `frontdesk-checkout-execution`, future execute permission `frontdesk.checkout-execution.execute`, durable one-time confirmation consumption inside the same PostgreSQL checkout transaction, authorization-before-stay-resolution, confirmation-is-not-permission, historical pre-Package-9 continued prohibition on checkout execution, continued locking of Package 9 Final checkout command and interaction layer, historical pre-Package-9 continued can_execute=false, historical pre-Package-9 continued checkout unauthorized, and confirmation that this governance activation creates no runtime source, migration, seeder, route, command, policy, UI, tests, baseline metadata, queue, worker, event, scheduler, WebSocket, external integration, checkout execution, stay transition, Housekeeping readiness mutation, or foreign-domain mutation.
  - Version 1.15 is explicitly Owner-authorized for Package 8 accepted-predecessor synchronization, canonical synchronization to 2395884479a69dfa3a876728137676e61a7b374e, acceptance of Package 8 through PR #40, accepted Package 8 feature head eb20396ff3f42fc6f9273d3757ee80ab996b2b4d, accepted checkout intent `frontdesk-checkout-execution`, accepted execute permission `frontdesk.checkout-execution.execute`, accepted authoritative authorization-before-stay-query behavior, accepted durable immutable confirmation issuance, accepted atomic one-time consumption inside a caller-owned PostgreSQL transaction, accepted PostgreSQL expiry, rollback, source-integrity, and concurrency enforcement, accepted additive confirmation references on FD-C1 execution evidence, activation of Package 9, historical pre-Package-9 continued prohibition on actual checkout execution inside that governance package, historical pre-Package-9 continued `can_execute=false`, historical pre-Package-9 continued `CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED`, confirmation that Package 9 runtime required a separate branch and Draft PR, and confirmation that Full Access did not bypass review and merge boundaries.
  - Version 1.16 is explicitly Owner-authorized for Package 9 accepted runtime synchronization, Package 9 accepted and merged through PR #42, canonical synchronization to merge commit 43ad08969e36b1ddc65b0a7227a86d02e2e1a27a, accepted Package 9 feature and metadata SHA df27dc8b7b33caf98ba2dd61305c652069780601, accepted Package 9 final source SHA 77a82dd3951b7bb5804efb496b8939163ba2076d, accepted final checkout execution command, accepted atomic PostgreSQL orchestration, accepted controlled write routes, accepted execute authorization-before-stay-query, accepted Sensitive Action Confirmation lifecycle, accepted terminal CHECKED_OUT stay transition, accepted immutable FD-C1 execution evidence, accepted FD-C2 PENDING Housekeeping handoff creation, accepted JSON and HTML/Inertia committed receipt, accepted idempotent response-loss replay, accepted real PostgreSQL concurrency proof, accepted SQLSTATE retry and revalidation proof, activation of Package 11 governance boundary, and continued prohibition on Package 11 runtime until a separate runtime branch, Draft PR, independent review, and Owner-authorized merge.
  - Version 1.17 is explicitly Owner-authorized for Package 10 Housekeeping checkout-turnover intake governance correction, correction of the canonical Housekeeping readiness-state inventory, recognition that `dirty` is both a source-supported readiness state and `RoomCleanlinessStatusEnum` cleanliness status, source determination that no durable idempotent checkout-turnover intake target currently exists for one FD-C2 checkout handoff to one Housekeeping turnover outcome, ADR-086 checkout-turnover intake amendment, Package 11 runtime authorization remaining separate and unimplemented, and confirmation that no new ADR is required.
  - Version 1.18 is explicitly Owner-authorized for Package 11 acceptance and merge through PR #44, Package 12 acceptance and merge through PR #45, Package 13 acceptance and merge through PR #46, canonical synchronization to `9e21f2e3f40438beb6727d9b6c19af4feb53697a`, current Housekeeping lifecycle and validation-snapshot synchronization, activation of Package 14 governance synchronization, determination of Package 15 Controlled Housekeeping Turnover Dispatch and Assignment as the next separate runtime package, continued locking of Package 15 runtime pending independent review and Owner-authorized merge of Package 14, and confirmation that no new ADR is required.
  - Version 1.19 is explicitly Owner-authorized for Package 14 acceptance and merge through PR #47, Package 15 acceptance and merge through PR #49, canonical synchronization to `29731a60afc16ab4b50291cc06b00e67011e92f7`, accepted controlled Housekeeping initial assignment, pre-start reassignment, immutable assignment history, deterministic Property-scoped idempotency, attendant workload projection, and validation synchronization, activation of Package 16 governance-only synchronization, determination of Package 17 Controlled Housekeeping Inspection Claim and Segregation as the next separate runtime package, continued locking of Package 17 runtime pending independent review and Owner-authorized merge of Package 16, and confirmation that no new ADR is required.
  - Version 1.20 is explicitly Owner-authorized for Package 16 acceptance and merge, Package 17 acceptance and merge through PR #51, canonical synchronization to `37750626f9e0614d26d628a4707bcb205508ae03`, accepted Package 17 feature HEAD `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`, accepted controlled post-cleaning Inspection claim, cleaner/inspector segregation, immutable claim evidence, claimant-owned terminal decisions, PostgreSQL claim-bypass closure, historical Package 13 evidence compatibility, predecessor concurrency and migration-proof alignment, Package 18 governance synchronization activation, Package 19 recovery/reassignment readiness determination, continued locking of Package 19 runtime pending Package 18 independent review, explicit Owner authorization, and merge, and confirmation that no new ADR is required. Package 17's full registry aggregate exit code was not captured; Version 1.20 preserves that disclosure and does not rewrite the historical validation record to claim otherwise.
  - Version 1.21 is explicitly Owner-authorized for Package 18 acceptance and merge through PR #52 at canonical merge `a99f4b20489c3259c416297310a7b02f9cb6dacb`; Package 19 acceptance and merge through PR #53 at canonical merge `086deefca673af57776fcaa14e06494c2f16ab4d`; accepted Package 19 feature HEAD `9bd18634e603ee7e545798dd7ddf913407e2a685`; append-only Package 19 provenance comprising original source `3f05283dc878c9ec098ba0e27b319451abda36ad`, original metadata `88750a9a23067d1630d0bf151510f0a94083f546`, timestamp/PostgreSQL correction `a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50`, and corrected metadata/final feature HEAD `9bd18634e603ee7e545798dd7ddf913407e2a685`; accepted controlled Inspection claim recovery; immutable original Package 17 claim evidence; effective replacement claimant semantics; objective original-claimant ineligibility; one recovery maximum; dedicated sensitive confirmation intent; deterministic evidence timestamps; PostgreSQL malformed-write protection; Package 19 validation-process deviation disclosure; Package 20 governance-only final synchronization; closure of the current Housekeeping turnover/readiness package train; `NO_PACKAGE_21_ACTIVATED`; and confirmation that no new ADR is required. Initial corrected-head complete-registry execution exposed one transient Front Desk Scenario H worker-marker timeout. The exact failing class subsequently passed twice: 14 tests / 383 assertions. An additional clean registry retry was performed WITHOUT prior independent rerun authorization. That additional run passed: 14/14 targets, 1351 tests, 13108 assertions, 0 failures, exactly 2 registered inherited errors, 0 skipped, exit code 0. Independent review accepted the retry as a disclosed validation-process deviation, not source-correction evidence. Package 17's historical validation disclosure remains preserved and is not rewritten.
  - Version 1.22 is explicitly Owner-authorized for acceptance of the Master Domain Registry and CC-R1 through PR #55 at canonical merge `bc17d38060f2cdf91c45049aa593e358ae7e1c4c`; preservation of CC-R1 original audit provenance `cf8d1c840497d476362ca72925dfbfa3bba7ce1f` and provenance-correction/final feature HEAD `c368a0b93aaca7b963c9ba076268a327d93caf6b`; activation of the governance-only `CC-G1_COST_CONTROL_OWNERSHIP_ACTIVATION_AND_LEDGER_PRECEDENCE_FREEZE`; exact Inventory/Cost Ledger precedence, legacy-versus-enrolled AVCO authority, current synchronous-versus-future deferred delivery, dependency direction, negative-stock, reversal/correction, bounded GRNI/AP, Business Date/Financial Period, and analytical-workspace-versus-durable-write ownership freezes; synchronization of existing ADRs only; no new ADR; no runtime implementation; no `CC-P01`, `INV-R1`, `FIN-R1`, or `PROC-R1` activation; and `NO_PACKAGE_21_ACTIVATED`.

Amendment protocol:
  - Only Owner may approve amendments to this contract.
  - Any amendment must bump Version and update Last amended.
  - Package prompts must state which contract Version they were run under.
  - No AI agent may self-amend this contract as part of a package task.

---

## 1. Purpose

This contract governs package-by-package execution for IVORQ. It is reused by DeepSeek/Claude/Gemini prompts. It prevents unsafe "do everything at once" work. It supports sequential semi-automation only.

One package produces one Draft PR. ChatGPT/Owner review is required before merge.

## 2. Non-goals

This contract does NOT:

- Authorize runtime implementation by itself.
- Authorize merging.
- Authorize default branch push.
- Authorize cross-domain mutation.
- Authorize candidate baseline promotion.
- Authorize broad refactor.
- Authorize destructive migrations.

## 3. Governance sources

Every package must consult:

- `GEMINI.md` — canonical AI instruction baseline.
- `CLAUDE.md` — Claude Code bridge.
- Relevant `.agents/skills/**/SKILL.md` files for the domain.
- Approved ADRs / source-backed architecture records.
- Current validation baseline registry (`scripts/validation/ivorq-regression-baselines.json`).
- Current active package sequence as recorded in this contract (Section 6).

## 4. Architecture reference summary

Concise source-backed architecture summary — not a full ADR dump.

- **Multi-tenant hierarchy:** Enterprise → Tenant / Cloud Name → Property.
- **Property context:** Property isolation is mandatory for operational work.
- **Audit trail:** State-changing work must preserve audit/source evidence.
- **Approval engine:** Approval-sensitive workflows must not bypass authorization or approval state.
- **Finance boundary:** Front Desk must not mutate General Cashier, GL, AR, tax, revenue, settlement, payment, invoice, Night Audit, or Accounting unless the exact owner-domain package explicitly authorizes it.
- **Business Date / Night Audit boundary:** Business Date / Night Audit owns its own operational business-date lifecycle and orchestration evidence. It may read source-domain attestations, but it must not absorb Folio, payment, cashier, settlement, tax, revenue, GL, AR, Inventory, Front Desk stay, checkout, financial-period close, or source-domain ownership.
- **Interaction layer:** Use hospitality operational workspaces, not generic CRUD/admin screens. OPERA-derived logic, modern human-designed hospitality UX. No AdminLTE/long-sidebar patterns.
- **Inventory/Cost boundary:** Inventory Ledger remains source of truth. AVCO is current valuation method. FIFO is future scope. Candidate baselines remain diagnostic unless explicitly promoted by Owner.
- **Agent governance:** Delivery Mode by default. Exact scope, focused validation, concise evidence, stop.

## 5. Accepted predecessor snapshot at package authorization

- This section is a snapshot taken at package authorization. It is not a self-updating registry.
- Current exact canonical SHA must always be verified from `origin/ivorq-enterprise-core`.
- Current baseline counts must always be verified from the canonical regression manifest.
- This section records the accepted predecessor state at the time a package is authorized.
- Later merge results must not be inferred before independent merge verification.
- Historical Package 9 authorization predecessor: `2395884479a69dfa3a876728137676e61a7b374e`
- ADR-034 merged and accepted.
- BD-A1 merged and accepted.
- FD-B11 merged and accepted.
- NA-A1 merged and accepted.
- GLF-D merged and accepted.
- FD-B9 merged and accepted.
- GC-A1 merged and accepted.
- FD-B10 merged and accepted.
- FD-B12 merged and accepted.
- FD-B13 accepted and merged.
- FD-B13 verdict: `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`
- Historical FD-B13 ADR trigger:
  `NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION`
- Status: satisfied by Approved ADR-089 at `1682dec0fb7f654e77888a476b4ec55a1507610b`.
- Package 8 requires no additional ADR.
- Historical FD-B12 Front Desk baseline before FD-C1: 483 tests / 1983 assertions / 0 failures / 0 errors
- GC-A1: 38 tests / 231 assertions
- GLF-D: 60 tests / 253 assertions
- BD-A1: 11 tests / 318 assertions
- NA-A1: 11 tests / 422 assertions
- NA-A2: 20 tests / 914 assertions
- GLF-E: 63 tests / 271 assertions / 0 failures / 0 errors
- GLF-E baseline: 49 tests / 194 assertions / 0 failures / 0 errors (historical predecessor evidence before GLF-E-S1 correction)
- RegressionBaselineManifestTest: 34 tests / 1104 assertions / 0 failures / 0 errors
- Complete active runner: 8 passed / 6 MISMATCH / 0 skipped
- MISMATCH classification: test-runner / DatabaseMigrations infrastructure exception; not FD-C1 source failure; not new accepted product debt
- Inventory Reversal inherited debt remains: 8 tests / 72 assertions / 2 accepted errors
- ADR-089 accepted and merged.
- ADR-089 architecture status: Approved.
- NA-A2 accepted and fast-forward merged.
- NA-A2 does not authorize checkout execution.
- GLF-E accepted and merged.
- GLF-E-S1 accepted and fast-forward merged at `f91621b58fe5743ed2a60980a70475cae40331bc`
- GLF-E savepoint lock-continuity defect corrected
- GC-A2 accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`
- GC-A2: 67 tests / 253 assertions / 0 failures / 0 errors
- FD-C1 accepted and merged through PR #36.
- FD-C1 merge commit: `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`
- FD-C1 feature head: `a05e9296578bc0672792f531240837f9149b583b`
- FD-C1: 7 commits, 10 changed files.
- Front Desk FD-C1 baseline: 534 tests / 4817 assertions / 0 failures / 0 errors
- NA-A2: 20 tests / 914 assertions / 0 failures / 0 errors
- GLF-E: 63 tests / 271 assertions / 0 failures / 0 errors
- GC-A2: 67 tests / 253 assertions / 0 failures / 0 errors
- FD-C1 introduced CheckedOut / CHECKED_OUT terminal stay state, immutable FrontDeskCheckoutExecution evidence foundation, front_desk_checkout_executions table, six named RESTRICT foreign keys, application-level and PostgreSQL immutability, property-scoped idempotency, and one successful terminal outcome per stay.
- FD-C1 is a Front Desk-owned foundation package only.
- FD-C1 does not execute checkout.
- FD-C1 does not expose a write route.
- FD-C1 does not create execute permission.
- FD-C1 does not register sensitive confirmation intent.
- FD-C1 does not create Housekeeping handoff/outbox.
- FD-C1 does not call GLF-E or GC-A2 as a final checkout command.
- FD-C1 does not set can_execute=true.
- FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation - accepted and merged through PR #38.
- FD-C2 feature head: `ce05c4217dcf763ccd5e308f66a01201975036a1`.
- FD-C2 merge commit: `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`.
- FD-C2 is foundation-only: dedicated checkout-to-Housekeeping handoff/outbox persistence, additive migration, minimized identifier-only payload, application and PostgreSQL integrity controls, pending/retryable delivery foundation, idempotent claim/delivery contract, focused PostgreSQL tests, source-integrity tests proving no checkout execution authority.
- Historical pre-Package-9 FD-C2 must not execute checkout, transition a stay to CHECKED_OUT, create FrontDeskCheckoutExecution in production, create a checkout orchestration service, create a final checkout command, add a checkout write route, create execute permission, create sensitive confirmation intent, update Housekeeping readiness, mutate rooms or Engineering, mutate PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date, Night Audit, Accounting, GL, AR, tax, revenue, or financial periods, create a worker that performs Housekeeping lifecycle mutation, create UI, change can_execute, remove CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED, generalize outbox_messages, implement Package 8, or implement Package 9.
- FD-C2 payload boundary: restricted to minimized server-owned identifiers and timestamps (property_id, front_desk_stay_id, reservation_id or canonical stay reference, checkout_execution_id, property_business_date_id where source-compatible, business_date, occurred_at, idempotency_key, correlation_key, source_hash or safe fingerprint, created_at). No guest PII, raw financial snapshots, payment data, cashier internals, Housekeeping readiness supplied by Front Desk, or Engineering payloads.
- Package 8 - Checkout Sensitive Confirmation, Durable One-Time Consumption, and Execute Permission Foundation - accepted and merged through PR #40.
- Package 8 merge commit: `2395884479a69dfa3a876728137676e61a7b374e`.
- Package 8 feature head: `eb20396ff3f42fc6f9273d3757ee80ab996b2b4d`.
- Package 8 final source provenance: `94a87dad7422f5a7f16656d34432428f0f4a7100`.
- Package 8 Front Desk baseline: 686 tests / 6679 assertions / 0 failures / 0 errors.
- Package 8 focused validation: 33 tests / 333 assertions / 0 failures / 0 errors.
- Complete active runner: 14 passed / 0 failed / 0 skipped.
- Inventory Reversal inherited debt unchanged: 8 tests / 72 assertions / 2 accepted errors.
- Package 8 introduces no new accepted debt.
- Package 8 source-proves exact execute permission `frontdesk.checkout-execution.execute`.
- Package 8 source-proves checkout confirmation intent `frontdesk-checkout-execution`.
- Package 8 public issuance and claim APIs are `issueForCurrentSession()` and `claimCurrentSessionConfirmationFor()` only; caller-built confirmation context is not a public authority boundary.
- Package 8 requires an active PostgreSQL transaction before claim stay resolution.
- Package 8 durable issuance and consumption evidence is immutable.
- Package 8 consumption is database-protected against duplicate issuance and duplicate checkout identity.
- Package 8 expiry is revalidated using PostgreSQL wall clock after row-lock acquisition.
- Failed or rolled-back checkout transactions roll back confirmation consumption.
- Same-idempotency committed replay consumes no new confirmation.
- Raw password and raw session ID are not persisted.
- Session cleanup remains non-authoritative.
- Package 8 creates no final checkout command.
- Package 8 creates no write route.
- Package 8 creates no checkout UI.
- Package 8 does not transition the stay.
- Package 8 does not create production checkout execution or production handoff records.
- Package 9 - Final Front Desk Checkout Command, Atomic Terminal Transaction Orchestration, Controlled Write Route, and Hospitality Interaction Layer - is accepted and merged through PR #42 at canonical merge commit `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`.
- Accepted Package 9 feature head and final metadata SHA: `df27dc8b7b33caf98ba2dd61305c652069780601`.
- Accepted Package 9 final source SHA: `77a82dd3951b7bb5804efb496b8939163ba2076d`.
- Package 9 current runtime truth: `PACKAGE_9_RUNTIME_ACCEPTED_AND_MERGED`, `CHECKOUT_EXECUTION_IMPLEMENTED`, `CAN_EXECUTE_SERVER_PROJECTED`.
- `CAN_EXECUTE_SERVER_PROJECTED` is not universally true. It is resolved server-side per actor, Company, Property, stay, Business Date, Night Audit, financial, cashier, final-review, permission, confirmation, and idempotency context. Browser input cannot grant execution authority.
- Package 9 accepted runtime implements the Front Desk-owned checkout execution command/orchestration, identifier-only execution request, controlled checkout write route, controller/request integration, exact execute authorization before stay resolution, non-disclosing unknown/cross-Property behavior after authorization, checkout-specific confirmation preflight, caller-owned PostgreSQL transaction, approved global lock ordering, NA-A2 transaction participation, GLF-E terminal financial attestation, GC-A2 terminal obligation attestation, durable Package 8 confirmation claim, immutable FD-C1 checkout execution evidence creation, terminal Front Desk stay transition to CHECKED_OUT, transactional FD-C2 Housekeeping handoff creation as PENDING, deterministic same-idempotency replay, post-commit non-authoritative session cleanup, controlled audit evidence, JSON and HTML/Inertia committed receipt, and final Human Designed Hospitality interaction layer.
- Package 9 accepted runtime remains one bounded Front Desk package. It does not silently add unrelated departure, folio, cashier, Night Audit, Housekeeping, Engineering, accounting, reporting, or workflow redesign.
- Package 9 accepted validation evidence:
  - Scenario I focused: 3 tests / 130 assertions / 0 failures / 0 errors.
  - Package 9 isolated concurrency: 15 tests / 417 assertions / 0 failures / 0 errors.
  - Package 9 focused final batch: 41 tests / 708 assertions / 0 failures / 0 errors.
  - Package 8 confirmation: 33 tests / 346 assertions / 0 failures / 0 errors.
  - Adjacent NA-A2 + GLF-E + registered GC-A2: 150 tests / 1447 assertions / 0 failures / 0 errors.
  - Exact Front Desk baseline: 68 classes, 729 tests / 5539 assertions / 0 failures / 0 errors, exit code 0.
  - RegressionBaselineManifestTest: 34 tests / 1150 assertions / 0 failures / 0 errors.
  - Complete active baseline runner: 14 passed / 0 failed / 0 skipped, 1205 tests / 9378 assertions, exit code 0.
  - Inventory Reversal accepted inherited debt: 8 tests / 72 assertions / 2 accepted errors.
- Package 9 source determination:

```text
PACKAGE_9_RUNTIME_ACCEPTED_AND_MERGED
CHECKOUT_EXECUTION_IMPLEMENTED
CAN_EXECUTE_SERVER_PROJECTED
NO_NEW_ADR_REQUIRED
ADR_087_AND_ADR_089_REMAIN_GOVERNING
```

- Package 9 execution browser request contract is identifier-only:

```json
{
  "front_desk_stay_id": "ULID",
  "idempotency_key": "opaque client-generated key"
}
```

- Browser input must not control Company, Property, Tenant, actor, role, permission, membership, guest, reservation, room, stay status, business date, Night Audit status, folio balance, amount, currency, payment result, settlement result, cashier result, attestation object, source fingerprint, confirmation identity, confirmation fingerprint, consumption ID, audit timestamp, execution status, Housekeeping readiness, handoff payload, or retry outcome.
- Authorization and confirmation ordering is current runtime truth: resolve actor, company, property, and membership; authorize exactly `frontdesk.checkout-execution.execute`; only then resolve the submitted stay in the current Property; preserve non-disclosing 404; normalize idempotency; return immutable committed replay for the same authoritative identity without a new confirmation; for new mutation attempts perform checkout-specific confirmation preflight; then enter the controlled PostgreSQL transaction.
- Boundary-view permission, confirmation, broad administration, Finance roles, Cashier roles, Night Audit roles, Housekeeping roles, Engineering roles, Banking roles, GL roles, AR roles, tax roles, and revenue roles do not imply checkout execution permission.
- Terminal transaction order is frozen as Property row, Property Business Date row, Front Desk stay row, checkout idempotency/execution identity, Night Audit active-run scope through NA-A2, PMS terminal financial attestation through GLF-E, General Cashier terminal obligation attestation through GC-A2, final Package 8 confirmation revalidation, durable confirmation claim, immutable FD-C1 execution evidence, terminal stay transition, FD-C2 transactional Housekeeping handoff, commit, then idempotent session confirmation cleanup.
- No earlier global lock may be acquired after a later global lock. No external HTTP/API call may occur inside the transaction.
- Idempotency and replay contract is frozen: `property_id + idempotency_key` permits at most one committed checkout outcome; one stay permits at most one successful terminal checkout; same key plus same authoritative identity returns immutable committed evidence without a new confirmation, new consumption, new evidence, new handoff, or second stay transition; conflicting authoritative identity fails closed; rollback may reuse confirmation only when consumption rolled back and confirmation remains unexpired; response loss after commit is recovered through immutable evidence replay; handoff delivery retry must never re-run checkout.
- Failure and concurrency behavior is frozen: authorization failure before stay query; unknown/cross-Property stay after authorization returns non-disclosing 404; invalid confirmation preflight creates no terminal transaction; Night Audit, Business Date, Front Desk readiness, GLF-E, GC-A2, expiry, consumed confirmation conflict, lock timeout, deadlock, serialization, duplicate checkout, and rollback outcomes must fail closed with bounded retry only where source-compatible and with no partial stay closure, execution evidence, durable consumption, handoff, or foreign-domain repair after rollback.
- Package 9 runtime must provide isolated PostgreSQL concurrency proof for same idempotency concurrent execution, different idempotency keys for the same stay, Night Audit start versus checkout, confirmation expiry while waiting, rollback before mutation, response-loss replay, different Properties not unnecessarily serializing, and deadlock/serialization bounded retry when source-compatible.
- Front Desk owns command, orchestration, terminal stay transition, execution evidence, idempotency outcome, and handoff creation. PMS Guest Ledger and PMS Cashiering own financial and payment lifecycle facts. General Cashier owns cashier accountability lifecycle. Business Date and Night Audit own operational date/run lifecycle and lock evidence. Housekeeping owns room-turnover lifecycle. Engineering is not a mandatory checkout gate. Accounting owns GL, AR, tax, revenue, and financial-period outcomes. Finance governs and consumes outcomes but does not own operational checkout execution.
- Front Desk may invoke accepted participating ports but must not directly insert, update, or delete foreign-domain lifecycle rows, including folios, folio items, payment allocations, payment transactions, deposits, refunds, payment reversals, AR transfers, cashier sessions, cashier counts, cashier handovers, cashier reconciliation, Property Business Date lifecycle, Night Audit run/checkpoint lifecycle, Housekeeping readiness, room readiness, Engineering availability, GL journals, tax, revenue, or financial periods.
- Package 9 final interaction layer is accepted under ADR-040 as a hospitality operational workspace, not generic CRUD/AdminLTE/long-sidebar UI, with server-projected eligibility, explicit sensitive confirmation, clear blockers, intentional final execution, disabled/hidden unauthorized execution action, response-loss-safe replay display, immutable success receipt, pending Housekeeping handoff status without claiming readiness changed, accessibility, and no optimistic terminal success before server commit.
- Historical pre-Package-9 markers are retired as current-state statements: "Package 9 runtime remains unimplemented", "checkout execution remains unauthorized", "can_execute=false" as a universal runtime state, `CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED` as a current runtime marker, and "Package 9 is the current authorized governance boundary". Those strings may remain only where explicitly labeled historical pre-Package-9 evidence.
- Full access does not bypass later packages.
### Historical Version 1.17 Package 11 authorization context

- At the Version 1.17 authorization point, Package 11 - Housekeeping Checkout Handoff Consumption and Room Turnover Start - was the next separately delivered runtime boundary.
- Package 11 source determination:

```text
PACKAGE_9_RUNTIME_ACCEPTED_AND_MERGED
PACKAGE_11_GOVERNANCE_ACTIVATION_AUTHORIZED
PACKAGE_11_RUNTIME_REQUIRES_SEPARATE_DRAFT_PR
NO_NEW_ADR_REQUIRED
ADR_086_AMENDMENT_REQUIRED_AND_INCLUDED
ADR_040_ADR_086_ADR_087_ADR_089_REMAIN_GOVERNING
```

- Package 11 is not a continuation of Front Desk checkout execution. It is a Housekeeping-owned downstream consumption package for checkout-specific FD-C2 handoffs.
- Historical Version 1.17 source determination: no canonical durable idempotent checkout-turnover intake target existed at that point. `CleaningTaskService::generateDepartureTask()` directly created a `checkout_cleaning` task without accepted durable source-identity, source-hash, checkout-execution, handoff, or idempotency protection. A `CleaningTask` row alone was not accepted as the recovery identity unless the Package 11 runtime hardened it with the required source identity, uniqueness, immutability, and replay contract.
- A later Package 11 runtime PR may implement only a Housekeeping-owned consumer for checkout-specific FD-C2 handoffs; Property-scoped claim of an eligible PENDING or retry-due FAILED handoff; authoritative server-side re-resolution of Property, checkout execution, Front Desk stay, reservation relationship, authoritative room, and Business Date evidence where source-compatible; verification of immutable checkout evidence and handoff source integrity; creation or idempotent replay of one mandatory Housekeeping-owned checkout-turnover intake identity and one correlated Housekeeping room-turnover outcome through the canonical Housekeeping lifecycle; exact source identity preventing duplicate Housekeeping outcomes; markDelivered only after the Housekeeping-owned intake and outcome are committed or proven as an idempotent replay; markFailed with controlled retry evidence after bounded failure; crash recovery where the Housekeeping commit completed but the FD-C2 handoff was not yet marked DELIVERED; retry without repeating checkout; audit evidence; bounded internal worker or controlled command; focused PostgreSQL concurrency proof; and Housekeeping operational workspace integration only when separately included in the Package 11 runtime scope and supported by ADR-040. Package 11 must not create a parallel generic workflow framework.
- Historical Version 1.17 inventory: Package 11 was required to use the canonical readiness states `dirty`, `waiting_cleaning`, `cleaning`, `waiting_inspection`, `ready_for_sale`, `ready_for_arrival`, `ready_for_vip`, and `blocked`. Canonical cleanliness statuses were `dirty`, `clean`, and `inspected`; readiness projections were `HOUSEKEEPING_READY`, `HOUSEKEEPING_BLOCKED`, and `HOUSEKEEPING_UNKNOWN`. At that historical point, source-proven transition types were only `START_CLEANING`, `SUBMIT_INSPECTION`, and `RELEASE_READY`; no checkout-turnover intake transition type existed yet. Package 11 was authorized to add a narrowly governed checkout-turnover intake transition through its separate runtime PR.
- Package 11 required semantics: a checkout handoff is delivery evidence and a source reference, not Housekeeping readiness and not authority to bypass Housekeeping services; only Housekeeping may change cleanliness or readiness; Front Desk must never mutate Housekeeping room readiness or cleanliness; Housekeeping independently resolves the authoritative room and current lifecycle state; matching existing dirty/waiting-cleaning intake may replay; contradictory active cleaning, inspection, or blocked evidence must fail closed or enter an explicitly controlled Housekeeping exception path; Package 11 must not silently overwrite an active Housekeeping lifecycle; handoff delivery does not mean room READY; at-least-once handoff delivery must produce at-most-one Housekeeping-owned turnover outcome; duplicate worker delivery must resolve the same intake and same task/outcome; task creation and readiness mutation must be correlated to the durable intake identity; a crash after Housekeeping commit but before handoff DELIVERED must be recoverable through the intake identity without duplicate turnover creation; a failed Housekeeping mutation must not mark the handoff DELIVERED; FAILED status may be set only through the controlled FD-C2 delivery contract with a bounded privacy-safe error code and retry time; Package 11 must not rerun checkout, consume another checkout confirmation, create another checkout execution, or transition the stay again; Package 11 must not mutate PMS Guest Ledger, PMS Cashiering, General Cashier, Business Date, Night Audit, Front Desk checkout evidence, Front Desk stay status, Engineering lifecycle, Accounting, GL, AR, tax, revenue, or financial periods; browser input must not control Property, checkout identity, room identity, reservation relationship, readiness outcome, delivery status, retry status, source fingerprint, actor identity, or audit evidence; and no external HTTP/API call may be required for the atomic Housekeeping-owned outcome.
- Package 11 concurrency proof must use real isolated PostgreSQL processes and prove: two workers claim the same PENDING handoff with exactly one valid claim winner; duplicate delivery after Housekeeping intake and outcome commit replays the same intake and same Housekeeping outcome; crash or response loss after Housekeeping commit but before markDelivered creates no duplicate; expired and stale claims cannot mark delivered or failed; failed Housekeeping mutation preserves retryability and creates no partial target outcome; different Properties do not unnecessarily serialize; cross-Property handoff access is non-disclosing and causes zero Housekeeping mutation; malformed or contradictory checkout/handoff source evidence fails closed; and delivery replay never reruns Package 9 checkout.
- Package 11 interaction-layer scope, if separately included, must follow ADR-040: hospitality operational workspace, no generic CRUD/AdminLTE/long-sidebar redesign, server-projected checkout-turnover queue, clear FD-C2 PENDING/CLAIMED/FAILED/DELIVERED handoff state presentation, source-proven Housekeeping operational state presentation, visible source checkout receipt, room and departure context, retry and exception visibility, no claim that the room is clean or ready merely because the handoff was delivered, authorization-aware actions, no optimistic success before server commit, accessibility, privacy-safe evidence, and supervisory exception review.

### Historical Package 14 accepted predecessor snapshot (2026-08-03)

- Canonical branch: `ivorq-enterprise-core`.
- Canonical synchronization SHA: `9e21f2e3f40438beb6727d9b6c19af4feb53697a`.
- Package 11 is accepted and merged through PR #44. Accepted feature head: `c429b2b7409cb1e8f1062ad9431b62217a2758f3`. Canonical merge commit: `39b673109109d28e140b67f3835696836401a9e4`.
- Package 11 established Housekeeping-owned FD-C2 handoff consumption, durable checkout-turnover intake, exactly-once correlated checkout-cleaning outcome, source-bound replay and crash recovery, and no Front Desk mutation of Housekeeping readiness.
- Package 12 is accepted and merged through PR #45. Accepted feature head: `494b9ceaedf8573f09fe685a2dd899bb32dd6bd1`. Canonical merge commit: `0fa4e14f0c791105d31964d9e4ebebd95fda0345`.
- Package 12 established the current-Property-scoped, deterministic, read-only checkout-turnover operational workspace without lifecycle write authority.
- Package 13 is accepted and merged through PR #46. Accepted feature head: `70f6f0735d31bb573526649ed283237a742b2f7c`. Canonical merge commit: `9e21f2e3f40438beb6727d9b6c19af4feb53697a`.
- Package 13 established canonical Cleaning Task start and completion integration, one post-cleaning Inspection, controlled Inspection conduct, sensitive-confirmed Room release, `INSPECTION_FAILED` with exactly one source-bound re-cleaning outcome, PostgreSQL source relationship and immutability enforcement, exact replay, and real concurrency proof.
- Current source-supported readiness states: `dirty`, `waiting_cleaning`, `cleaning`, `waiting_inspection`, `ready_for_sale`, `ready_for_arrival`, `ready_for_vip`, and `blocked`.
- Current source-supported cleanliness statuses: `dirty`, `clean`, and `inspected`.
- Current source-supported transition types: `CHECKOUT_TURNOVER_INTAKE`, `START_CLEANING`, `SUBMIT_INSPECTION`, `RELEASE_READY`, and `INSPECTION_FAILED`.
- Package 11 through Package 13 collectively established:

```text
FD-C2 checkout handoff
→ durable Housekeeping turnover intake
→ checkout-cleaning task
→ operational turnover workspace
→ assigned cleaning lifecycle
→ pending post-cleaning Inspection
→ pass/release-ready or fail/re-cleaning outcome
```

- This accepted lifecycle does not make task assignment canonical. The remaining dispatch and assignment path is legacy and requires a separate runtime package.
- Accepted dated validation snapshot from Package 13 evidence: Package 13 exact batch 31 tests / 334 assertions; Housekeeping active baseline 147 tests / 2,236 assertions; Package 13 migration proof 1 test / 42 assertions; Package 13 isolated concurrency 1 test / 93 assertions; manifest validation 36 tests / 1,267 assertions; complete active registry 14 passed / 0 failed / 0 skipped; complete total 1,289 tests / 11,473 assertions.
- Inventory Reversal inherited debt remains unchanged at 8 tests / 72 assertions / exactly 2 documented errors. Package 11 through Package 13 introduced no new accepted debt.
- At this historical snapshot, Package 14 was the current governance-only synchronization package.
- At this historical snapshot, Package 15 - `PACKAGE_15_CONTROLLED_HOUSEKEEPING_TURNOVER_DISPATCH_AND_ASSIGNMENT` - was the proposed next runtime package and remained locked until Package 14 was independently reviewed, Owner-authorized, and merged.

### Package 16 current accepted predecessor snapshot (2026-08-11)

- Canonical branch: `ivorq-enterprise-core`.
- Canonical synchronization SHA: `29731a60afc16ab4b50291cc06b00e67011e92f7`.
- Package 14 governance synchronization is accepted and merged through PR #47 at `2a88895dd6ab9b14cd94ee7b928636068ecf5d6f`.
- Package 15 is accepted and merged through PR #49. Accepted feature head: `fdf6036d70a85e9c7283f174c205fdef29bcbefe`. Canonical merge commit: `29731a60afc16ab4b50291cc06b00e67011e92f7`.
- Package 15 Version 1.19 evidence retains the complete accepted provenance: original source `a50657e7c6472336939bcbaea5a21060b1a6fcc7`; original metadata `c13485ab4bc4bcde24fb6067512d5505111a1edf`; re-anchor `5e81983ab8443e5903349426c4835c356ba495fe`; concurrency stabilization `27123995e635ae5052dde5aacfa867768a13103d`; Front Desk cross-baseline metadata `fdf6036d70a85e9c7283f174c205fdef29bcbefe`; canonical merge `29731a60afc16ab4b50291cc06b00e67011e92f7`.
- Package 15 established one canonical Housekeeping command for controlled initial assignment and pre-start reassignment, exactly one active assignment per Cleaning Task, immutable prior assignment history, deterministic Property-scoped idempotency and replay, current-Property attendant and Department eligibility, server-owned assignment evidence, audit evidence, attendant workload projection, bounded turnover-workspace integration, PostgreSQL integrity, and real-worker concurrency proof.
- Package 15 did not implement cleaner/inspector segregation, Inspection claim idempotency, Inspection takeover, automatic dispatch, another domain's lifecycle mutation, a scheduler, a queue, an external integration, or a new ADR.
- Accepted Package 15 validation evidence: Package 15 exact suite 17 tests / 497 assertions; Housekeeping active baseline 22 classes / 164 tests / 2,733 assertions / 0 failures / 0 errors; Front Desk active baseline 68 classes / 729 tests / 5,639 assertions / 0 failures / 0 errors; manifest validation 36 tests / 1,307 assertions; complete active registry 14 passed / 0 failed / 0 skipped and 1,306 tests / 11,994 assertions; frontend production build passed.
- Inventory Reversal inherited debt remains unchanged at 8 tests / 72 assertions / exactly 2 documented errors. Package 15 introduced no new accepted debt.
- Current source creates exactly one pending post-cleaning Inspection per completed checkout-cleaning task and records the cleaner in `CleaningTask.completed_by`.
- Current `conductInspection()` changes a pending Inspection to `in_progress` and records the acting User in `RoomInspection.supervisor_id`, but there is no durable claim idempotency identity, source hash, claim timestamp, claim audit contract, or dedicated claim command.
- Current authorization permits any current-Property User with `housekeeping.inspection.conduct` to conduct the Inspection; it does not compare the claimant with `CleaningTask.completed_by`.
- Current pass and fail commands do not require the acting User to equal the recorded `supervisor_id`, so the current source does not prove one claimant owns the terminal Inspection decision.
- The deferred maker-checker policy is now governance-resolved as a future identity-segregation rule: the cleaner recorded in `CleaningTask.completed_by` must not claim or decide that task's post-cleaning Inspection, and the accepted Inspection claimant must own the pass/fail action. Runtime enforcement remains absent until Package 17 is independently implemented, reviewed, and merged.
- Package 16 is the current governance-only synchronization package and makes no runtime change.
- Package 17 - `PACKAGE_17_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_AND_SEGREGATION` - is the proposed next runtime package. Its runtime remains locked until Package 16 is independently reviewed, Owner-authorized, and merged.

### Historical Package 18 accepted predecessor snapshot (2026-08-13)

- Canonical branch: `ivorq-enterprise-core`.
- Canonical synchronization SHA: `37750626f9e0614d26d628a4707bcb205508ae03`.
- Package 16 governance synchronization is accepted and merged. Its accepted governance correction is `bd42d6d25a078b60c1a8ba09e949b3f7c532c397`, and its canonical merge is `a8dd9ffdea4f09d1c223d6db9e43def756cc5682`.
- Package 17 is accepted and merged through PR #51. Accepted feature HEAD: `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`. Canonical merge commit: `37750626f9e0614d26d628a4707bcb205508ae03`.
- Package 17 append-only provenance is preserved without collapsing the accepted history into one inaccurate source SHA: original source `20112b623d04c50655e8701566c1dbd156e6dc53`; original metadata `de3e131c091f02fbb70cabb41006accecb0ce1bd`; legacy/replay correction `0120b793a1e10f21ae4b6a235e9e75591b792ee4`; Package 13 concurrency fixture alignment `86a3b9e242bbf427353e07131c42f69d983df6e9`; corrected metadata `40a6b3959411fd6d4a347e03d617905fc7ad9d5f`; PostgreSQL bypass closure `3055610ebd714f592fe395926a180743a5e945d1`; Foundation legacy proof alignment `b45bba591e32963c2bbe7e03a82cc9f997a5d6c1`; Package 13 canonical claim fixture `55399a7c53dc9c5f099ee4570ec1bc1bb6fd757b`; Package 13 successor migration isolation `98ccdeb9be1b9bc60b2df9cda2d31bbe9aed4a59`; final metadata/HEAD `0a1e2a1eb9f4882ad05e3966604b8b36fa262fb4`; canonical merge `37750626f9e0614d26d628a4707bcb205508ae03`.
- Package 17 establishes one canonical Housekeeping-owned post-cleaning Inspection claim, server-resolved claimant identity in `RoomInspection.supervisor_id`, deterministic Property-scoped idempotency, source hash, server claim timestamp, evidence version, immutable claim identity, completed-cleaner/claimant segregation, claimant-owned terminal pass/fail decisions, PostgreSQL bypass protection, and historical Package 13 compatibility without fabricated Package 17 evidence.
- A Package 17 claimant cannot be silently replaced. Terminal decision requires the effective recorded claimant. No claim expiry, release, abandonment, reassignment, takeover, emergency recovery, or alternate effective claimant exists in current runtime.
- An `in_progress` Inspection can therefore become operationally stuck if its recorded claimant later becomes objectively ineligible through inactive/deleted User state, inactive/missing current-Property membership, or loss of `housekeeping.inspection.conduct`.
- Current source already seeds `housekeeping.inspection.approve`, but `RoomInspectionPolicy` does not use it as controlled claim-recovery or reassignment authority. Ordinary claimant eligibility remains `housekeeping.inspection.conduct`.
- Accepted Package 17 validation evidence: Front Desk active baseline 68 classes / 729 tests / 5,657 assertions / 0 failures / 0 errors; Housekeeping active baseline 28 classes / 191 tests / 3,539 assertions / 0 failures / 0 errors. The Package 17 full registry aggregate exit code was not captured; this limitation remains disclosed and is not rewritten as captured evidence.
- Inventory Reversal inherited debt remains unchanged at 8 tests / 72 assertions / 0 failures / exactly 2 documented errors. Package 17 introduced no new accepted debt.
- Package 18 - `PACKAGE_18_POST_PACKAGE_17_HOUSEKEEPING_GOVERNANCE_SYNCHRONIZATION` - is the current governance-only synchronization package and makes no runtime change.
- Package 19 - `PACKAGE_19_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_RECOVERY_AND_REASSIGNMENT` - is the proposed next runtime boundary. It remains locked pending Package 18 independent review, explicit Owner authorization, and merge.
- ADR-086 remains the Housekeeping Inspection/readiness/maker-checker owner, ADR-040 remains the controlled interaction owner, and ADR-066 remains the sensitive-confirmation architecture. `NO_NEW_ADR_REQUIRED`.

### Package 20 current accepted predecessor snapshot (2026-08-14)

- Canonical branch: `ivorq-enterprise-core`.
- Canonical synchronization SHA: `086deefca673af57776fcaa14e06494c2f16ab4d`.
- Package 18 governance synchronization is accepted and merged through PR #52 at canonical merge `a99f4b20489c3259c416297310a7b02f9cb6dacb`.
- Package 19 is accepted and merged through PR #53. Accepted feature HEAD: `9bd18634e603ee7e545798dd7ddf913407e2a685`. Canonical merge commit: `086deefca673af57776fcaa14e06494c2f16ab4d`.
- Package 19 append-only provenance is original source `3f05283dc878c9ec098ba0e27b319451abda36ad`; original metadata `88750a9a23067d1630d0bf151510f0a94083f546`; deterministic timestamp/PostgreSQL correction `a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50`; corrected metadata/final feature HEAD `9bd18634e603ee7e545798dd7ddf913407e2a685`; canonical merge `086deefca673af57776fcaa14e06494c2f16ab4d`.
- Package 19 provides one append-only claim-reassignment aggregate while preserving the immutable original Package 17 claim; effective replacement claimant semantics; objective original-claimant ineligibility; exact intervention permission `housekeeping.inspection.approve`; replacement eligibility permission `housekeeping.inspection.conduct`; one reassignment maximum; dedicated Sensitive Action Confirmation; PostgreSQL integrity; deterministic `occurred_at` / `created_at` evidence; exact replay; and real concurrency proof.
- Package 19 validation-process deviation remains disclosed exactly: initial corrected-head complete-registry execution exposed one transient Front Desk Scenario H worker-marker timeout. The exact failing class subsequently passed twice at 14 tests / 383 assertions. An additional clean registry retry was performed WITHOUT prior independent rerun authorization. That additional run passed 14/14 targets, 1351 tests, 13108 assertions, 0 failures, exactly 2 registered inherited errors, 0 skipped, exit code 0. Independent review accepted the retry as a disclosed validation-process deviation, not source-correction evidence.
- Package 20 - `PACKAGE_20_POST_PACKAGE_19_HOUSEKEEPING_FINAL_GOVERNANCE_CLOSURE` - is the current governance-only final closure package and makes no runtime change.
- `HOUSEKEEPING_TURNOVER_READINESS_PACKAGE_TRAIN = CLOSURE_PENDING_PACKAGE_20_REVIEW_AND_MERGE`.
- `NO_PACKAGE_21_ACTIVATED`. Package 20 does not authorize another runtime package. Future Housekeeping work requires fresh source review and explicit Owner authorization.
- ADR-086 remains the Housekeeping Inspection/readiness/maker-checker owner, ADR-040 remains the controlled interaction owner, and ADR-066 remains the sensitive-confirmation architecture. `NO_NEW_ADR_REQUIRED`.

## 6. Package sequencing model

Packages must run sequentially. One package may depend on earlier package evidence, but must not silently implement later package behavior.

### Version 1.22 current Cost Control authorization snapshot (2026-08-15)

- Canonical branch: `ivorq-enterprise-core`.
- Current canonical SHA: `bc17d38060f2cdf91c45049aa593e358ae7e1c4c`.
- The Master Domain Registry and `CC-R1_COST_CONTROL_CANONICAL_REVALIDATION_AND_BOUNDARY_AUDIT` are accepted and merged through PR #55.
- CC-R1 provenance is preserved as original audit `cf8d1c840497d476362ca72925dfbfa3bba7ce1f`, provenance correction/final feature HEAD `c368a0b93aaca7b963c9ba076268a327d93caf6b`, and canonical merge `bc17d38060f2cdf91c45049aa593e358ae7e1c4c`.
- `CC-G1_COST_CONTROL_OWNERSHIP_ACTIVATION_AND_LEDGER_PRECEDENCE_FREEZE` is the current explicitly authorized package.
- Package type: `GOVERNANCE_ONLY`.
- CC-G1 may synchronize only the exact governance documents and executable Contract-version guards named by its Owner authorization.
- CC-G1 freezes current ownership, activation, Inventory/Cost Ledger source precedence, legacy/enrolled AVCO authority, synchronous/future-deferred delivery precedence, dependency direction, negative-stock scope, reversal/correction scope, bounded GRNI/AP scope, Business Date/Financial Period controls, and analytical-workspace-versus-durable-write ownership.
- CC-G1 creates no runtime, migration, model, service, controller, request, policy, route, permission, Sensitive Action intent, React/TypeScript, queue, worker, deferred consumer, GL/AP/Procurement runtime, or new ADR.
- `CC-P01`, `INV-R1`, `FIN-R1`, and `PROC-R1` are not activated.
- `NO_PACKAGE_21_ACTIVATED`.

### Current controlled sequence

1. ADR-089 — accepted, Approved, merged
2. NA-A2 — accepted, merged
3. GLF-E original package — accepted, merged
4. GLF-E-S1 savepoint lock-continuity correction — accepted, merged
5. GC-A2 General Cashier terminal obligation attestation — accepted, merged
6. FD-C1 Front Desk terminal checkout state and immutable checkout execution evidence foundation — accepted, merged through PR #36
7. FD-C2 Transactional Housekeeping Checkout Handoff / Outbox Foundation — accepted and merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2`
8. Package 8 - accepted and merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e`
9. Package 9 - accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`
10. Package 10 - governance synchronization accepted and merged
11. Package 11 - accepted and merged through PR #44 at `39b673109109d28e140b67f3835696836401a9e4`
12. Package 12 - accepted and merged through PR #45 at `0fa4e14f0c791105d31964d9e4ebebd95fda0345`
13. Package 13 - accepted and merged through PR #46 at `9e21f2e3f40438beb6727d9b6c19af4feb53697a`
14. Package 14 - governance synchronization accepted and merged through PR #47 at `2a88895dd6ab9b14cd94ee7b928636068ecf5d6f`
15. Package 15 - `PACKAGE_15_CONTROLLED_HOUSEKEEPING_TURNOVER_DISPATCH_AND_ASSIGNMENT` - accepted and merged through PR #49 at `29731a60afc16ab4b50291cc06b00e67011e92f7`
16. Package 16 - `PACKAGE_16_POST_PACKAGE_15_HOUSEKEEPING_GOVERNANCE_SYNCHRONIZATION` - accepted governance synchronization, merged at `a8dd9ffdea4f09d1c223d6db9e43def756cc5682`
17. Package 17 - `PACKAGE_17_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_AND_SEGREGATION` - accepted runtime through PR #51 at `37750626f9e0614d26d628a4707bcb205508ae03`
18. Package 18 - `PACKAGE_18_POST_PACKAGE_17_HOUSEKEEPING_GOVERNANCE_SYNCHRONIZATION` - accepted and merged through PR #52 at `a99f4b20489c3259c416297310a7b02f9cb6dacb`
19. Package 19 - `PACKAGE_19_CONTROLLED_HOUSEKEEPING_INSPECTION_CLAIM_RECOVERY_AND_REASSIGNMENT` - accepted and merged through PR #53 at `086deefca673af57776fcaa14e06494c2f16ab4d`
20. Package 20 - `PACKAGE_20_POST_PACKAGE_19_HOUSEKEEPING_FINAL_GOVERNANCE_CLOSURE` - current governance-only final closure

Packages 8 through 19 are accepted and canonical. `PACKAGE_18 = ACCEPTED_AND_MERGED`. `PACKAGE_19 = ACCEPTED_AND_MERGED`. `PACKAGE_20 = CURRENT_GOVERNANCE_FINAL_CLOSURE`. `HOUSEKEEPING_TURNOVER_READINESS_PACKAGE_TRAIN = CLOSURE_PENDING_PACKAGE_20_REVIEW_AND_MERGE`. `NO_PACKAGE_21_ACTIVATED`. Package 20 makes no runtime change and does not authorize another runtime package.

Only an explicitly Owner-authorized package may start after its predecessor is reviewed, accepted, and merged into the canonical branch. BD-A1 is accepted as the authoritative Property Business Date foundation; NA-A1 is accepted as the authoritative Night Audit run and active close-lock foundation. FD-B13 historical pre-Package-9 evidence records `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES`; the historical `NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION` trigger is satisfied by Approved ADR-089 at `1682dec0fb7f654e77888a476b4ec55a1507610b`. ADR-089 is accepted, Approved, and merged. NA-A2 is accepted and merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 is accepted and fast-forward merged at `f91621b58fe5743ed2a60980a70475cae40331bc`. GC-A2 is accepted and true fast-forward merged at `f0635b6c402ea095a1cd21b1a1510008c49e7739`. FD-C1 is accepted and merged through PR #36 at `233b2407dd3c77e86a007b77e9572d2c0d0ea36e`. FD-C2 is accepted and merged through PR #38 at `13bff99e67d95ef5fbf8bdf2e69bdbbfd3e12ed2` with accepted feature head `ce05c4217dcf763ccd5e308f66a01201975036a1`. Package 8 is accepted and merged through PR #40 at `2395884479a69dfa3a876728137676e61a7b374e` with accepted feature head `eb20396ff3f42fc6f9273d3757ee80ab996b2b4d`. Package 9 is accepted and merged through PR #42 at `43ad08969e36b1ddc65b0a7227a86d02e2e1a27a`, with accepted feature/metadata SHA `df27dc8b7b33caf98ba2dd61305c652069780601` and accepted final source SHA `77a82dd3951b7bb5804efb496b8939163ba2076d`. Current checkout execution is implemented and server-projected, not browser-granted. Package 11 through Package 19 are accepted and canonical at their exact merge identities above. Package 20 is the current governance-only final closure boundary. No Package 21 identity, readiness package, or runtime is activated. No AI agent may interpret Full Access as permission to skip package sequencing, review, Draft PR, merge, or default-branch boundaries. Business Date and Night Audit must never absorb source-domain ownership. Package implementation remains sequential.

### A. Domain-owned package

A package owned by one domain only.
- May read accepted upstream evidence.
- May create own evidence/state.
- Must not mutate another domain.
- Must not perform later lifecycle actions.
- Must add focused tests.
- Must update active baseline only when accepted package tests are added.
- Must end in Draft PR.

### B. Cross-domain handoff package

A package that creates or consumes controlled handoff between domains.
- Requires explicit owner/domain boundary.
- Requires source-proven upstream evidence.
- Requires server-side re-resolution and independent revalidation.
- Must not allow browser-controlled trusted state.
- Must not mutate target domain lifecycle unless that package explicitly owns the lifecycle action.

### C. Finance/accounting package

- Finance retains financial-period close, GL, tax, revenue recognition, AR, payment, bank reconciliation, and accounting ownership.
- Business Date / Night Audit owns its own operational business-date lifecycle and orchestration evidence.
- Business Date / Night Audit may read source-domain attestations but may not mutate those domains without a separately authorized owner-domain command.
- Only owner-domain packages may mutate payment, cashier, folio, settlement, GL, AR, tax, revenue, invoice, financial-period, Business Date, or Night Audit state.
- Front Desk packages may display or record operational markers only when explicitly authorized.
- Front Desk remains a read-only consumer of Business Date / Night Audit, cashier, settlement, folio, payment, tax, revenue, GL, AR, and accounting evidence until a separately authorized checkout command exists.
- Financial marker must be present where financial settlement is intentionally not evaluated.

### D. Audit/security gate package

- Audit/security review is not saved until the end of all work.
- Each package must have its own audit/security gate before commit.
- Security/audit findings block the package before PR.

### E. Candidate baseline package

- Candidate baselines are diagnostic only.
- Do not promote candidates unless Owner explicitly authorizes.
- Candidate failure does not block active package unless current package directly changed that candidate scope.

## 7. Dependency rules

- Package N output becomes context for Package N+1 only after PR N is reviewed, accepted, and merged.
- Draft PR is the stop point.
- Merge is a separate controlled task.
- No package may start while prior PR is unreviewed unless Owner explicitly authorizes parallel work.
- No package may assume unmerged branch content is accepted baseline.
- Each package prompt must state current accepted default SHA.

## 8. Safe role boundaries per package

### Product/domain role
- Implements only the requested business outcome.
- Uses hospitality naming.
- Avoids generic CRUD.

### Architecture role
- Checks ADR/source compatibility.
- Stops if boundary is unclear.

### Security role
- Checks authorization, policy, permission, property isolation, secret handling, ownership segregation.

### Audit role
- Checks append-only evidence, source hash/snapshot, actor, occurred_at, idempotency, immutable records where required.

### Validation role
- Runs focused tests first.
- Runs baseline gates only as required.
- Distinguishes active baselines from candidate diagnostics.

### Git role
- Exact-path staging only (`git add -- <path>`).
- Feature branch push allowed when task explicitly authorizes it.
- Draft PR creation allowed when validation passes.
- Default branch push only in separate controlled merge task.

## 9. Migration safety rules

- Additive migrations only by default.
- No drop/rename/destructive column changes unless Owner explicitly authorizes.
- New non-nullable columns require safe default or backfill strategy.
- Prefer nullable-first rollout when existing data may exist.
- Foreign keys/indexes/unique constraints must be compatible with current data.
- DB triggers for immutability must be tested.
- No `migrate:fresh`.
- No destructive seed.
- No raw production-like DML.
- No `TRUNCATE`.
- No broad data mutation to make tests pass.

## 10. Browser-input and server-resolution rules

- Browser may submit identifiers and user-entered notes/status only when authorized.
- Browser must not control property, tenant, reservation, guest, room, actor, amount, currency, posting state, audit fields, or source snapshots.
- Server must first resolve the authenticated actor and authoritative current Company / Property context.
- Exact package authorization must then be evaluated at the ordering required by the package contract.
- Package 9 authorization before stay resolution is mandatory.
- For Package 9 checkout execution, `frontdesk.checkout-execution.execute` authorization occurs before any Front Desk stay query.
- Only after execute authorization succeeds may the server resolve the submitted stay identifier, scoped to the authoritative current Property.
- An unauthorized actor causes no stay query.
- Unknown or cross-Property stay remains non-disclosing only after authorization succeeds.
- Upstream evidence and owner-domain relationships are independently re-resolved and revalidated after authorization and at the transaction stages required by the governing ADR.
- No generic server-side resolution rule may be interpreted as permission to query the target stay before execute authorization.
- Amount/currency must derive server-side where relevant.

## 11. Definition of Done per package

A package is done only when:

- [ ] Scope implemented exactly.
- [ ] Explicit non-goals preserved.
- [ ] Tests added/updated.
- [ ] Focused validation passes.
- [ ] Active baseline validation passes where applicable.
- [ ] RegressionBaselineManifestTest passes where registry changes occur.
- [ ] `npm build` passes when frontend changed.
- [ ] PHP lint passes for changed PHP files.
- [ ] PowerShell syntax passes for changed PS files.
- [ ] `git diff --check` passes.
- [ ] Security/audit self-review passes (Section 12).
- [ ] Candidate baselines not promoted.
- [ ] Exact-path staging used (`git add -- <path>`).
- [ ] Feature branch pushed.
- [ ] Draft PR created with complete PR body.
- [ ] Final report includes PR URL.
- [ ] Work stops before merge.

## 12. Per-package security/audit gate

Each package must check this list before commit:

- [ ] Authorization — permission gates are enforced server-side.
- [ ] Permission naming — follows existing module convention.
- [ ] Policy enforcement — policy/service checks are applied.
- [ ] Property isolation — queries are scoped to current property.
- [ ] Tenant isolation — no cross-tenant data leakage.
- [ ] Actor resolution — actor is resolved server-side, never from browser input.
- [ ] Idempotency — idempotency key is required and validated.
- [ ] Source hash / source snapshot — audit evidence recorded where pattern exists.
- [ ] Append-only / immutability — evidence records are immutable (app + DB level).
- [ ] Audit trail / activity log — meaningful controlled actions are recorded.
- [ ] Browser-input hardening — no browser-supplied amount, currency, property, actor, or financial status.
- [ ] No secrets printed — no credentials, tokens, or .env content in output.
- [ ] No .env inspection — `.env`, `.env.*`, `bootstrap/cache/config.php` not read/parsed.
- [ ] Migration safety — additive, no destructive operations, triggers tested.
- [ ] No cross-domain lifecycle mutation — only package-owned domain state is mutated.
- [ ] No financial mutation unless package-owned — Front Desk ≠ Finance.
- [ ] No candidate baseline promotion — candidates remain candidates unless Owner promotes.

### 12a. Audit/security gate failure procedure

If any item in the Section 12 checklist fails:

- Do not commit.
- Do not push.
- Do not create Draft PR.
- Do not discard changes automatically.
- Preserve the working tree for Owner/ChatGPT inspection.
- Report: `PACKAGE_BLOCKED_AUDIT_GATE`
- Include:
  - Which checklist item failed.
  - File(s) involved.
  - Why it failed.
  - Whether any uncommitted changes exist.
- Do not attempt automatic remediation without an explicit Owner decision.
- Do not retry the same package silently in the same session.

## 13. Sequential semi-automation rule

Semi-automatic package train is allowed only as:

1. Start from accepted default branch.
2. Select next package from contract.
3. Create feature branch.
4. Implement one package.
5. Validate.
6. Commit.
7. Push feature branch.
8. Create Draft PR.
9. Stop.
10. Wait for ChatGPT/Owner review.
11. Merge only through separate fast-forward merge prompt.
12. Use merged output as context for next package.

**Forbidden:**
- Continuous loop that implements multiple packages without review.
- Auto-merge without review.
- Starting next package from unmerged PR branch.
- Combining domain package + cross-domain package + security package into one PR unless explicitly authorized.

## 14. Package run template

> Continue the next IVORQ package according to `.agents/contracts/IVORQ-Package-Execution-Contract.md`. Use the current accepted default SHA: `<SHA>`. Select only the next safe package, implement one package, validate, push feature branch, create Draft PR with `gh`, return PR URL, and stop. Do not merge.

## 15. Package completion handoff

Each package final report must include:

- Contract version used
- Package name
- Owner domain
- Dependency source
- Files changed
- Tests and assertions
- Active baseline before/after
- Candidate baseline status
- Security/audit gate result
- Commit SHA
- Draft PR URL
- Stop confirmation

## 16. Contract governance status

- This contract file is a proposal until Owner explicitly approves it.
- Status field at top of file: `Status: DRAFT_PENDING_OWNER_APPROVAL` or `Status: APPROVED`.
- No package prompt may cite this contract as binding while Status is `DRAFT_PENDING_OWNER_APPROVAL`.
- Only Owner may change Status to `APPROVED`. AI agents must not self-approve.
- ChatGPT/Claude review of this PR is advisory; final approval authority is Owner only.
