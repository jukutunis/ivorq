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
    'idempotency_key' => $request['idempotency_key'] ?? null,
    'outcome' => 'UNKNOWN', 'movement_id' => null,
    'error_class' => null, 'error_message' => null];

try {
    $pgPid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() as pid');
    $result['pg_backend_pid'] = $pgPid[0]->pid ?? null;
} catch (\Throwable $e) {}

try {
    $actor = \Modules\Foundation\User\Models\User::findOrFail($request['actor_id']);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($request['property_id']);

    touch($barrierDir . '/ready-' . $workerId);

    $startFile = $barrierDir . '/start.signal';
    for ($i = 0; $i < 6000; $i++) { if (file_exists($startFile)) break; usleep(10000); }

    touch($barrierDir . '/locking-' . $workerId);

    $postingService = app(\Modules\Operations\Inventory\Services\InventoryLedgerPostingService::class);
    $movement = $postingService->post([
            'property_id' => $request['property_id'],
            'inventory_item_id' => $request['inventory_item_id'],
            'inventory_location_id' => $request['inventory_location_id'],
            'inventory_unit_id' => $request['inventory_unit_id'],
            'movement_type' => \Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum::IssueConsumption,
            'direction' => \Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum::Out,
            'source_leg' => \Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum::Primary,
            'quantity' => $request['quantity'],
            'source_domain' => $request['source_domain'],
            'source_type' => $request['source_type'],
            'source_id' => $request['source_id'],
            'correlation_id' => $request['correlation_id'],
            'idempotency_key' => $request['idempotency_key'],
            'occurred_at' => \Illuminate\Support\Carbon::now(),
            'created_by' => $request['actor_id'],
    ]);

    $result['outcome'] = 'POSTED';
    $result['movement_id'] = $movement->id;
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
