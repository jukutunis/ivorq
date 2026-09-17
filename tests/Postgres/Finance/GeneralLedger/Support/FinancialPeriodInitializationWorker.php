<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Services\FinancialPeriodInitializationService;
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

if ($resultFile === '' || $barrier === '' || $runId === '') {
    fwrite(STDERR, "Missing result file, barrier, or run id\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function fpB4bSignal(string $barrier, string $name, string $runId, array $payload = []): void
{
    file_put_contents($barrier.'-'.$name.'.json', json_encode($payload + [
        'run_id' => $runId,
        'name' => $name,
        'pid' => getmypid(),
        'at' => microtime(true),
    ]));
}

function fpB4bWaitFor(string $barrier, string $name, string $runId, int $timeoutMs = 10000): array
{
    $path = $barrier.'-'.$name.'.json';
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

    throw new RuntimeException("FP_B4B_BARRIER_TIMEOUT:{$name}");
}

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

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

    $pgPid = DB::select('SELECT pg_backend_pid() as pid')[0]->pid;
    fpB4bSignal($barrier, 'ready-'.$workerId, $runId, [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ]);
    fpB4bWaitFor($barrier, 'ready-w0', $runId);
    fpB4bWaitFor($barrier, 'ready-w1', $runId);

    $period = app(FinancialPeriodInitializationService::class)->initialize($actor);

    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
        'financial_period_id' => $period->id,
        'period_year' => $period->period_year,
        'period_month' => $period->period_month,
        'row_count' => FinancialPeriod::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->count(),
    ]));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'error' => $exception->getMessage(),
        'file' => $exception->getFile().':'.$exception->getLine(),
    ]));
    exit(1);
}
