<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
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
$mode = (string) ($args['mode'] ?? '');
$workerId = (string) ($args['worker_id'] ?? 'worker');

if ($resultFile === '' || $barrier === '' || $runId === '' || $mode === '') {
    fwrite(STDERR, "Missing worker configuration\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function naA2Signal(string $barrier, string $name, string $runId, array $payload = []): void
{
    file_put_contents($barrier . '-' . $name . '.json', json_encode($payload + [
        'run_id' => $runId,
        'name' => $name,
        'php_pid' => getmypid(),
        'at' => microtime(true),
    ]));
}

function naA2WaitFor(string $barrier, string $name, string $runId, int $timeoutMs = 10000): array
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

    throw new RuntimeException("NA_A2_BARRIER_TIMEOUT:{$name}");
}

function naA2Write(string $resultFile, array $payload): void
{
    file_put_contents($resultFile, json_encode($payload + [
        'php_pid' => getmypid(),
    ]));
}

try {
    require __DIR__ . '/../../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if (! empty($args['test_now'])) {
        Carbon::setTestNow(Carbon::parse((string) $args['test_now'], 'UTC'));
    }

    $actor = User::whereKey((string) $args['actor_id'])->where('is_active', true)->first();
    if (! $actor) {
        throw new RuntimeException('Actor unavailable.');
    }

    auth()->login($actor);
    app(CurrentPropertyService::class)->setPropertyId((string) $args['property_id']);
    session([
        'current_property_id' => (string) $args['property_id'],
        'active_company_id' => (string) $args['company_id'],
    ]);

    $pgPid = (int) DB::selectOne('SELECT pg_backend_pid() as pid')->pid;

    if ($mode === 'participant_hold' || $mode === 'participant_rollback') {
        DB::beginTransaction();
        $context = app(PropertyBusinessDateOperationalLockService::class)->acquire(
            (string) $args['company_id'],
            (string) $args['property_id'],
            (array) $args['business_date_evidence']
        );
        $attestation = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
        naA2Write($resultFile, [
            'worker_id' => $workerId,
            'pg_backend_pid' => $pgPid,
            'status' => $attestation->status,
            'source_fingerprint' => $attestation->source_fingerprint,
            'transaction_bound' => $attestation->transaction_bound,
            'active_count_while_held' => NightAuditRun::withoutGlobalScopes()
                ->where('property_id', (string) $args['property_id'])
                ->where('status', 'IN_PROGRESS')
                ->count(),
        ]);
        naA2Signal($barrier, 'locks-held-' . $workerId, $runId, ['pg_backend_pid' => $pgPid]);
        naA2WaitFor($barrier, 'release-' . $workerId, $runId, 15000);
        $mode === 'participant_rollback' ? DB::rollBack() : DB::commit();
        exit(0);
    }

    if ($mode === 'start') {
        naA2Signal($barrier, 'start-ready-' . $workerId, $runId, ['pg_backend_pid' => $pgPid]);
        $run = app(NightAuditRunStartService::class)->start($actor);
        naA2Write($resultFile, [
            'worker_id' => $workerId,
            'pg_backend_pid' => $pgPid,
            'night_audit_run_id' => $run->id,
            'attempt_number' => $run->attempt_number,
            'status' => $run->status->value,
            'started_by' => (string) $run->started_by,
            'started_at' => $run->started_at?->toISOString(),
            'active_count' => NightAuditRun::withoutGlobalScopes()
                ->where('property_id', (string) $args['property_id'])
                ->where('status', 'IN_PROGRESS')
                ->count(),
        ]);
        exit(0);
    }

    throw new RuntimeException('Unknown worker mode.');
} catch (Throwable $e) {
    naA2Write($resultFile, [
        'worker_id' => $workerId,
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    exit(1);
}
