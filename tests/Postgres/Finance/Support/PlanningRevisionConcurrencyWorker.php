<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Budgeting\Services\BudgetService;

$argsFile = $argv[1] ?? '';
if ($argsFile === '' || ! is_file($argsFile)) {
    fwrite(STDERR, "Missing args file\n");
    exit(1);
}

$args = json_decode((string) file_get_contents($argsFile), true);
if (! is_array($args)) {
    fwrite(STDERR, "Invalid args JSON\n");
    exit(1);
}

$resultFile = (string) ($args['result_file'] ?? '');
$barrier = (string) ($args['barrier'] ?? '');
$runId = (string) ($args['run_id'] ?? '');
$workerId = (string) ($args['worker_id'] ?? '');
$versionId = (string) ($args['version_id'] ?? '');
$checkerId = (string) ($args['checker_id'] ?? '');

if ($resultFile === '' || $barrier === '' || $runId === '' || $workerId === '' || $versionId === '' || $checkerId === '') {
    fwrite(STDERR, "Missing worker arguments\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function finP03R1Signal(string $barrier, string $name, string $runId): void
{
    file_put_contents($barrier.'-'.$name.'.json', json_encode(['run_id' => $runId, 'name' => $name, 'pid' => getmypid()]));
}

function finP03R1WaitFor(string $barrier, string $name, string $runId): void
{
    $path = $barrier.'-'.$name.'.json';
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                return;
            }
        }
        usleep(25000);
    }

    throw new RuntimeException("FIN_P03_R1_BARRIER_TIMEOUT:{$name}");
}

try {
    require __DIR__.'/../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $pgPid = DB::selectOne('SELECT pg_backend_pid() as pid')->pid;
    finP03R1Signal($barrier, 'ready-'.$workerId, $runId);
    finP03R1WaitFor($barrier, 'ready-w0', $runId);
    finP03R1WaitFor($barrier, 'ready-w1', $runId);

    app(BudgetService::class)->rejectVersion($versionId, $checkerId, 'Concurrent correction');
    $payload = ['status' => 'rejected'];
    file_put_contents($resultFile, json_encode($payload + [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ]));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'status' => 'harness_error',
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'error' => $exception->getMessage(),
        'file' => $exception->getFile().':'.$exception->getLine(),
    ]));
    exit(1);
}
