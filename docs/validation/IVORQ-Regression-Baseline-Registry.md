# IVORQ Regression Baseline Registry v1

**Status:** Active
**Version:** 1.0
**Created:** 2026-07-09
**Branch:** `sprint-validation-baseline-governance`
**Classification:** Governance / Validation Infrastructure

---

## Purpose

This registry defines the canonical set of IVORQ regression baselines. Each baseline is backed by an exact test-class manifest — never a broad module filter. The registry replaces the undocumented legacy baselines that were referenced in prior completion reports (e.g., `247 tests / 927 assertions` for Inventory/AVCO/Sensitive, `194 tests` for Banking master) with repeatable, reviewer-verifiable manifests.

## Why Broad Filters Are Forbidden

Broad `--filter` arguments such as `--filter Banking` or `--filter Inventory` are non-deterministic acceptance gates:

1. **Test drift**: As new test classes are added, the filter silently selects more tests. The same command produces different counts over time.
2. **Non-master leakage**: Broad filters pick up migration, deferred, and experimental tests that are not part of the accepted master baseline.
3. **Unreviewable**: No reviewer can verify what a broad filter selects without running it and inspecting the output.
4. **Fragile**: If a test file is renamed or moved, the filter may silently include or exclude it.

All baselines in this registry use `selection_policy: "exact-test-classes"`. The exact list of classes is committed and version-controlled.

## Legacy Baseline Evidence

### Inventory / AVCO / Sensitive Legacy Baseline

- **Referenced in**: ADR-084 (line 439-441), FD-B3 status context
- **Reported count**: 247 tests, 927 assertions
- **Command**: NOT RECORDED
- **Classification**: `LEGACY_EVIDENCE_COMMAND_UNDISCOVERABLE`
- **Evidence**: The completions reports and ADRs reference the baseline by count only. No exact `phpunit --filter=...` command, no class list, and no manifest were committed to the repository.

### Banking Master Legacy Baseline

- **Referenced in**: ADR-080 (line 45), Sprint-FD-A2, Sprint-FD-A3 completion reports
- **Reported count**: 194 tests, 0 failures, 0 errors
- **Command**: NOT RECORDED
- **Classification**: `LEGACY_EVIDENCE_COMMAND_UNDISCOVERABLE`
- **Evidence**: "Full Banking/Finance Master Regression: 194 tests" appears in ADR-080. No exact command or class list was committed.

### Inventory Reversal Inherited Debt

- **Referenced in**: ADR-080 (line 46), Sprint-FD-A3 completion report (line 38-39)
- **Reported**: 8 tests, 72 assertions, 0 failures, 2 inherited errors
- **Class**: `InventoryReversalWorkspaceTest`
- **Classification**: `INHERITED_DEBT_CONFIRMED` — exact class identified and verified.

## Baseline Manifest Policy

1. Every baseline entry must list exact test classes, not broad filters.
2. Every baseline entry must declare its `execution_mode`: `batch` (all classes in one PHPUnit invocation) or `individual` (each class separately, totals summed).
3. Every baseline entry must declare its status: `active`, `candidate`, `legacy-undiscoverable`, or `deferred`.
4. Candidate baselines require owner approval before promotion to `active`.
5. Accepted inherited debt must name exact test classes and expected error counts.
6. Expected `failures` and `errors` must be explicitly stated; `null` means "not yet measured."
7. The manifest file is `scripts/validation/ivorq-regression-baselines.json`.

## Baseline IDs

| ID | Status | Description |
|----|--------|-------------|
| `frontdesk-operational-baseline` | `active` | Front Desk operational tests through Package 9 checkout execution completion foundation, Package 11 FD-C2 assertion sync, Package 15 deterministic Housekeeping source-scan cross-baseline remeasurement, preserved Contract 1.19 historical provenance, Package 17 deterministic three-file Housekeeping source-scan delta, and Package 18 Contract 1.20 governance/guard synchronization - unchanged 68 classes, 729 tests / 5657 assertions |
| `housekeeping-room-readiness-baseline` | `active` | Housekeeping room readiness (HK-B1), Package 11 durable checkout-turnover intake, Package 12 read-only turnover workspace, Package 13 canonical Cleaning Task/Room Inspection readiness with current-runtime canonical claim fixtures and disposable successor isolation, Package 15 controlled assignment, corrected Package 17 post-cleaning Inspection claim/segregation with closed PostgreSQL bypasses, historical NULL-evidence compatibility, immutable authority, maker-checker enforcement, terminal replay, audit evidence, recovered predecessor proofs, and Package 18 test-only Contract 1.20 guard alignment - unchanged 28 classes, 191 tests / 3539 assertions |

## Package 18 Governance Synchronization (2026-08-13)

- Contract Version 1.20 synchronizes accepted Package 17 source truth through PR #51 and canonical merge `37750626f9e0614d26d628a4707bcb205508ae03`.
- Package 18 governance commit: `8cc177066dcd0598e740bea9a70ef756353d1442`.
- Package 18 Housekeeping Contract-guard alignment commit and latest non-metadata validation provenance: `df606720148a0a09df12eb111f5ddd79851608ed`.
- The Package 17 Housekeeping source-integrity Contract guard changed only from Version 1.19 to Version 1.20. No canonical claim writer, browser-authority prohibition, Property scope, terminal claimant ownership, PostgreSQL marker, no-assignment-aggregate, no-background-runtime, foreign-domain, or ADR-ceiling assertion was weakened.
- No Front Desk runtime or Housekeeping production source changed. The exact Front Desk selection remains 68 classes / 729 tests / 5,657 assertions / 0 failures / 0 errors. The exact Housekeeping selection remains 28 classes / 191 tests / 3,539 assertions / 0 failures / 0 errors.
- Historical Package 17 runtime provenance, the Package 17 three-file Housekeeping source-scan explanation, the Package 15 +24 explanation, and the PR #48 Contract correction remain preserved in the manifest.
- Package 19 runtime remains locked. Inventory Reversal remains 8 tests / 72 assertions / 0 failures / exactly 2 documented inherited errors. No new ADR and no new accepted debt.
| `engineering-availability-baseline` | `active` | Engineering room availability tests (ENG-A1) |
| `inventory-avco-sensitive-baseline-v2-candidate` | `candidate` | Inventory / AVCO / Sensitive baseline v2 (replaces legacy 247/927) |
| `inventory-reversal-inherited-debt-v1` | `active` | Inventory Reversal workspace inherited trigger debt |
| `banking-master-baseline-v2-candidate` | `candidate` | Banking master baseline v2 (replaces legacy 194) |

## Expected Result Policy

- **Active baselines**: Expected failures and errors must be `0/0`.
- **Candidate baselines**: Expected `failures=0`, `errors=0`. Actual counts are recorded in the manifest after the first successful run.
- **Inherited debt baselines**: Expected error count must match the documented inherited errors. All other failures/errors must be `0`.

## Accepted Inherited Debt Policy

Inherited debt is accepted only when:

1. The exact test class is identified.
2. The exact expected test count, assertion count, failure count, and error count are documented.
3. The root cause of the errors is understood and documented (not hidden).
4. The debt is tracked in `docs/validation/IVORQ-Regression-Baseline-Debt.md`.
5. A future package will resolve the inherited debt.

### Canonical Error Count Semantics

- **`expected.errors` is the canonical total.** The runner compares actual errors directly against this field. It is the single source of truth for expected error count.
- **`accepted_debt` is explanatory metadata only.** The `accepted_debt[].expected_errors` field documents how many of the canonical errors are attributable to each debt entry. It is NOT additive — the runner does not add `accepted_debt.expected_errors` to `expected.errors`.
- **Example:** `inventory-reversal-inherited-debt-v1` has `expected.errors = 2` and `accepted_debt[0].expected_errors = 2`. The values are equal because all 2 canonical errors are explained by that single debt entry. The runner expects exactly 2 actual errors — not 4.
- If accepted debt is partially resolved (e.g., 1 of 2 trigger errors fixed), the owner must update BOTH `expected.errors` (to the new canonical count) AND `accepted_debt.expected_errors` (to reflect remaining debt).

## Environment Safety Rules

All baseline runs must:

1. Use `phpunit.pg.xml` configuration.
2. Target the `ivorq_testing` PostgreSQL database.
3. Never run `migrate:fresh`, `db:seed`, `TRUNCATE`, or `DROP DATABASE` on the main test database.
4. Never read or print `.env` secrets.
5. Require `DB_*` environment variables to be available in the calling shell.

## Future Package Prompt Usage

Future packages can reference this registry:

```powershell
# Run all active baselines (default acceptance gate)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -All

# Run active + candidate baselines (for candidate evaluation)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -All -IncludeCandidates

# Run a specific baseline (any status — diagnostic)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -BaselineId inventory-reversal-inherited-debt-v1

# Run a candidate baseline explicitly
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -BaselineId banking-master-baseline-v2-candidate
```

Future package authors must not invent new broad filters as acceptance gates. If a new baseline is needed, add it to the manifest with exact classes and owner review.

### Default Selection Policy

- **`-All` runs active baselines only.** This is the default acceptance gate. It must pass with exit code 0.
- **`-All -IncludeCandidates` adds candidate baselines.** Use for candidate evaluation; candidate mismatches are diagnostic, not gating.
- **`-BaselineId` runs the exact baseline regardless of status.** Candidate mismatch still returns non-zero exit code — it is diagnostic evidence, not a clean pass.
- Candidate baselines are not active acceptance gates. Their failures must not block a package acceptance gate.

## Owner Approval Requirement

- v2 candidate baselines (`inventory-avco-sensitive-baseline-v2-candidate`, `banking-master-baseline-v2-candidate`) require explicit owner approval before promotion to `active`.
- Approval consists of: review of exact class list, confirmation that excluded classes are intentional, and acceptance of actual test/assertion counts.
- Until approved, candidate baselines are informative but not gating.

## Related Documents

- [IVORQ-Regression-Baseline-Debt.md](IVORQ-Regression-Baseline-Debt.md) — Inherited and acknowledged debt registry
- [ivorq-regression-baselines.json](../../scripts/validation/ivorq-regression-baselines.json) — Machine-readable baseline manifest
- [Invoke-IvorqRegressionBaseline.ps1](../../scripts/validation/Invoke-IvorqRegressionBaseline.ps1) — Repeatable baseline runner
- [RegressionBaselineManifestTest.php](../../tests/Postgres/Validation/RegressionBaselineManifestTest.php) — Manifest integrity test
