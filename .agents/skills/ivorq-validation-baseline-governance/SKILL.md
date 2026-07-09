---
name: ivorq-validation-baseline-governance
description: |
  IVORQ regression baseline registry, runner usage, candidate management,
  and validation gate policy. Use when running validation gates, adding new
  test classes to a baseline, or evaluating candidate baselines.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Validation Baseline Governance

## Purpose

The IVORQ regression baseline system provides exact, repeatable, reviewer-verifiable test manifests. It replaces undocumented legacy baselines with version-controlled class lists.

## Accepted baselines (active)

| Baseline ID | Classes | Tests | Assertions | Status |
|-------------|---------|-------|------------|--------|
| `frontdesk-operational-baseline` | 28 | 207 | 918 | active |
| `housekeeping-room-readiness-baseline` | 7 | 63 | 221 | active |
| `engineering-availability-baseline` | 5 | 9 | 76 | active |
| `inventory-reversal-inherited-debt-v1` | 1 | 8 | 72 | active |

## Candidate baselines

| Baseline ID | Classes | Status |
|-------------|---------|--------|
| `inventory-avco-sensitive-baseline-v2-candidate` | 51 | candidate |
| `banking-master-baseline-v2-candidate` | 9 | candidate |

## Runner usage

```powershell
# Run all active baselines (default acceptance gate — must pass exit 0)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -All

# Run active + candidate (diagnostic evaluation)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -All -IncludeCandidates

# Run a specific baseline (any status — diagnostic)
.\scripts\validation\Invoke-IvorqRegressionBaseline.ps1 -BaselineId <id>
```

## Acceptance gate policy

- **`-All` runs active baselines only.** Must pass with 0 failures and 0 errors (except accepted inherited debt).
- **Candidate baselines are diagnostic only.** Candidate mismatch is NOT an active gate failure.
- **Do not promote candidates without Owner approval.**
- **Do not change Inventory Reversal `expected.errors = 2`** without Owner approval.
- **Broad filters are forbidden as acceptance gates.** `--filter Banking`, `--filter Inventory`, etc. are non-deterministic and unreviewable.

## Adding tests to a baseline

When a package adds new test classes:
1. Verify all new tests pass individually.
2. Run the full baseline batch to confirm no regressions.
3. Measure exact counts using the runner — never guess.
4. Update `scripts/validation/ivorq-regression-baselines.json` with new classes and updated expected counts.
5. Update `docs/validation/IVORQ-Regression-Baseline-Registry.md` with updated description.
6. Commit the manifest update separately with a clear subject line.

## Inherited debt policy

Inherited debt is accepted only when:
1. The exact test class is identified.
2. Exact expected test/assertion/failure/error counts are documented.
3. The root cause is understood and documented.
4. Debt is tracked in `docs/validation/IVORQ-Regression-Baseline-Debt.md`.
5. A future package will resolve it.
