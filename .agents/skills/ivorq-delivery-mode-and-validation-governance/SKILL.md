---
name: ivorq-delivery-mode-and-validation-governance
description: |
  IVORQ Delivery Mode default operating model, scope discipline, validation
  governance, and handoff rules. Use at the start of every implementation task
  and when deciding what to validate, how to scope delivery, and when to stop.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Delivery Mode & Validation Governance

## Purpose

Every IVORQ implementation task runs in Delivery Mode by default. This skill defines the delivery contract: scope, validation, evidence, and stop conditions.

## Delivery Mode contract

1. **One narrow slice.** Deliver exactly one Owner-defined outcome per task.
2. **Exact allowed files.** Only touch files within the explicit file boundary stated in the task package. Do not add cleanup, refactors, or documentation outside that scope.
3. **No self-created phases.** Do not invent discovery, planning, audit, review, or research phases unless the task explicitly authorizes them.
4. **No endless audit loops.** Review/Audit mode is only for explicit review requests or genuine high-risk blockers.
5. **No speculative repo-wide cleanup.** Do not fix unrelated lint, tests, docs, or code style outside authorized scope.
6. **No broad test repair.** Only add or update tests within the authorized package boundary. Do not "fix" unrelated failing tests.
7. **Proportionate validation.** Validate what the task delivers — not everything that could be validated.
8. **Use the regression baseline runner** for accepted acceptance gates when the task targets an active baseline module.
9. **Concise evidence.** Report only: scope, files, validation commands and results, git status, blockers, and deferred items.
10. **Stop after delivery.** Do not start the next package or propose next steps unless explicitly asked.

## Review/Audit Mode

Only enter Review/Audit Mode when:
- The task explicitly asks for a review, audit, or investigation.
- A real high-risk blocker is discovered that prevents safe delivery.

In Review/Audit Mode:
- State findings with evidence.
- Distinguish confirmed issues from inference.
- Do not implement fixes unless authorized.
- Stop and report; do not self-assign remediation.

## Read-only preflight

For fact-finding or pre-implementation inspection:
- Inspect only the minimum committed sources needed.
- Do not implement.
- Confirm no files, database, staging, or history were changed.

## Validation governance

- Prefer focused validation over broad test suites.
- For PostgreSQL-bound work, use the repository's `phpunit.pg.xml` and `ivorq_testing` database.
- The baseline runner (`scripts/validation/Invoke-IvorqRegressionBaseline.ps1`) is the canonical acceptance gate for active baselines.
- Do not use broad `--filter` arguments (e.g., `--filter Banking`) as final gates.
- Do not use `migrate:fresh`, `db:seed`, `TRUNCATE`, or `DROP DATABASE` on the main test database unless explicitly authorized.
- If source code changes after a test run, rerun the relevant focused validation.
- Distinguish evidence from inference; state blockers plainly.
