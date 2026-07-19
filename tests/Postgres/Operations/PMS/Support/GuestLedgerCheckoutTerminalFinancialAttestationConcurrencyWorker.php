<?php

/**
 * GLF-E Concurrency Worker — separate PHP process CLI entry point.
 *
 * Usage: php worker.php <base_path> <data_file>
 *
 * Data file = JSON with 'mode' and payload-specific fields.
 *
 * Outputs ONE JSON line to stdout containing:
 *   mode, result, php_pid, postgres_backend_pid, postgres_transaction_id,
 *   started_at, completed_at.
 *
 * On database failure, also includes:
 *   sqlstate, database_message, domain_error, previous_exception_class.
 */

(function () {
    global $argv;
    $basePath = $argv[1] ?? '';
    $dataFile = $argv[2] ?? '';
    if (empty($basePath) || empty($dataFile) || !file_exists($dataFile)) {
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
    $phpPid = getmypid();

    try {
        $result = match ($mode) {
            'attest'       => doAttest($payload),
            'mutate'       => doMutate($payload),
            'attest_other' => doAttestOther($payload),
            'hold_source'  => doHoldSource($payload),
            default        => throw new \RuntimeException("Unknown mode: {$mode}"),
        };
        $result['mode'] = $mode;
        $result['php_pid'] = $phpPid;
        $result['started_at'] = $startedAt;
        $result['completed_at'] = date('c');
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (\Throwable $e) {
        $out = [
            'mode' => $mode, 'error' => $e->getMessage(), 'class' => get_class($e),
            'php_pid' => $phpPid, 'started_at' => $startedAt, 'completed_at' => date('c'),
        ];
        // Include structured DB error evidence when available
        if ($e instanceof \Illuminate\Database\QueryException) {
            $out['sqlstate'] = $e->errorInfo[0] ?? null;
            $out['database_message'] = $e->getMessage();
            $out['previous_exception_class'] = $e->getPrevious() ? get_class($e->getPrevious()) : null;
        }
        if ($e instanceof \DomainException) {
            $out['domain_error'] = $e->getMessage();
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
})();

function doAttest(array $p): array
{
    $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $p) {
        // 1. Acquire NA-A2 context (within transaction)
        $context = $lockService->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);

        // 2. Perform GLF-E attest (acquires PMS locks)
        $attestation = $attestService->attest($context, $p['stay_id']);

        // 3. Resolve transaction identity INSIDE transaction
        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'
        );
        $result = [
            'status' => $attestation->status->value,
            'fingerprint' => $attestation->source_fingerprint,
            'postgres_backend_pid' => (int)($row->pid ?? 0),
            'postgres_transaction_id' => trim((string)($row->txid ?? '')),
        ];

        // 4. Signal readiness (attestation complete, locks held)
        if (!empty($p['ready_marker'])) {
            file_put_contents($p['ready_marker'], json_encode([
                'mode' => 'attest', 'status' => $result['status'],
                'pid' => getmypid(), 'pg_pid' => $result['postgres_backend_pid'],
                'txid' => $result['postgres_transaction_id'],
            ]));
        }

        // 5. Hold until release signal
        if (!empty($p['hold_until_path'])) {
            $deadline = time() + ($p['hold_timeout'] ?? 30);
            while (time() < $deadline) {
                if (file_exists($p['hold_until_path'])) {
                    @unlink($p['hold_until_path']);
                    break;
                }
                usleep(100000);
            }
        }

        return $result;
    });
}

function doMutate(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        \Illuminate\Support\Facades\DB::table($p['table'])
            ->where('id', $p['row_id'])
            ->update([$p['column'] => $p['value']]);

        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'
        );

        $result = [
            'mutated' => true,
            'postgres_backend_pid' => (int)($row->pid ?? 0),
            'postgres_transaction_id' => trim((string)($row->txid ?? '')),
        ];

        // Signal mutation completed
        if (!empty($p['ready_marker'])) {
            file_put_contents($p['ready_marker'], json_encode([
                'mode' => 'mutate', 'pid' => getmypid(),
                'pg_pid' => $result['postgres_backend_pid'],
            ]));
        }

        // Hold until release
        if (!empty($p['hold_until_path'])) {
            $deadline = time() + ($p['hold_timeout'] ?? 30);
            while (time() < $deadline) {
                if (file_exists($p['hold_until_path'])) {
                    @unlink($p['hold_until_path']);
                    break;
                }
                usleep(100000);
            }
        }

        return $result;
    });
}

function doAttestOther(array $p): array
{
    $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $p) {
        $context = $lockService->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $attestation = $attestService->attest($context, $p['stay_id']);

        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'
        );

        return [
            'status' => $attestation->status->value,
            'postgres_backend_pid' => (int)($row->pid ?? 0),
            'postgres_transaction_id' => trim((string)($row->txid ?? '')),
        ];
    });
}

function doHoldSource(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        // 1. Lock source row FOR UPDATE (inside transaction)
        \Illuminate\Support\Facades\DB::table($p['table'])
            ->where('id', $p['row_id'])
            ->lockForUpdate()
            ->first();

        // 2. Resolve transaction identity (inside transaction, after lock)
        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'
        );
        $result = [
            'held' => true,
            'postgres_backend_pid' => (int)($row->pid ?? 0),
            'postgres_transaction_id' => trim((string)($row->txid ?? '')),
        ];

        // 3. Signal readiness (lock acquired)
        if (!empty($p['ready_marker'])) {
            file_put_contents($p['ready_marker'], json_encode([
                'mode' => 'hold_source', 'pid' => getmypid(),
                'pg_pid' => $result['postgres_backend_pid'],
                'txid' => $result['postgres_transaction_id'],
            ]));
        }

        // 4. Hold until release
        if (!empty($p['hold_until_path'])) {
            $deadline = time() + ($p['hold_timeout'] ?? 30);
            while (time() < $deadline) {
                if (file_exists($p['hold_until_path'])) {
                    @unlink($p['hold_until_path']);
                    break;
                }
                usleep(100000);
            }
        }

        return $result;
    });
}
