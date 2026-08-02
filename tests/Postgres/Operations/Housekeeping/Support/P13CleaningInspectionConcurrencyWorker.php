<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Shared\Services\CurrentPropertyService;

$configPath = $argv[1] ?? '';
$config = $configPath !== '' && is_file($configPath)
    ? json_decode((string) file_get_contents($configPath), true)
    : null;
if (! is_array($config)) {
    exit(2);
}

$basePath = (string) $config['base_path'];
require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.connections.pgsql.database' => $config['db_name'],
    'database.connections.pgsql.host' => $config['db_host'],
    'database.connections.pgsql.port' => $config['db_port'],
    'database.connections.pgsql.username' => $config['db_user'],
    'database.connections.pgsql.password' => $config['db_pass'],
    'session.driver' => 'array',
    'cache.default' => 'array',
]);
DB::purge('pgsql');
DB::reconnect('pgsql');

$workerId = (string) $config['worker_id'];
$barrier = (string) $config['barrier_dir'];
$fixture = $config['fixture'];
$result = [
    'worker_id' => $workerId,
    'pid' => getmypid(),
    'pg_backend_pid' => null,
    'outcome' => 'INTERNAL_FAILURE',
    'task_id' => $fixture['task_id'] ?? null,
    'inspection_id' => $fixture['inspection_id'] ?? null,
    'transition_id' => null,
    'rework_task_id' => null,
    'duration_ms' => null,
];

try {
    $result['pg_backend_pid'] = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $actor = User::withoutGlobalScopes()->findOrFail($fixture['actor_id']);
    Auth::login($actor);
    app(CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
    session([
        'active_company_id' => $fixture['company_id'],
        'active_property_id' => $fixture['property_id'],
        'current_property_id' => $fixture['property_id'],
    ]);
    setPermissionsTeamId($fixture['property_id']);

    /** @var HousekeepingCleaningInspectionReadinessLifecycleService $service */
    $service = app(HousekeepingCleaningInspectionReadinessLifecycleService::class);
    if (($config['action'] ?? '') === 'pass') {
        $service->confirmInspectionPass($actor, $fixture['inspection_id'], $fixture['reason'], 'password');
    }

    touch($barrier . '/ready-' . $workerId);
    $start = $barrier . '/start.signal';
    for ($i = 0; $i < 12000 && ! is_file($start); $i++) {
        usleep(10000);
    }

    if (($config['wait_for_held'] ?? false) === true) {
        $held = $barrier . '/held-A';
        for ($i = 0; $i < 12000 && ! is_file($held); $i++) {
            usleep(10000);
        }
    }

    $startedAt = hrtime(true);
    $action = (string) $config['action'];
    if (($config['hold_own_room'] ?? false) === true) {
        DB::transaction(function () use ($fixture, $barrier, $workerId, $service, $actor): void {
            DB::table('rooms')->where('id', $fixture['room_id'])->lockForUpdate()->first();
            touch($barrier . '/held-' . $workerId);
            $release = $barrier . '/release-held.signal';
            for ($i = 0; $i < 12000 && ! is_file($release); $i++) {
                usleep(10000);
            }
            usleep(1500000);
            $service->changeCleaningTaskStatus($actor, $fixture['task_id'], TaskStatusEnum::InProgress);
        });
        $result['outcome'] = 'STARTED';
    } elseif ($action === 'start') {
        $service->changeCleaningTaskStatus($actor, $fixture['task_id'], TaskStatusEnum::InProgress);
        $result['outcome'] = 'STARTED';
    } elseif ($action === 'complete') {
        $service->changeCleaningTaskStatus($actor, $fixture['task_id'], TaskStatusEnum::Completed, $fixture['reason']);
        $result['outcome'] = 'COMPLETED';
        $result['inspection_id'] = RoomInspection::withoutGlobalScopes()->where('cleaning_task_id', $fixture['task_id'])->value('id');
    } elseif ($action === 'pass') {
        $service->passInspection($actor, $fixture['inspection_id'], $fixture['reason']);
        $result['outcome'] = 'PASSED';
    } elseif ($action === 'fail') {
        $service->failInspection($actor, $fixture['inspection_id'], $fixture['reason']);
        $result['outcome'] = 'FAILED';
        $result['rework_task_id'] = CleaningTask::withoutGlobalScopes()
            ->where('rework_source_inspection_id', $fixture['inspection_id'])
            ->value('id');
    }

    $key = match ($action) {
        'start' => 'hk-task-start:' . $fixture['task_id'],
        'complete' => 'hk-task-complete:' . $fixture['task_id'],
        'pass' => 'hk-inspection-pass:' . $fixture['inspection_id'],
        'fail' => 'hk-inspection-fail:' . $fixture['inspection_id'],
        default => 'hk-task-start:' . $fixture['task_id'],
    };
    $result['transition_id'] = HousekeepingRoomReadinessTransition::withoutGlobalScopes()
        ->where('property_id', $fixture['property_id'])
        ->where('idempotency_key', $key)
        ->value('id');
    $result['duration_ms'] = (int) ((hrtime(true) - $startedAt) / 1_000_000);
} catch (DomainException|Symfony\Component\HttpKernel\Exception\HttpException|Illuminate\Database\QueryException) {
    $result['outcome'] = 'CONTROLLED_REJECTION';
} catch (Throwable) {
    $result['outcome'] = 'INTERNAL_FAILURE';
} finally {
    try {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    } catch (Throwable) {
    }
    file_put_contents((string) $config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    DB::disconnect('pgsql');
}

exit(0);
