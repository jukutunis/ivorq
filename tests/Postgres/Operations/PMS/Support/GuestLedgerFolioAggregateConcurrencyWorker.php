<?php

declare(strict_types=1);

$cfgPath = $argv[1] ?? '';
if ($cfgPath === '' || ! file_exists($cfgPath)) {
    exit(1);
}

$cfg = json_decode(file_get_contents($cfgPath), true);
$workerId = $cfg['worker_id'];
$scenario = $cfg['scenario'];
$barrierDir = $cfg['barrier_dir'];
$resultFile = $cfg['result_file'];
$dbName = $cfg['db_name'];
$basePath = $cfg['base_path'];
$fixture = $cfg['fixture'];

require $basePath . '/vendor/autoload.php';
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.connections.pgsql.database' => $dbName]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::reconnect('pgsql');

$actor = \Modules\Foundation\User\Models\User::findOrFail($fixture['actor_id']);
\Illuminate\Support\Facades\Auth::login($actor);
app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
session(['current_property_id' => $fixture['property_id']]);

$aggregate = app(\Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService::class);

$result = [
    'worker_id' => $workerId,
    'pid' => getmypid(),
    'pg_backend_pid' => -1,
    'outcome' => 'UNKNOWN',
    'folio_id' => null,
    'folio_number' => null,
    'window_number' => null,
    'error' => null,
];

touch($barrierDir . "/ready-{$workerId}");

// Wait for start signal
$startFile = $barrierDir . "/start-{$scenario}.signal";
$end = time() + 90;
while (time() < $end && ! file_exists($startFile)) {
    usleep(20000);
}
if (! file_exists($startFile)) {
    $result['outcome'] = 'TIMEOUT_NO_START';
    file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
    touch($barrierDir . "/result-{$workerId}-ready");
    exit(1);
}

try {
    // Get PG backend PID
    $pdo = \Illuminate\Support\Facades\DB::connection('pgsql')->getPdo();
    $stmt = $pdo->query('SELECT pg_backend_pid()');
    $result['pg_backend_pid'] = (int) $stmt->fetchColumn();

    if ($scenario === 'same_key') {
        // Both workers use the SAME idempotency key
        $folio = $aggregate->openWindow(
            $actor,
            $fixture['reservation_id'],
            'concurrency-same-key',
        );

        $result['folio_id'] = $folio->id;
        $result['folio_number'] = $folio->folio_number;
        $result['window_number'] = $folio->window_number;
        $result['outcome'] = 'FOLIO_OPENED';
    } elseif ($scenario === 'different_key') {
        // Each worker uses a DIFFERENT idempotency key
        $key = 'concurrency-diff-key-' . $workerId;
        $folio = $aggregate->openWindow(
            $actor,
            $fixture['reservation_id'],
            $key,
        );

        $result['folio_id'] = $folio->id;
        $result['folio_number'] = $folio->folio_number;
        $result['window_number'] = $folio->window_number;
        $result['outcome'] = 'FOLIO_OPENED';
    }
} catch (Throwable $e) {
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['error'] = $e->getMessage();
}

// Signal completion
file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
touch($barrierDir . "/result-{$workerId}-ready");
exit(0);
