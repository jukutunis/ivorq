<?php

/**
 * GLF-E Concurrency Worker — separate-process CLI entry point.
 *
 * Usage: php worker.php <base_path> <data_file>
 *
 * The data_file contains a JSON object with 'mode' and other payload fields.
 *
 * Modes: attest, mutate, attest_other, hold_source, attest_after_release
 *
 * Outputs one JSON line to stdout containing:
 *   mode, result, php_pid, postgres_backend_pid, postgres_transaction_id,
 *   started_at, completed_at
 */

// CLI guard: only run when invoked directly
(function () {
    global $argv;

    $basePath = $argv[1] ?? '';
    $dataFile = $argv[2] ?? '';

    if (empty($basePath) || empty($dataFile) || ! file_exists($dataFile)) {
        fwrite(STDERR, "Usage: php worker.php <base_path> <data_file>\n");
        exit(1);
    }

    chdir($basePath);
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $payload = json_decode(file_get_contents($dataFile), true) ?: [];
    $mode = $payload['mode'] ?? 'attest';
    $startedAt = date('c');

    try {
        $result = match ($mode) {
            'attest'           => doAttest($payload),
            'mutate'           => doMutate($payload),
            'attest_other'     => doAttestOther($payload),
            'hold_source'      => doHoldSource($payload),
            'attest_after_release' => doAttestAfterRelease($payload),
            default            => throw new \RuntimeException("Unknown mode: {$mode}"),
        };

        $result['mode'] = $mode;
        $result['started_at'] = $startedAt;
        $result['completed_at'] = date('c');
        $result['php_pid'] = getmypid();

        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'
        );
        $result['postgres_backend_pid'] = (int) ($row->pid ?? 0);
        $result['postgres_transaction_id'] = trim((string) ($row->txid ?? ''));

        echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (\Throwable $e) {
        echo json_encode([
            'mode' => $mode,
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'php_pid' => getmypid(),
            'started_at' => $startedAt,
            'completed_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
})();

function doAttest(array $p): array
{
    $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $p) {
        $context = $lockService->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        signalMarker($p['ready_marker'] ?? null);

        if (! empty($p['barrier_path'])) {
            waitForBarrier($p['barrier_path'], $p['barrier_timeout'] ?? 30);
        }

        $attestation = $attestService->attest($context, $p['stay_id']);

        if (! empty($p['hold_until_path'])) {
            signalMarker($p['hold_until_path'] . '.ready');
            waitForBarrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        }

        return ['status' => $attestation->status->value, 'fingerprint' => $attestation->source_fingerprint];
    });
}

function doMutate(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        signalMarker($p['ready_marker'] ?? null);

        if (! empty($p['barrier_path'])) {
            waitForBarrier($p['barrier_path'], $p['barrier_timeout'] ?? 30);
        }

        \Illuminate\Support\Facades\DB::table($p['table'])
            ->where('id', $p['row_id'])
            ->update([$p['column'] => $p['value']]);

        if (! empty($p['hold_until_path'])) {
            signalMarker($p['hold_until_path'] . '.ready');
            waitForBarrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        }

        return ['mutated' => true];
    });
}

function doAttestOther(array $p): array
{
    $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $p) {
        $context = $lockService->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        signalMarker($p['ready_marker'] ?? null);

        $attestation = $attestService->attest($context, $p['stay_id']);

        if (! empty($p['hold_until_path'])) {
            signalMarker($p['hold_until_path'] . '.ready');
            waitForBarrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        }

        return ['status' => $attestation->status->value];
    });
}

function doHoldSource(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        signalMarker($p['ready_marker'] ?? null);

        \Illuminate\Support\Facades\DB::table($p['table'])
            ->where('id', $p['row_id'])
            ->lockForUpdate()
            ->first();

        signalMarker($p['hold_until_path'] . '.ready');
        waitForBarrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);

        return ['held' => true];
    });
}

function doAttestAfterRelease(array $p): array
{
    $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

    signalMarker($p['ready_marker'] ?? null);
    waitForBarrier($p['barrier_path'], $p['barrier_timeout'] ?? 30);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $p) {
        $context = $lockService->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $attestation = $attestService->attest($context, $p['stay_id']);
        return ['status' => $attestation->status->value, 'fingerprint' => $attestation->source_fingerprint];
    });
}

function signalMarker(?string $path): void
{
    if ($path !== null && $path !== '') {
        file_put_contents($path, getmypid());
    }
}

function waitForBarrier(string $path, int $timeoutSeconds): void
{
    $deadline = time() + $timeoutSeconds;
    while (time() < $deadline) {
        if (file_exists($path)) {
            unlink($path);
            return;
        }
        usleep(100000);
    }
    throw new \RuntimeException("Barrier timeout after {$timeoutSeconds}s: {$path}");
}
