<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Services\DeferredCostDeliveryConsumer;

$configPath = $argv[1] ?? '';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($config)) {
    exit(2);
}

require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
DB::purge('pgsql');
DB::reconnect('pgsql');

$result = ['status' => 'ERROR', 'code' => null, 'error' => null];
try {
    touch($config['barrier_dir'].'/ready-'.$config['worker_id']);
    $start = $config['barrier_dir'].'/start.signal';
    for ($attempt = 0; $attempt < 12000 && ! is_file($start); $attempt++) {
        usleep(10000);
    }
    touch($config['barrier_dir'].'/calling-'.$config['worker_id']);
    $consumed = app(DeferredCostDeliveryConsumer::class)->consume($config['outbox_id']);
    $result['status'] = $consumed->status;
    $result['code'] = $consumed->code;
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.substr($exception->getMessage(), 0, 300);
}

file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
touch($config['barrier_dir'].'/done-'.$config['worker_id']);
