<?php

/**
 * GLF-E Concurrency Worker — separate-process CLI.
 * Usage: php worker.php <base_path> <data_file>
 * Outputs one JSON line to stdout.
 */
(function () {
    global $argv;
    $basePath = $argv[1] ?? ''; $dataFile = $argv[2] ?? '';
    if (empty($basePath) || empty($dataFile) || !file_exists($dataFile)) { fwrite(STDERR, "Usage: php worker.php <base_path> <data_file>\n"); exit(1); }

    chdir($basePath);
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $p = json_decode(file_get_contents($dataFile), true) ?: [];
    $mode = $p['mode'] ?? 'attest';
    $startedAt = date('c');
    $phpPid = getmypid();

    // Bind CLEAR participation ports for concurrency workers
    bindClearParticipationPorts($app);

    try {
        $result = match ($mode) {
            'attest'             => doAttest($p),
            'mutate_and_hold'    => doMutateAndHold($p),
            'attest_other'       => doAttestOther($p),
            'hold_source'        => doHoldSource($p),
            'attest_and_rollback'=> doAttestAndRollback($p),
            default              => throw new \RuntimeException("Unknown mode: {$mode}"),
        };
        $result['mode'] = $mode; $result['php_pid'] = $phpPid;
        $result['started_at'] = $startedAt; $result['completed_at'] = date('c');
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (\Throwable $e) {
        $out = ['mode'=>$mode,'error'=>$e->getMessage(),'class'=>get_class($e),
                'php_pid'=>$phpPid,'started_at'=>$startedAt,'completed_at'=>date('c')];
        // Use captured pre-rollback identity if available
        if (isset($e->workerPid)) $out['postgres_backend_pid'] = $e->workerPid;
        if (isset($e->workerTxid)) $out['postgres_transaction_id'] = $e->workerTxid;
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
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_pc','source_identifiers'=>[]]; }
        }
    );
    $app->singleton(
        \Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort::class,
        fn() => new class implements \Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_sh','source_identifiers'=>[]]; }
        }
    );
    $app->singleton(
        \Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort::class,
        fn() => new class implements \Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp_cs','source_identifiers'=>[]]; }
        }
    );
}

function extractStructuredError(\Throwable $e, array &$out): void
{
    $cursor = $e;
    while ($cursor !== null) {
        if ($cursor instanceof \Illuminate\Database\QueryException) {
            $out['sqlstate'] = $cursor->errorInfo[0] ?? null;
            $out['database_message'] = $cursor->getMessage();
            if ($cursor->getPrevious()) $out['previous_exception_class'] = get_class($cursor->getPrevious());
        }
        if ($cursor instanceof \DomainException && empty($out['domain_error'])) $out['domain_error'] = $cursor->getMessage();
        // GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT is a RuntimeException wrapping QueryException
        if ($cursor instanceof \RuntimeException && str_contains($cursor->getMessage(), 'GLF_E_') && empty($out['domain_error'])) {
            $out['domain_error'] = $cursor->getMessage();
            if ($cursor->getPrevious()) {
                $prevClass = get_class($cursor->getPrevious());
                $out['previous_exception_class'] = $prevClass;
                // Walk into QueryException for sqlstate (it's the immediate previous)
                if ($cursor->getPrevious() instanceof \Illuminate\Database\QueryException) {
                    $out['sqlstate'] = $cursor->getPrevious()->errorInfo[0] ?? null;
                    $out['database_message'] = $cursor->getPrevious()->getMessage();
                }
            }
        }
        $cursor = $cursor->getPrevious();
    }
}

function txIdentity(): array { $row = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid, txid_current()::text AS txid'); return [(int)($row->pid ?? 0), trim((string)($row->txid ?? ''))]; }
function barrier(string $path, int $timeoutS): void { $dl=time()+$timeoutS; $ok=false; while(time()<$dl){if(file_exists($path)){@unlink($path);$ok=true;break;}usleep(100000);} if(!$ok)throw new \RuntimeException("Barrier timeout after {$timeoutS}s: {$path}"); }
function signal(string $path, array $data): void { if($path!=='')file_put_contents($path,json_encode($data)); }

function doAttest(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    // Capture identity BEFORE lock attempt (survives rollback)
    [$pid, $txid] = txIdentity();
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $att = $attSvc->attest($ctx, $p['stay_id']);
        $r = ['status'=>$att->status->value,'fingerprint'=>$att->source_fingerprint,'canonical_aggregate_balance'=>$att->canonical_aggregate_balance,'blocker_codes'=>$att->blocker_codes,'review_reasons'=>$att->review_reasons,'evidence_unavailable_codes'=>$att->evidence_unavailable_codes,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'attest','status'=>$r['status'],'pg_pid'=>$pid,'txid'=>$txid]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        // Attach captured identity to exception so extractStructuredError can use it
        $e->workerPid = $pid;
        $e->workerTxid = $txid;
        throw $e;
    }
}

function doMutateAndHold(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->lockForUpdate()->first();
        \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->update([$p['column'] => $p['value']]);
        [$pid, $txid] = txIdentity();
        $r = ['mutated'=>true,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'mutate_and_hold','pg_pid'=>$pid,'txid'=>$txid,'column'=>$p['column'],'value'=>$p['value']]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) { \Illuminate\Support\Facades\DB::rollBack(); throw $e; }
}

function doAttestOther(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        [$pid, $txid] = txIdentity();
        $att = $attSvc->attest($ctx, $p['stay_id']);
        $r = ['status'=>$att->status->value,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) { \Illuminate\Support\Facades\DB::rollBack(); throw $e; }
}

function doHoldSource(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->lockForUpdate()->first();
        [$pid, $txid] = txIdentity();
        $r = ['held'=>true,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'hold_source','pg_pid'=>$pid,'txid'=>$txid]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        \Illuminate\Support\Facades\DB::commit();
        return $r;
    } catch (\Throwable $e) { \Illuminate\Support\Facades\DB::rollBack(); throw $e; }
}

function doAttestAndRollback(array $p): array
{
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        [$pid, $txid] = txIdentity();
        $att = $attSvc->attest($ctx, $p['stay_id']);
        $r = ['rolled_back'=>true,'fingerprint'=>$att->source_fingerprint,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'attest_and_rollback','pg_pid'=>$pid,'txid'=>$txid]);
        barrier($p['hold_until_path'] ?? '', $p['hold_timeout'] ?? 30);
        \Illuminate\Support\Facades\DB::rollBack();
        return $r;
    } catch (\Throwable $e) { \Illuminate\Support\Facades\DB::rollBack(); throw $e; }
}
