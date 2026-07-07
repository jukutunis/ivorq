<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || !file_exists($configPath)) { fwrite(STDERR, "CONFIG_MISSING\n"); exit(1); }

$config = json_decode(file_get_contents($configPath), true);
if (!$config) { fwrite(STDERR, "CONFIG_INVALID\n"); exit(1); }

$workerId = $config['worker_id'];
$barrierDir = $config['barrier_dir'];
$resultFile = $config['result_file'];
$request = $config['request'];
$dbName = $config['db_name'] ?? null;
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';
$basePath = $config['base_path'];

require $basePath . '/vendor/autoload.php';
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($dbName) {
    config(['database.connections.pgsql.database' => $dbName]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    \Illuminate\Support\Facades\DB::reconnect('pgsql');
}

$result = ['worker_id' => $workerId, 'pid' => getmypid(), 'pg_backend_pid' => null,
    'idempotency_key' => $request['lines'][0]['idempotency_key'] ?? null,
    'outcome' => 'UNKNOWN', 'receipt_id' => null, 'movement_ids' => [],
    'commercial_evidence_count' => 0,
    'error_class' => null, 'error_message' => null, 'po_line_lock_acquired' => false];

try {
    $pgPid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() as pid');
    $result['pg_backend_pid'] = $pgPid[0]->pid ?? null;
} catch (\Throwable $e) {}

try {
    $actor = \Modules\Foundation\User\Models\User::findOrFail($request['actor_id']);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($request['property_id']);

    $svc = app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class);
    $svc->confirm($actor, 'inventory-goods-receipt-posting', 'password', $request['company_id'], $request['property_id']);

    touch($barrierDir . '/ready-' . $workerId);

    $startFile = $barrierDir . '/start.signal';
    for ($i = 0; $i < 6000; $i++) { if (file_exists($startFile)) break; usleep(10000); }

    touch($barrierDir . '/locking-' . $workerId);

    $poLineId = $request['lines'][0]['purchase_order_line_id'];
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        \Modules\Operations\Purchasing\Models\PurchaseOrderLine::query()
            ->whereKey($poLineId)->lockForUpdate()->firstOrFail();
        $result['po_line_lock_acquired'] = true;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }

    $postingService = app(\Modules\Operations\Inventory\Services\ControlledGoodsReceiptPostingService::class);
    $receipt = $postingService->createDraft($request['purchase_order_id'], $request['lines'], $request['actor_id']);
    $receipt->status = \Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum::ConfirmationPending;
    $receipt->save();
    $posted = $postingService->post($receipt, $request['actor_id']);

    \Illuminate\Support\Facades\DB::commit();

    $result['outcome'] = 'POSTED';
    $result['receipt_id'] = $posted->id;
    foreach ($posted->lines as $line) {
        if ($line->stock_movement_id) { $result['movement_ids'][] = $line->stock_movement_id; }
    }
    $result['commercial_evidence_count'] = \Illuminate\Support\Facades\DB::table('goods_receipt_line_commercial_evidences')->count();
    touch($barrierDir . '/posted-' . $workerId);

} catch (\RuntimeException $e) {
    try { \Illuminate\Support\Facades\DB::rollBack(); } catch (\Throwable $ex) {}
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['error_class'] = get_class($e);
    $result['error_message'] = substr($e->getMessage(), 0, 200);
    touch($barrierDir . '/posted-' . $workerId);
} catch (\Throwable $e) {
    try { \Illuminate\Support\Facades\DB::rollBack(); } catch (\Throwable $ex) {}
    $result['outcome'] = 'ERROR';
    $result['error_class'] = get_class($e);
    $result['error_message'] = substr($e->getMessage(), 0, 500);
    touch($barrierDir . '/posted-' . $workerId);
}

@file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
exit(0);
