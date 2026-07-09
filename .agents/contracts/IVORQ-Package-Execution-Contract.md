# IVORQ Package Execution Contract

Status: APPROVED
Version: 1.0
Created: 2026-07-10
Last amended: 2026-07-10

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
- Current active package sequence as recorded in this contract (Section 5).

## 4. Architecture reference summary

Concise source-backed architecture summary — not a full ADR dump.

- **Multi-tenant hierarchy:** Enterprise → Tenant / Cloud Name → Property.
- **Property context:** Property isolation is mandatory for operational work.
- **Audit trail:** State-changing work must preserve audit/source evidence.
- **Approval engine:** Approval-sensitive workflows must not bypass authorization or approval state.
- **Finance boundary:** Front Desk must not mutate General Cashier, GL, AR, tax, revenue, settlement, payment, invoice, Night Audit, or Accounting unless the exact finance-owned package explicitly authorizes it.
- **Interaction layer:** Use hospitality operational workspaces, not generic CRUD/admin screens. OPERA-derived logic, modern human-designed hospitality UX. No AdminLTE/long-sidebar patterns.
- **Inventory/Cost boundary:** Inventory Ledger remains source of truth. AVCO is current valuation method. FIFO is future scope. Candidate baselines remain diagnostic unless explicitly promoted by Owner.
- **Agent governance:** Delivery Mode by default. Exact scope, focused validation, concise evidence, stop.

## 5. Current accepted baseline

- `ivorq-enterprise-core` @ `290ccf028c9a05b28a425fea0b042fe82e77201f`
- PR #3 — Validation Baseline Governance merged
- PR #4 — FD-B3 Controlled Departure Operational Handover merged
- PR #5 — FD-B4 Controlled Departure Closure Readiness merged
- PR #6 — AI Agent Governance Baseline merged
- Front Desk active baseline: 258 tests / 1128 assertions / 0 failures / 0 errors
- RegressionBaselineManifestTest: 24 tests / 502 assertions / 0 failures / 0 errors
- Candidate Banking remains candidate
- Candidate Inventory/AVCO/Sensitive remains candidate
- Inventory Reversal inherited debt remains accepted as recorded in baseline governance

## 6. Package sequencing model

Packages must run sequentially. One package may depend on earlier package evidence, but must not silently implement later package behavior.

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

- Only finance/accounting-owned packages may mutate payment, cashier, folio, settlement, GL, AR, tax, revenue, invoice, or Night Audit state.
- Front Desk packages may display or record operational markers only when explicitly authorized.
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
