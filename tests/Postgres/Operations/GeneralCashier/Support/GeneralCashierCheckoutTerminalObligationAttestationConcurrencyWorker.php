<?php

/**
 * GC-A2 Concurrency Worker — separate-process CLI.
 * Usage: php worker.php <base_path> <data_file>
 * Outputs one JSON line to stdout.
 */
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

    $p = json_decode(file_get_contents($dataFile), true) ?: [];
    $mode = $p['mode'] ?? 'gc_attest';
    $startedAt = date('c');
    $phpPid = getmypid();

    // Bind CLEAR participation ports for GLF-E attestation in concurrency workers
    bindClearParticipationPorts($app);

    try {
        $result = match ($mode) {
            'gc_attest'                     => doGcAttest($p),
            'gc_hold_session'               => doGcHoldSession($p),
            'gc_attest_other'                => doGcAttestOther($p),
            'gc_attest_savepoint_rollback'   => doGcAttestSavepointRollback($p),
            'gc_conflicting_lock_attempt'    => doGcConflictingLockAttempt($p),
            default                          => throw new \RuntimeException("Unknown mode: {$mode}"),
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
        if (isset($e->workerPid)) {
            $out['postgres_backend_pid'] = $e->workerPid;
        }
        if (isset($e->workerTxid)) {
            $out['postgres_transaction_id'] = $e->workerTxid;
        }
        extractStructuredError($e, $out);
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
})();

function bindClearParticipationPorts($app): void
{
    $app->singleton(
        \Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort::class,
        fn() => new class implements \Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_pc', 'source_identifiers' => []]; }
        }
    );
    $app->singleton(
        \Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort::class,
        fn() => new class implements \Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_sh', 'source_identifiers' => []]; }
        }
    );
    $app->singleton(
        \Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort::class,
        fn() => new class implements \Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_cs', 'source_identifiers' => []]; }
        }
    );
}

function extractStructuredError(\Throwable $e, array &$out): void
{
    $cursor = $e;
    while ($cursor !== null) {
        if ($cursor instanceof \Illuminate\Database\QueryException) {
            if (empty($out['sqlstate'])) {
                $out['sqlstate'] = $cursor->errorInfo[0] ?? null;
            }
            if (empty($out['database_message'])) {
                $out['database_message'] = $cursor->getMessage();
            }
            if ($cursor->getPrevious() && empty($out['previous_exception_class'])) {
                $out['previous_exception_class'] = get_class($cursor->getPrevious());
            }
        }
        if ($cursor instanceof \DomainException && empty($out['domain_error'])) {
            $out['domain_error'] = $cursor->getMessage();
        }
        if ($cursor instanceof \RuntimeException && str_contains($cursor->getMessage(), 'GC_A2_') && empty($out['domain_error'])) {
            $out['domain_error'] = $cursor->getMessage();
            if ($cursor->getPrevious()) {
                $prevClass = get_class($cursor->getPrevious());
                $out['previous_exception_class'] = $prevClass;
                if ($cursor->getPrevious() instanceof \Illuminate\Database\QueryException) {
                    $out['sqlstate'] = $cursor->getPrevious()->errorInfo[0] ?? null;
                    $out['database_message'] = $cursor->getPrevious()->getMessage();
                }
            }
        }
        $cursor = $cursor->getPrevious();
    }
}

function txIdentity(): array
{
    $row = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid, txid_current()::text AS txid');
    return [(int) ($row->pid ?? 0), trim((string) ($row->txid ?? ''))];
}

function barrier(string $path, int $timeoutS): void
{
    $dl = time() + $timeoutS;
    $ok = false;
    while (time() < $dl) {
        if (file_exists($path)) {
            @unlink($path);
            $ok = true;
            break;
        }
        usleep(100000);
    }
    if (! $ok) {
        throw new \RuntimeException("Barrier timeout after {$timeoutS}s: {$path}");
    }
}

function signal(string $path, array $data): void
{
    if ($path !== '') {
        file_put_contents($path, json_encode($data));
    }
}

function doGcAttest(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    [$pid, $txid] = txIdentity();
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $glfSvc = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $gcSvc = app(\Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService::class);

        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $glf = $glfSvc->attest($ctx, $p['stay_id']);
        $gc = $gcSvc->attest($ctx, $glf);

        $r = [
            'status' => $gc->status->value,
            'fingerprint' => $gc->source_fingerprint,
            'blocker_codes' => $gc->blocker_codes,
            'evidence_unavailable_codes' => $gc->evidence_unavailable_codes,
            'postgres_backend_pid' => $pid,
            'postgres_transaction_id' => $txid,
        ];
        signal($p['ready_marker'] ?? '', ['mode' => 'gc_attest', 'status' => $r['status'], 'pg_pid' => $pid, 'txid' => $txid]);
        if (! empty($p['hold_until_path'])) {
            barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        }
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        $e->workerPid = $pid;
        $e->workerTxid = $txid;
        throw $e;
    }
}

function doGcHoldSession(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        \Illuminate\Support\Facades\DB::table('cashier_sessions')
            ->where('id', $p['session_id'])
            ->where('property_id', $p['property_id'])
            ->lockForUpdate()
            ->first();
        [$pid, $txid] = txIdentity();
        $r = ['held' => true, 'postgres_backend_pid' => $pid, 'postgres_transaction_id' => $txid];
        signal($p['ready_marker'] ?? '', ['mode' => 'gc_hold_session', 'pg_pid' => $pid, 'txid' => $txid]);
        if (! empty($p['hold_until_path'])) {
            barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        }
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}

function doGcAttestOther(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $glfSvc = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $gcSvc = app(\Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService::class);

        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        [$pid, $txid] = txIdentity();
        $glf = $glfSvc->attest($ctx, $p['stay_id']);
        $gc = $gcSvc->attest($ctx, $glf);

        $r = ['status' => $gc->status->value, 'postgres_backend_pid' => $pid, 'postgres_transaction_id' => $txid];
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}

function doGcAttestSavepointRollback(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction(); // outer transaction
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $glfSvc = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $gcSvc = app(\Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService::class);

        // Acquire NA-A2 context OUTSIDE savepoint
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);

        // Issue GLF-E OUTSIDE savepoint
        $glf = $glfSvc->attest($ctx, $p['stay_id']);

        [$pidBefore, $txidBefore] = txIdentity();

        // Start nested savepoint
        \Illuminate\Support\Facades\DB::beginTransaction();

        // Issue GC-A2 INSIDE savepoint
        $gc = $gcSvc->attest($ctx, $glf);

        signal($p['ready_marker'] ?? '', [
            'mode' => 'gc_attest_savepoint_rollback',
            'pg_pid' => $pidBefore,
            'txid' => $txidBefore,
        ]);

        // Hold until signaled
        barrier($p['hold_until_path'] ?? '', $p['hold_timeout'] ?? 30);

        // Rollback only the nested savepoint
        \Illuminate\Support\Facades\DB::rollBack();

        [$pidAfter, $txidAfter] = txIdentity();

        // Signal rollback complete, outer tx still open
        signal($p['rollback_marker'] ?? '', ['mode' => 'savepoint_rolled_back_outer_still_open']);

        // Validate retained GC-A2 (must reject)
        $gcValidatorResult = 'rejected';
        $gcValidatorExceptionClass = '';
        $gcValidatorError = '';
        try {
            $gcSvc->assertIssuedForCurrentTransaction($ctx, $glf, $gc);
            $gcValidatorResult = 'accepted';
        } catch (\DomainException $e) {
            $gcValidatorExceptionClass = get_class($e);
            $gcValidatorError = $e->getMessage();
        }

        // Validate GLF-E (must remain valid)
        $glfValidatorResult = 'rejected';
        try {
            $glfSvc->assertIssuedForCurrentTransaction($ctx, $glf);
            $glfValidatorResult = 'accepted';
        } catch (\DomainException $e) {
            // expected to remain valid
        }

        // Validate NA-A2 (must remain valid)
        $naA2ValidatorResult = 'rejected';
        try {
            $lockSvc->assertIssuedForCurrentTransaction($ctx);
            $naA2ValidatorResult = 'accepted';
        } catch (\DomainException $e) {
            // expected to remain valid
        }

        // Final hold
        barrier($p['final_release_path'] ?? '', $p['hold_timeout'] ?? 30);

        \Illuminate\Support\Facades\DB::commit();

        return [
            'rolled_back' => true,
            'postgres_backend_pid' => $pidBefore,
            'postgres_transaction_id' => $txidBefore,
            'postgres_backend_pid_before' => $pidBefore,
            'postgres_backend_pid_after' => $pidAfter,
            'postgres_transaction_id_before' => $txidBefore,
            'postgres_transaction_id_after' => $txidAfter,
            'gc_validator_result' => $gcValidatorResult,
            'gc_validator_exception_class' => $gcValidatorExceptionClass,
            'gc_validator_error' => $gcValidatorError,
            'glf_validator_result' => $glfValidatorResult,
            'na_a2_validator_result' => $naA2ValidatorResult,
            'gc_attestation_retained' => true,
            'glf_attestation_retained' => true,
            'outer_transaction_still_open' => true,
        ];
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}

function doGcConflictingLockAttempt(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $lockAttemptStartedAt = date('c');
        [$pid, $txid] = txIdentity();

        // Publish lock-attempt marker BEFORE the query
        signal($p['lock_attempt_marker'] ?? '', [
            'mode' => 'gc_conflicting_lock_attempt',
            'postgres_backend_pid' => $pid,
            'postgres_transaction_id' => $txid,
            'table' => $p['table'] ?? 'cashier_sessions',
            'session_id' => $p['session_id'] ?? '',
            'lock_attempt_started_at' => $lockAttemptStartedAt,
        ]);

        $blockedStart = microtime(true);

        $row = \Illuminate\Support\Facades\DB::table($p['table'] ?? 'cashier_sessions')
            ->where('id', $p['session_id'] ?? '')
            ->where('property_id', $p['property_id'] ?? '')
            ->lockForUpdate()
            ->first();

        $lockAcquiredAt = date('c');
        $blockedMs = (int) ((microtime(true) - $blockedStart) * 1000);

        $r = [
            'proceeded' => true,
            'row_found' => $row !== null,
            'postgres_backend_pid' => $pid,
            'postgres_transaction_id' => $txid,
            'lock_attempt_started_at' => $lockAttemptStartedAt,
            'lock_acquired_at' => $lockAcquiredAt,
            'blocked_ms' => $blockedMs,
        ];
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}
