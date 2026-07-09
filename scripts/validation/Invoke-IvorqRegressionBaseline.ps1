# IVORQ Regression Baseline Runner v1
#
# Reads scripts/validation/ivorq-regression-baselines.json.
# Runs exact test classes from the manifest -- never broad filters.
# Uses phpunit.pg.xml. Requires DB_* environment variables already available.
# Does not read .env or print secrets.
#
# Usage:
#   .\Invoke-IvorqRegressionBaseline.ps1 -BaselineId inventory-reversal-inherited-debt-v1
#   .\Invoke-IvorqRegressionBaseline.ps1 -BaselineId banking-master-baseline-v2-candidate
#   .\Invoke-IvorqRegressionBaseline.ps1 -All

param(
    [Parameter(Mandatory = $false)]
    [string]$BaselineId,

    [Parameter(Mandatory = $false)]
    [switch]$All,

    [string]$ManifestPath,

    [string]$PhpunitPath,

    [string]$Configuration = 'phpunit.pg.xml',

    [string]$ProjectDir
)

$ErrorActionPreference = 'Stop'

# Resolve paths relative to script location
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $ProjectDir) {
    $ProjectDir = Resolve-Path (Join-Path $ScriptDir '..\..')
}
if (-not $ManifestPath) {
    $ManifestPath = Join-Path $ScriptDir 'ivorq-regression-baselines.json'
}
if (-not $PhpunitPath) {
    $PhpunitPath = Join-Path $ProjectDir 'vendor\bin\phpunit'
}

# Guard: manifest must exist
if (-not (Test-Path -LiteralPath $ManifestPath)) {
    Write-Error "Manifest not found: $ManifestPath"
    exit 2
}

# Guard: at least one selection mode
if (-not $All -and -not $BaselineId) {
    Write-Error "Specify -BaselineId <id> or -All."
    exit 2
}

# Guard: DB env vars
if (-not $env:DB_DATABASE) {
    Write-Error "DB_DATABASE environment variable is not set. Set DB_* variables before running."
    exit 3
}

# Load manifest
$manifest = Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json

# Select baselines to run
$selected = @()
if ($All) {
    $selected = $manifest.baselines
} else {
    $match = $manifest.baselines | Where-Object { $_.id -eq $BaselineId }
    if (-not $match) {
        Write-Error "Baseline '$BaselineId' not found in manifest."
        exit 2
    }
    $selected = @($match)
}

Write-Host "=== IVORQ Regression Baseline Runner v1 ==="
Write-Host "Manifest : $ManifestPath"
Write-Host "PHPUnit  : $PhpunitPath"
Write-Host "Config   : $Configuration"
Write-Host "Targets  : $($selected.Count) baseline(s)"
Write-Host ""

# ------------------------------------------------------------------
# Helper: Parse PHPUnit output for test summary.
# Handles JSON output format (e.g., {"tool":"phpunit","result":"...","tests":N,...})
# and falls back to traditional text output (e.g., "Tests: N, Assertions: N, ...").
# ------------------------------------------------------------------
function Parse-PhpunitOutput {
    param([string[]]$Output)

    $result = @{ Tests = 0; Assertions = 0; Failures = 0; Errors = 0 }

    # Try JSON format first
    foreach ($line in $Output) {
        if ($line -match '"tool"\s*:\s*"phpunit"') {
            try {
                $json = $line | ConvertFrom-Json
                if ($json.PSObject.Properties['tests'])    { $result.Tests = [int]$json.tests }
                if ($json.PSObject.Properties['assertions']) { $result.Assertions = [int]$json.assertions }
                # The JSON wrapper may use 'failed' (past tense) or 'failures' (noun)
                if ($json.PSObject.Properties['failed'])    { $result.Failures = [int]$json.failed }
                elseif ($json.PSObject.Properties['failures']) { $result.Failures = [int]$json.failures }
                if ($json.PSObject.Properties['errors'])    { $result.Errors = [int]$json.errors }
                return $result
            } catch {
                # Not valid JSON, fall through to text parsing
            }
        }
    }

    # Fallback: traditional PHPUnit text output
    $testsLine = ($Output | Select-String -Pattern 'Tests:\s*(\d+)' | Select-Object -Last 1)
    $assertionsLine = ($Output | Select-String -Pattern 'Assertions:\s*(\d+)' | Select-Object -Last 1)
    $failuresLine = ($Output | Select-String -Pattern 'Failures:\s*(\d+)' | Select-Object -Last 1)
    $errorsLine = ($Output | Select-String -Pattern 'Errors:\s*(\d+)' | Select-Object -Last 1)

    if ($testsLine -match 'Tests:\s*(\d+)')      { $result.Tests = [int]$matches[1] }
    if ($assertionsLine -match 'Assertions:\s*(\d+)') { $result.Assertions = [int]$matches[1] }
    if ($failuresLine -match 'Failures:\s*(\d+)') { $result.Failures = [int]$matches[1] }
    if ($errorsLine -match 'Errors:\s*(\d+)')    { $result.Errors = [int]$matches[1] }

    return $result
}
$results = @()

foreach ($baseline in $selected) {
    Write-Host "--- Baseline: $($baseline.id) ---"
    Write-Host "Description: $($baseline.description)"
    Write-Host "Status     : $($baseline.status)"
    Write-Host "Classes    : $($baseline.classes.Count)"
    Write-Host ""

    if ($baseline.classes.Count -eq 0) {
        Write-Host "[SKIP] No test classes defined."
        $results += [PSCustomObject]@{
            Id         = $baseline.id
            Status     = 'SKIPPED'
            Tests      = 0
            Assertions = 0
            Failures   = 0
            Errors     = 0
            Passed     = $true
        }
        continue
    }

    # Determine execution mode (default: batch if not specified)
    $execMode = 'batch'
    if ($baseline.PSObject.Properties['execution_mode']) {
        $execMode = $baseline.execution_mode
    }

    $configAbs = Join-Path $ProjectDir $Configuration

    $actualTests = 0
    $actualAssertions = 0
    $actualFailures = 0
    $actualErrors = 0

    if ($execMode -eq 'individual') {
        # ------------------------------------------------------------------
        # INDIVIDUAL MODE: run each class separately, sum totals.
        # If any class cannot be found or produces an unexpected outcome,
        # the final baseline result will fail.
        # ------------------------------------------------------------------
        Write-Host "Execution Mode: INDIVIDUAL -- running $($baseline.classes.Count) class(es) separately"
        Write-Host ""

        foreach ($className in $baseline.classes) {
            $escapedName = [regex]::Escape($className)
            $classFilter = "$escapedName::"

            Write-Host "  [$className] Running..."

            Push-Location $ProjectDir
            try {
                $env:APP_ENV = 'testing'
                $env:DB_CONNECTION = 'pgsql'

                $classOutput = & php $PhpunitPath --filter=$classFilter --configuration=$configAbs 2>&1
                $classExitCode = $LASTEXITCODE
                $classOutput | ForEach-Object { Write-Host "    $_" }
            } finally {
                Pop-Location
            }

            # Parse individual class summary
            $classResult = Parse-PhpunitOutput -Output $classOutput
            $cTests = $classResult.Tests
            $cAssertions = $classResult.Assertions
            $cFailures = $classResult.Failures
            $cErrors = $classResult.Errors

            Write-Host "  [$className] Tests=$cTests Assertions=$cAssertions Failures=$cFailures Errors=$cErrors"
            Write-Host ""

            $actualTests += $cTests
            $actualAssertions += $cAssertions
            $actualFailures += $cFailures
            $actualErrors += $cErrors
        }

        Write-Host "Individual totals: Tests=$actualTests Assertions=$actualAssertions Failures=$actualFailures Errors=$actualErrors"
        Write-Host ""
    } else {
        # ------------------------------------------------------------------
        # BATCH MODE: all classes in one PHPUnit invocation.
        # Build a regex from escaped exact class names -- never broad filters.
        # ------------------------------------------------------------------
        Write-Host "Execution Mode: BATCH"
        Write-Host ""

        # Escape each class name for safe regex use
        $filterParts = $baseline.classes | ForEach-Object { "$([regex]::Escape($_))::" }
        $filterRegex = '(' + ($filterParts -join '|') + ')'

        Write-Host "Filter regex: $filterRegex"
        Write-Host ""

        Push-Location $ProjectDir
        try {
            $env:APP_ENV = 'testing'
            $env:DB_CONNECTION = 'pgsql'

            $output = & php $PhpunitPath --filter=$filterRegex --configuration=$configAbs 2>&1
            $exitCode = $LASTEXITCODE
            $output | ForEach-Object { Write-Host $_ }
        } finally {
            Pop-Location
        }

        # Parse PHPUnit output for summary
        $batchResult = Parse-PhpunitOutput -Output $output
        $actualTests = $batchResult.Tests
        $actualAssertions = $batchResult.Assertions
        $actualFailures = $batchResult.Failures
        $actualErrors = $batchResult.Errors
    }

    # ------------------------------------------------------------------
    # Zero-test rejection: if classes > 0 but 0 tests were selected, fail.
    # Applies even when expected.tests is null (candidate baselines).
    # ------------------------------------------------------------------
    if ($baseline.classes.Count -gt 0 -and $actualTests -eq 0) {
        Write-Host ""
        Write-Host "=== Summary for $($baseline.id) ==="
        Write-Host "  RESULT: NO_TESTS_SELECTED"
        Write-Host "  Classes defined: $($baseline.classes.Count), but 0 tests were selected."
        Write-Host "  Verify that all class names in the manifest exist and are autoloadable."
        Write-Host ""

        $results += [PSCustomObject]@{
            Id         = $baseline.id
            Status     = 'NO_TESTS_SELECTED'
            Tests      = 0
            Assertions = 0
            Failures   = 0
            Errors     = 0
            Passed     = $false
        }
        $overallExit = 1
        continue
    }

    # ------------------------------------------------------------------
    # Expected-vs-actual comparison.
    # expected.errors is the CANONICAL TOTAL.
    # accepted_debt is explanatory metadata only -- do NOT add to expected.errors.
    # ------------------------------------------------------------------
    $expected = $baseline.expected
    $mismatch = $false
    $mismatchDetails = @()

    if ($expected.PSObject.Properties['tests'] -and $expected.tests -ne $null) {
        if ($actualTests -ne $expected.tests) {
            $mismatch = $true
            $mismatchDetails += "TESTS: expected=$($expected.tests) actual=$actualTests"
        }
    }
    if ($expected.PSObject.Properties['assertions'] -and $expected.assertions -ne $null) {
        if ($actualAssertions -ne $expected.assertions) {
            $mismatch = $true
            $mismatchDetails += "ASSERTIONS: expected=$($expected.assertions) actual=$actualAssertions"
        }
    }
    if ($actualFailures -ne $expected.failures) {
        $mismatch = $true
        $mismatchDetails += "FAILURES: expected=$($expected.failures) actual=$actualFailures"
    }
    # canonical comparison: actual errors vs expected.errors (no debt addition)
    if ($actualErrors -ne $expected.errors) {
        $mismatch = $true
        $mismatchDetails += "ERRORS: expected=$($expected.errors) (canonical total) actual=$actualErrors"
    }

    Write-Host ""
    Write-Host "=== Summary for $($baseline.id) ==="
    Write-Host "  Tests     : $actualTests"
    Write-Host "  Assertions: $actualAssertions"
    Write-Host "  Failures  : $actualFailures  (expected: $($expected.failures))"
    Write-Host "  Errors    : $actualErrors  (expected: $($expected.errors) canonical total)"
    if ($baseline.accepted_debt.Count -gt 0) {
        Write-Host "  Accepted Debt (explanatory metadata only -- NOT added to expected.errors):"
        foreach ($debt in $baseline.accepted_debt) {
            $debtErr = if ($debt.PSObject.Properties['expected_errors']) { $debt.expected_errors } else { 'N/A' }
            $debtTest = if ($debt.PSObject.Properties['test']) { $debt.test } else { '(no test)' }
            Write-Host "    - $debtTest : expected_errors=$debtErr  reason=$($debt.reason)"
        }
    }

    $passed = (-not $mismatch)
    if ($mismatch) {
        Write-Host "  RESULT: MISMATCH"
        foreach ($d in $mismatchDetails) { Write-Host "    $d" }
        $overallExit = 1
    } else {
        Write-Host "  RESULT: PASS"
    }
    Write-Host ""

    $results += [PSCustomObject]@{
        Id         = $baseline.id
        Status     = if ($mismatch) { 'MISMATCH' } else { 'PASS' }
        Tests      = $actualTests
        Assertions = $actualAssertions
        Failures   = $actualFailures
        Errors     = $actualErrors
        Passed     = $passed
    }
}

# Final report
Write-Host "=== Final Report ==="
$results | Format-Table Id, Status, Tests, Assertions, Failures, Errors -AutoSize

$failedCount = ($results | Where-Object { -not $_.Passed }).Count
$skipCount = ($results | Where-Object { $_.Status -eq 'SKIPPED' }).Count
Write-Host "Passed : $(($results | Where-Object { $_.Passed }).Count)"
Write-Host "Failed : $failedCount"
Write-Host "Skipped: $skipCount"

exit $overallExit
