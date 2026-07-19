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
        extractStructuredError($e, $out);
        captureFailedTxIdentity($out);
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
})();

function extractStructuredError(\Throwable $e, array &$out): void
{
    $cursor = $e;
    while ($cursor !== null) {
        if ($cursor instanceof \Illuminate\Database\QueryException) {
            $out['sqlstate'] = $cursor->errorInfo[0] ?? null;
            $out['database_message'] = $cursor->getMessage();
            $out['previous_exception_class'] = $cursor->getPrevious() ? get_class($cursor->getPrevious()) : null;
        }
        if ($cursor instanceof \DomainException && empty($out['domain_error'])) {
            $out['domain_error'] = $cursor->getMessage();
        }
        $cursor = $cursor->getPrevious();
    }
}

function captureFailedTxIdentity(array &$out): void
{
    try {
        $row = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid, txid_current()::text AS txid');
        $out['postgres_backend_pid'] = (int)($row->pid ?? 0);
        $out['postgres_transaction_id'] = trim((string)($row->txid ?? ''));
    } catch (\Throwable) {}
}

function txIdentity(): array
{
    $row = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid, txid_current()::text AS txid');
    return [(int)($row->pid ?? 0), trim((string)($row->txid ?? ''))];
}

function barrier(string $path, int $timeoutS): void
{
    $deadline = time() + $timeoutS;
    $released = false;
    while (time() < $deadline) {
        if (file_exists($path)) { @unlink($path); $released = true; break; }
        usleep(100000);
    }
    if (!$released) throw new \RuntimeException("Barrier timeout after {$timeoutS}s: {$path}");
}

function signal(string $path, array $data): void { if ($path !== '') file_put_contents($path, json_encode($data)); }

function doAttest(array $p): array
{
    $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockSvc, $attSvc, $p) {
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $att = $attSvc->attest($ctx, $p['stay_id']);
        [$pid, $txid] = txIdentity();
        $r = ['status'=>$att->status->value,'fingerprint'=>$att->source_fingerprint,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'attest','status'=>$r['status'],'pg_pid'=>$pid,'txid'=>$txid]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        return $r;
    });
}

function doMutateAndHold(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        $row = \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->lockForUpdate()->first();
        \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->update([$p['column'] => $p['value']]);
        [$pid, $txid] = txIdentity();
        $r = ['mutated'=>true,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'mutate_and_hold','pg_pid'=>$pid,'txid'=>$txid,'column'=>$p['column'],'value'=>$p['value']]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        return $r;
    });
}

function doAttestOther(array $p): array
{
    $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
    return \Illuminate\Support\Facades\DB::transaction(function () use ($lockSvc, $attSvc, $p) {
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $att = $attSvc->attest($ctx, $p['stay_id']);
        [$pid, $txid] = txIdentity();
        return ['status'=>$att->status->value,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
    });
}

function doHoldSource(array $p): array
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
        \Illuminate\Support\Facades\DB::table($p['table'])->where('id', $p['row_id'])->lockForUpdate()->first();
        [$pid, $txid] = txIdentity();
        $r = ['held'=>true,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'hold_source','pg_pid'=>$pid,'txid'=>$txid]);
        if (!empty($p['hold_until_path'])) barrier($p['hold_until_path'], $p['hold_timeout'] ?? 30);
        return $r;
    });
}

function doAttestAndRollback(array $p): array
{
    $lockSvc = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
    $attSvc  = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $ctx = $lockSvc->acquire($p['company_id'], $p['property_id'], $p['expected_evidence']);
        $att = $attSvc->attest($ctx, $p['stay_id']);
        [$pid, $txid] = txIdentity();
        $r = ['rolled_back'=>true,'fingerprint'=>$att->source_fingerprint,'postgres_backend_pid'=>$pid,'postgres_transaction_id'=>$txid];
        signal($p['ready_marker'] ?? '', ['mode'=>'attest_and_rollback','pg_pid'=>$pid,'txid'=>$txid]);
        barrier($p['hold_until_path'] ?? '', $p['hold_timeout'] ?? 30);
        \Illuminate\Support\Facades\DB::rollBack();
        return $r;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}
