<?php

use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateInitializationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

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
$workerId = (string) ($args['worker_id'] ?? 'worker');
$propertyId = (string) ($args['property_id'] ?? '');
$companyId = (string) ($args['company_id'] ?? '');
$actorId = (string) ($args['actor_id'] ?? '');
$testNow = (string) ($args['test_now'] ?? '');

if ($resultFile === '' || $barrier === '' || $runId === '') {
    fwrite(STDERR, "Missing result file, barrier, or run id\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function bdA1Signal(string $barrier, string $name, string $runId, array $payload = []): void
{
    file_put_contents($barrier . '-' . $name . '.json', json_encode($payload + [
        'run_id' => $runId,
        'name' => $name,
        'pid' => getmypid(),
        'at' => microtime(true),
    ]));
}

function bdA1WaitFor(string $barrier, string $name, string $runId, int $timeoutMs = 8000): array
{
    $path = $barrier . '-' . $name . '.json';
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                return $payload;
            }
        }
        usleep(25000);
    }

    throw new RuntimeException("BD_A1_BARRIER_TIMEOUT:{$name}");
}

try {
    require __DIR__ . '/../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if ($testNow !== '') {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($testNow, 'UTC'));
    }

    $actor = User::whereKey($actorId)->where('is_active', true)->first();
    if (! $actor) {
        throw new RuntimeException('Actor unavailable.');
    }

    auth()->login($actor);
    app(CurrentPropertyService::class)->setPropertyId($propertyId);
    session([
        'current_property_id' => $propertyId,
        'active_company_id' => $companyId,
    ]);

    $pgPid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() as pid')[0]->pid;
    bdA1Signal($barrier, 'ready-' . $workerId, $runId, [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ]);
    bdA1WaitFor($barrier, 'ready-w0', $runId);
    bdA1WaitFor($barrier, 'ready-w1', $runId);

    $businessDate = app(PropertyBusinessDateInitializationService::class)->initialize($actor);

    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
        'property_business_date_id' => $businessDate->id,
        'business_date' => $businessDate->business_date->format('Y-m-d'),
        'opened_by' => (string) $businessDate->opened_by,
        'opened_at' => $businessDate->opened_at?->toISOString(),
        'row_count' => PropertyBusinessDate::withoutGlobalScopes()->where('property_id', $propertyId)->count(),
        'active_property_id_present' => session()->has('active_property_id'),
    ]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]));
    exit(1);
}
