<?php

/**
 * Test-only Inventory Goods Receipt Concurrency Worker
 *
 * Reads configuration from a JSON file to avoid proc_open env var issues on Windows.
 * Boots its own Laravel application, authenticates, confirms, and posts.
 */

declare(strict_types=1);

$configPath = getenv('IVORQ_CONFIG_FILE') ?: ($argv[1] ?? '');

if ($configPath === '' || !file_exists($configPath)) {
    fwrite(STDERR, "WORKER_CONFIG_MISSING\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (!$config) {
    fwrite(STDERR, "WORKER_CONFIG_INVALID\n");
    exit(1);
}

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=pgsql');
putenv('DB_DATABASE=ivorq_testing');

$dbUsername = $config['db_username'] ?? getenv('DB_USERNAME');
$dbPassword = $config['db_password'] ?? getenv('DB_PASSWORD');
if ($dbUsername) { putenv('DB_USERNAME=' . $dbUsername); }
if ($dbPassword) { putenv('DB_PASSWORD=' . $dbPassword); }

$workerId = $config['worker_id'];
$barrierDir = $config['barrier_dir'];
$resultFile = $config['result_file'];
$request = $config['request'];

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result = [
    'worker_id' => $workerId,
    'pid' => getmypid(),
    'pg_backend_pid' => null,
    'idempotency_key' => $request['lines'][0]['idempotency_key'] ?? null,
    'outcome' => 'UNKNOWN',
    'receipt_id' => null,
    'movement_ids' => [],
    'error_class' => null,
    'error_message' => null,
];

try {
    $pgPid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() as pid');
    $result['pg_backend_pid'] = $pgPid[0]->pid ?? null;
} catch (\Throwable $e) {
}

try {
    $actorId = $request['actor_id'];
    $propertyId = $request['property_id'];
    $companyId = $request['company_id'];

    $actor = \Modules\Foundation\User\Models\User::findOrFail($actorId);
    \Illuminate\Support\Facades\Auth::login($actor);

    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);

    $svc = app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class);
    $svc->confirm($actor, 'inventory-goods-receipt-posting', 'password', $companyId, $propertyId);

    $readyFile = $barrierDir . DIRECTORY_SEPARATOR . 'ready-' . $workerId;
    touch($readyFile);

    $startFile = $barrierDir . DIRECTORY_SEPARATOR . 'start';
    $startWaitEnd = time() + 60;
    while (time() < $startWaitEnd) {
        if (file_exists($startFile)) { break; }
        usleep(10000);
    }

    $postingFile = $barrierDir . DIRECTORY_SEPARATOR . 'posting-' . $workerId;
    touch($postingFile);

    $postingService = app(\Modules\Operations\Inventory\Services\ControlledGoodsReceiptPostingService::class);

    $receipt = $postingService->createDraft(
        $request['purchase_order_id'],
        $request['lines'],
        $actorId
    );

    $receipt->status = \Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum::ConfirmationPending;
    $receipt->save();

    $posted = $postingService->post($receipt, $actorId);

    $result['outcome'] = 'POSTED';
    $result['receipt_id'] = $posted->id;
    $movementIds = [];
    foreach ($posted->lines as $line) {
        if ($line->stock_movement_id) {
            $movementIds[] = $line->stock_movement_id;
        }
    }
    $result['movement_ids'] = $movementIds;

} catch (\RuntimeException $e) {
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['error_class'] = get_class($e);
    $result['error_message'] = substr($e->getMessage(), 0, 200);
} catch (\Throwable $e) {
    $result['outcome'] = 'ERROR';
    $result['error_class'] = get_class($e);
    $result['error_message'] = substr($e->getMessage(), 0, 500);
}

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);

echo $result['outcome'] . "\n";

exit(0);
