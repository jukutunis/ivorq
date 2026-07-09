# IVORQ Regression Baseline Runner v1
#
# Reads scripts/validation/ivorq-regression-baselines.json.
# Runs exact test classes from the manifest — never broad filters.
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

$overallExit = 0
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

    # Build filter: exact class names, regex-anchored
    # PHPUnit --filter matches against ClassName::testMethodName
    # Anchoring to exact class names: ^(Class1|Class2|...)$
    $filterParts = $baseline.classes | ForEach-Object { $_ }
    $filterRegex = '^(' + ($filterParts -join '|') + ')$'

    # Escape any regex-special characters in class names (unlikely but safe)
    # PHP class names are alphanumeric + underscores, so this is typically safe as-is.

    Write-Host "Filter regex: $filterRegex"
    Write-Host ""

    $configAbs = Join-Path $ProjectDir $Configuration

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
    $testsLine = ($output | Select-String -Pattern 'Tests:\s*(\d+)' | Select-Object -Last 1)
    $assertionsLine = ($output | Select-String -Pattern 'Assertions:\s*(\d+)' | Select-Object -Last 1)
    $failuresLine = ($output | Select-String -Pattern 'Failures:\s*(\d+)' | Select-Object -Last 1)
    $errorsLine = ($output | Select-String -Pattern 'Errors:\s*(\d+)' | Select-Object -Last 1)

    $actualTests = 0
    $actualAssertions = 0
    $actualFailures = 0
    $actualErrors = 0

    if ($testsLine -match 'Tests:\s*(\d+)') { $actualTests = [int]$matches[1] }
    if ($assertionsLine -match 'Assertions:\s*(\d+)') { $actualAssertions = [int]$matches[1] }
    if ($failuresLine -match 'Failures:\s*(\d+)') { $actualFailures = [int]$matches[1] }
    if ($errorsLine -match 'Errors:\s*(\d+)') { $actualErrors = [int]$matches[1] }

    # Check expected vs actual
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
    if ($actualErrors -ne $expected.errors) {
        # Check accepted debt
        $acceptedErrors = 0
        if ($baseline.accepted_debt) {
            foreach ($debt in $baseline.accepted_debt) {
                $acceptedErrors += $debt.expected_errors
            }
        }
        $adjustedExpected = $expected.errors + $acceptedErrors
        if ($actualErrors -ne $adjustedExpected) {
            $mismatch = $true
            $mismatchDetails += "ERRORS: expected=$($expected.errors) (base) + $acceptedErrors (accepted debt) = $adjustedExpected actual=$actualErrors"
        }
    }

    Write-Host ""
    Write-Host "=== Summary for $($baseline.id) ==="
    Write-Host "  Tests     : $actualTests"
    Write-Host "  Assertions: $actualAssertions"
    Write-Host "  Failures  : $actualFailures  (expected: $($expected.failures))"
    Write-Host "  Errors    : $actualErrors  (expected: $($expected.errors))"
    if ($baseline.accepted_debt.Count -gt 0) {
        Write-Host "  Accepted Debt Errors: $acceptedErrors"
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
