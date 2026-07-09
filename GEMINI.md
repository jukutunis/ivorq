# IVORQ AI Instructions

Project:
IVORQ Hospitality Operations Platform

Product principle:
Use OPERA-derived hospitality operational logic with modern human-designed hospitality UX.
Avoid generic ERP, AdminLTE, long-sidebar, and CRUD-first UX.

Stack:

- PHP 8.3+
- Laravel 13
- React + TypeScript
- Inertia
- Tailwind
- PostgreSQL
- Redis
- Sanctum
- Spatie Permission
- Docker / Nginx where applicable

Go and Python are future specialized service boundaries only. Do not introduce Go/Python runtime services unless explicitly authorized. Do not treat future Go/Python as a rewrite.

Hierarchy:

- Enterprise → Tenant / Cloud Name → Property
- Cloud Name identifies Tenant.
- Property context is mandatory for operational work.

Architecture:

- Multi-property SaaS
- Property isolation required
- ULID primary keys
- Service layer architecture
- Repository pattern
- Policy-based authorization

Current accepted baseline:

- ivorq-enterprise-core @ 9a4d11690edbf5c1ad5a4ab0dc4c0aea50c79339
- PR #3 Validation Baseline Governance merged
- PR #4 FD-B3 Controlled Departure Operational Handover merged
- PR #5 FD-B4 Controlled Departure Closure Readiness merged
- Front Desk active baseline: 258 tests / 1128 assertions / 0 failures / 0 errors
- RegressionBaselineManifestTest: 24 tests / 484 assertions / 0 failures / 0 errors
- Runner -All active baselines: all 4 active baselines PASS
- Candidate Banking remains candidate
- Candidate Inventory/AVCO/Sensitive remains candidate
- Inventory Reversal inherited debt remains 8 tests / 72 assertions / 0 failures / 2 accepted errors

Default operating mode:

Delivery Mode by default:

- Deliver one narrowly scoped, Owner-defined outcome.
- Follow the exact allowed-file boundary in the task.
- Implement plus run proportionate validation.
- Give concise evidence and stop.
- Do not create self-directed phases, broad refactors, speculative cleanup, extra documentation, or test-infrastructure work.
- Use review/audit mode only when explicitly requested or when a real high-risk blocker requires it.
- For a read-only preflight, inspect only the minimum committed sources needed to prove the requested facts. Do not implement unless implementation is explicitly authorized.

Safety rules:

- Never use git add .
- Never use git add -A
- Never use git reset
- Never use git restore
- Never use git clean
- Never use git stash unless explicitly authorized
- Never force push
- Never rebase unless explicitly authorized
- Never push default branch unless explicitly authorized by Owner
- Never merge unless explicitly authorized
- Never use migrate:fresh, db:seed, migrate --seed
- Never use DROP DATABASE except isolated disposable test DB when the task explicitly authorizes it
- Never TRUNCATE
- Never print .env or secrets
- Never commit secrets
- Do not manually inspect credentials
- Never stage, modify, delete, rename, or commit files outside the exact authorized scope
- Before commit, verify the staged diff contains only authorized files
- Use explicit file paths with git add -- <path>
- Do not amend or rewrite history unless the Owner explicitly asks
- Do not touch protected local artifacts merely because they appear in git status

Environment and .env policy:

- Never print .env or secrets.
- Never commit secrets.
- Do not manually inspect credentials.
- Use already configured environment when available.
- If a PostgreSQL validation requires local DB variables and the task explicitly authorizes full access, the approved local helper may be used:
  C:\Users\edigd\.ivorq-local\Invoke-IvorqPgPhpunitWithEnv.ps1
- The helper may load DB variables into a child PHPUnit process but must never print secrets.
- If the helper or environment is unavailable, report the blocker.

Validation baseline policy:

- Use scripts/validation/Invoke-IvorqRegressionBaseline.ps1 for accepted gates.
- `-All` runs active baselines only — this is the default acceptance gate.
- `-All -IncludeCandidates` adds candidate baselines for diagnostic evaluation.
- `-BaselineId` runs an explicit baseline (any status) for diagnostics.
- Candidate baselines are diagnostic only. Candidate mismatch is not an active gate failure.
- Do not promote candidates without Owner approval.
- Do not change Inventory Reversal expected.errors = 2 without Owner approval.
- Broad filters such as --filter Banking or --filter Inventory are not final acceptance gates.
- Prefer focused validation for the current authorized slice.
- Use the repository's PostgreSQL test configuration for PostgreSQL-bound work.
- Do not replace a failed validation with broad test runs.
- Do not use raw SQL DML, Tinker, database shell commands, migrations, or test data changes unless explicitly authorized.
- If source code changes after a test run, rerun the relevant focused validation before reporting success.
- Be precise: distinguish evidence from inference and state blockers plainly.

Documentation policy:

- Do not create documentation unless the task explicitly asks, the change affects ADR/governance, or the implementation cannot be safely understood without a concise record.
- No sprint reports in repository root.
- Use existing docs layout.
- Do not reorganize docs without explicit authorization.
- ADRs are for durable architecture decisions, not routine implementation notes.
- Do not edit approved ADRs just to make an implementation appear compliant.

Skills policy:

- Before any task that could read, modify, test, stage, or commit repository work, read only the relevant skill file(s) under `.agents/skills/`.
- Do not read every skill, every document, or the entire repository by default.
- Read only what is relevant to the assigned slice.
- Skill files guide behavior but do not override Owner scope or repository evidence.

Response and handover format:

At the end of any task, report only:

1. Scope and exact files changed or inspected.
2. Key behavior proven or blocker found.
3. Validation commands and actual result.
4. Git/staging/commit status.
5. Deferred or unauthorized items.

For a read-only task, explicitly confirm that no file, database, staging area, or commit history was changed.

Do not claim approval, implementation, validation, or architecture facts that are not proven by the current task or approved IVORQ source material.

Git Tags:

- v0.1-foundation-stable
- v0.2-sprint02-complete
