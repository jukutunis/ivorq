# IVORQ — Claude Code Bridge

You are Claude Code working inside the IVORQ repository.

This file is a thin Claude-specific bridge. The canonical IVORQ governance, architecture, and workflow rules remain in `GEMINI.md` and the relevant files under `.agents/skills/`.

## Required session start

Before any task that could read, modify, test, stage, or commit repository work:

1. Read this `CLAUDE.md`.
2. Read `GEMINI.md`.
3. Read only the relevant skill file(s) under `.agents/skills/` for the assigned task.
4. Inspect `git status --short` before making any change.
5. Treat pre-existing modified and untracked files as protected unless the Owner explicitly includes them in the task scope.

Do not read every skill, every document, or the entire repository by default. Read only what is relevant to the assigned slice.

## Default IVORQ operating mode

Use **Delivery Mode** by default:

- Deliver one narrowly scoped, Owner-defined outcome.
- Follow the exact allowed-file boundary in the task.
- Implement plus run proportionate validation.
- Give concise evidence and stop.
- Do not create self-directed phases, broad refactors, speculative cleanup, extra documentation, or test-infrastructure work.
- Use review/audit mode only when explicitly requested or when a real high-risk blocker requires it.

For a read-only preflight, inspect only the minimum committed sources needed to prove the requested facts. Do not implement unless implementation is explicitly authorized.

## Architecture and domain authority

IVORQ is an enterprise hospitality platform.

- Product principle: use familiar hospitality operational logic with modern human-designed hospitality UX.
- Organizational hierarchy: Enterprise → Tenant (Cloud Name) → Property.
- Inventory Ledger is the source of truth.
- Initial inventory valuation method: AVCO; FIFO is future scope.
- Inventory owns immutable source valuation evidence.
- CostControl owns AVCO durable state and Cost Ledger entries.
- Derived Cost Ledger delivery uses the transactional outbox architecture.
- Strict sequence barrier: a non-allow outcome must not mutate AVCO state, advance the applied sequence, or append a Cost Ledger entry. Do not silently skip, retry, bypass, or infer recovery behavior.
- Do not implement the CostControl Outbox Consumer, queue worker, scheduler, publisher, listener, replay, correction workflow, or General Ledger integration unless the current task explicitly authorizes that exact slice.

When a task crosses module ownership, accounting outcomes, shared primitives, or ADR boundaries, read the relevant IVORQ skill and approved ADR/source material before acting.

## Repository safety

Never use:

```text
git add .
git add -A
git clean
git reset --hard
git restore .
```

Never stage, modify, delete, rename, or commit files outside the exact authorized scope.

Always use explicit file paths: `git add -- <path>` — never `git add .` or `git add -A`.

Before commit:

1. Verify the staged diff contains only authorized files.
2. Run `git diff --cached --check`.
3. Use explicit file paths with `git add -- <path>`.
4. Do not amend or rewrite history unless the Owner explicitly asks.

Do not touch protected local artifacts merely because they appear in `git status`.

**Push policy:**

- Never force push (`git push --force`, `git push --force-with-lease`).
- Never push the default branch (`ivorq-enterprise-core`) unless the Owner explicitly authorizes that exact push.
- Feature branch push is allowed only when the task explicitly asks for it (e.g., `git push -u origin <feature-branch>`).
- Do not create a PR unless explicitly requested after a clean final report.

## Security and environment boundary

Never print .env or secrets.
Never commit secrets.
Do not manually inspect credentials.
Do not manually parse or read .env to extract credentials.
Do not create ad-hoc scripts to print, parse, or echo credentials.

Use already configured environment when available.

If a PostgreSQL validation requires local DB variables and the task explicitly authorizes full access, the approved local helper may be used:

```
C:\Users\edigd\.ivorq-local\Invoke-IvorqPgPhpunitWithEnv.ps1
```

The helper may load DB variables into a child PHPUnit process but must never print secrets.

If the helper or environment is unavailable, report the blocker without attempting a credential workaround.

## Validation

- Prefer focused validation for the current authorized slice.
- Use the repository's PostgreSQL test configuration for PostgreSQL-bound work.
- Do not replace a failed validation with broad test runs.
- Do not use raw SQL DML, Tinker, database shell commands, migrations, or test data changes unless explicitly authorized.
- If source code changes after a test run, rerun the relevant focused validation before reporting success.
- Be precise: distinguish evidence from inference and state blockers plainly.

## Response and handover format

At the end of any task, report only:

1. Scope and exact files changed or inspected.
2. Key behavior proven or blocker found.
3. Validation commands and actual result.
4. Git/staging/commit status.
5. Deferred or unauthorized items.

For a read-only task, explicitly confirm that no file, database, staging area, or commit history was changed.

Do not claim approval, implementation, validation, or architecture facts that are not proven by the current task or approved IVORQ source material.
