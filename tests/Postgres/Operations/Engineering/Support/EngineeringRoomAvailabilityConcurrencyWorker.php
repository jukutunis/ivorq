<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || ! file_exists($configPath)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (! $config) {
    fwrite(STDERR, "CONFIG_INVALID\n");
    exit(1);
}

$workerId = $config['worker_id'];
$scenario = $config['scenario'];
$barrierDir = $config['barrier_dir'];
$resultFile = $config['result_file'];
$fixture = $config['fixture'];
$dbName = $config['db_name'];
$basePath = $config['base_path'];

require $basePath . '/vendor/autoload.php';
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.connections.pgsql.database' => $dbName]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::reconnect('pgsql');

$result = [
    'worker_id' => $workerId,
    'scenario' => $scenario,
    'pid' => getmypid(),
    'pg_backend_pid' => null,
    'outcome' => 'UNKNOWN',
    'block_id' => null,
    'error_class' => null,
    'error_message' => null,
    'lock_attempted' => false,
    'lock_acquired' => false,
];

try {
    $pid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() AS pid');
    $result['pg_backend_pid'] = $pid[0]->pid ?? null;
} catch (Throwable $exception) {
}

try {
    $actor = \Modules\Foundation\User\Models\User::findOrFail($fixture['actor_id']);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
    session([
        'active_property_id' => $fixture['property_id'],
        'active_company_id' => $fixture['company_id'],
        'current_property_id' => $fixture['property_id'],
    ]);
    setPermissionsTeamId($fixture['property_id']);

    touch($barrierDir . '/ready-' . $workerId);

    $startFile = $barrierDir . '/start-' . $scenario . '.signal';
    for ($i = 0; $i < 6000; $i++) {
        if (file_exists($startFile)) {
            break;
        }
        usleep(10000);
    }

    touch($barrierDir . '/locking-' . $workerId);
    $result['lock_attempted'] = true;

    $service = app(\Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService::class);

    if ($scenario === 'create') {
        $block = $service->block(
            $actor,
            $fixture['room_id'],
            'Concurrent room technical block',
            null,
            null,
            'create-' . $workerId . '-' . \Illuminate\Support\Str::ulid()
        );
        $result['outcome'] = 'BLOCKED';
        $result['block_id'] = $block->id;
        $result['lock_acquired'] = true;
    } elseif ($scenario === 'release') {
        $block = \Modules\Operations\Engineering\Models\EngineeringRoomAvailabilityBlock::withoutGlobalScopes()
            ->whereKey($fixture['block_id'])
            ->firstOrFail();
        $reason = 'Concurrent technical clearance';
        $idempotencyContext = 'release-' . $workerId . '-' . \Illuminate\Support\Str::ulid();
        $hash = $service->releaseEvidenceHash($block, $reason, $idempotencyContext);
        app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm(
            $actor,
            \Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService::RELEASE_INTENT,
            'password',
            $fixture['company_id'],
            $fixture['property_id'],
            $hash
        );
        $released = $service->release($actor, $fixture['block_id'], $reason, $idempotencyContext);
        $result['outcome'] = 'RELEASED';
        $result['block_id'] = $released->id;
        $result['lock_acquired'] = true;
    } else {
        throw new RuntimeException('UNKNOWN_SCENARIO');
    }

    touch($barrierDir . '/posted-' . $workerId);
} catch (DomainException|RuntimeException $exception) {
    try {
        \Illuminate\Support\Facades\DB::rollBack();
    } catch (Throwable $rollback) {
    }
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['error_class'] = get_class($exception);
    $result['error_message'] = substr($exception->getMessage(), 0, 300);
    touch($barrierDir . '/posted-' . $workerId);
} catch (Throwable $exception) {
    try {
        \Illuminate\Support\Facades\DB::rollBack();
    } catch (Throwable $rollback) {
    }
    $result['outcome'] = 'ERROR';
    $result['error_class'] = get_class($exception);
    $result['error_message'] = substr($exception->getMessage(), 0, 500);
    touch($barrierDir . '/posted-' . $workerId);
}

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
exit(0);
