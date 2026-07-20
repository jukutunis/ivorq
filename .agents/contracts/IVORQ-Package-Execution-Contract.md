# IVORQ Package Execution Contract

Status: APPROVED
Version: 1.10
Created: 2026-07-10
Last amended: 2026-07-20

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
- Canonical predecessor: `5f471a7bd38687938227d3b345d9e426dc90276a`
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
- ADR trigger: `NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION`
- Front Desk active baseline: 483 tests / 1983 assertions / 0 failures / 0 errors
- GC-A1: 38 tests / 231 assertions
- GLF-D: 60 tests / 253 assertions
- BD-A1: 11 tests / 318 assertions
- NA-A1: 11 tests / 422 assertions
- NA-A2: 20 tests / 914 assertions
- RegressionBaselineManifestTest: 33 tests / 997 assertions
- Complete active runner: 13 passed / 0 failed / 0 skipped
- Inventory Reversal inherited debt remains: 8 tests / 72 assertions / 2 accepted errors
- ADR-089 accepted and merged.
- ADR-089 architecture status: Approved.
- NA-A2 accepted and fast-forward merged.
- NA-A2 does not authorize checkout execution.
- GLF-E accepted and merged.
- GLF-E baseline: 49 tests / 194 assertions / 0 failures / 0 errors (historical predecessor evidence)
- GLF-E acceptance reopened only for GLF-E-S1 savepoint lock-continuity correction
- GLF-E-S1 current authorized correction package
- GC-A2 paused and locked
- Later Front Desk state/evidence, Housekeeping handoff, confirmation/permission, and final command remain locked.
- Full access does not bypass later packages.
- `can_execute=false` remains binding.
- checkout unauthorized
- GC-A2 must not start until GLF-E-S1 is independently reviewed, accepted, and merged.

## 6. Package sequencing model

Packages must run sequentially. One package may depend on earlier package evidence, but must not silently implement later package behavior.

### Current controlled sequence

1. ADR-089 — accepted, Approved, merged
2. NA-A2 — accepted, merged
3. GLF-E PMS terminal financial attestation — accepted, merged
4. GLF-E-S1 savepoint lock-continuity correction — current authorized package
5. GC-A2 General Cashier terminal obligation attestation — paused and locked
6. Front Desk terminal state and immutable checkout evidence — locked
7. Transactional Housekeeping handoff/outbox — locked
8. Checkout sensitive confirmation and execute permission — locked
9. Final checkout command and interaction layer — locked

Only the next package may start after its predecessor is reviewed, accepted, and merged into the canonical branch. BD-A1 is accepted as the authoritative Property Business Date foundation; NA-A1 is accepted as the authoritative Night Audit run and active close-lock foundation. FD-B12 consumes NA-A1 read-only inside Front Desk, but checkout execution remains unauthorized. FD-B13 is accepted and records `CHECKOUT_EXECUTION_BLOCKED_BY_PREREQUISITES` and `NEW_ADR_REQUIRED_BEFORE_IMPLEMENTATION`. ADR-089 is accepted, Approved, and merged at `1682dec0fb7f654e77888a476b4ec55a1507610b`. NA-A2 is accepted and merged at `4241e83e6f9e470a7ff5407179cadc166fc7b555`. GLF-E is accepted and fast-forward merged at `2a42d2439f5c1c3e50e15fc604cd0e8b3bb2ade9`. GLF-E-S1 is the current authorized savepoint lock-continuity correction package. GC-A2 is paused and locked; it must not start until GLF-E-S1 is independently reviewed, accepted, and merged. GC-A2 is limited to General Cashier execution-time terminal obligation attestation, consuming minimized PMS references under General Cashier-owned locks without PMS or Front Desk mutation. GC-A2 does not authorize checkout execution. Checkout execution requires later Owner-approved packages; `can_execute=false` remains the canonical runtime behavior until that separately authorized package changes it. No AI agent may interpret full access as permission to skip package sequencing. Business Date and Night Audit must never absorb source-domain ownership. Package implementation remains sequential.

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
- Server must re-resolve current property context.
- Server must independently revalidate upstream evidence.
- Amount/currency must derive server-side where relevant.
- Authorization must be checked after server-side resolution.

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
