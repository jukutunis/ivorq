<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Modules\Operations\Housekeeping\Services\HousekeepingTaskDispatchAssignmentService;
use Shared\Services\CurrentPropertyService;

$configPath = $argv[1] ?? '';
$config = $configPath !== '' && is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
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
    'transaction_state' => null,
    'lock_count' => 0,
    'outcome' => 'INTERNAL_FAILURE',
    'rejection_marker' => null,
    'assignment_id' => null,
    'duration_ms' => null,
    'exit_code' => 0,
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

    touch($barrier . '/ready-' . $workerId);
    for ($i = 0; $i < 12000 && ! is_file($barrier . '/start.signal'); $i++) {
        usleep(10000);
    }

    $startedAt = hrtime(true);
    DB::beginTransaction();
    $action = (string) $config['action'];
    if (($config['hold_room_ms'] ?? 0) > 0) {
        DB::table('rooms')->where('id', $fixture['room_id'])->lockForUpdate()->first();
        usleep(((int) $config['hold_room_ms']) * 1000);
    }

    if (in_array($action, ['assign', 'assign_loss', 'reassign'], true)) {
        $assignment = app(HousekeepingTaskDispatchAssignmentService::class)->assignOrReassign(
            $actor,
            $fixture['task_id'],
            $fixture['target_user_id'],
            $fixture['department_id'],
            $fixture['idempotency_key'],
            $fixture['expected_active_assignment_id'] ?? null,
        );
        $result['assignment_id'] = $assignment->assignment->id;
        $result['outcome'] = $assignment->replayed
            ? 'REPLAYED'
            : ($assignment->assignment->assignment_action === 'initial' ? 'ASSIGNED' : 'REASSIGNED');
    } elseif ($action === 'start') {
        app(HousekeepingCleaningInspectionReadinessLifecycleService::class)
            ->changeCleaningTaskStatus($actor, $fixture['task_id'], TaskStatusEnum::InProgress);
        $result['outcome'] = 'STARTED';
    }

    $activity = DB::selectOne('SELECT state FROM pg_stat_activity WHERE pid = pg_backend_pid()');
    $result['transaction_state'] = $activity?->state;
    $result['lock_count'] = (int) DB::selectOne('SELECT COUNT(*) AS aggregate FROM pg_locks WHERE pid = pg_backend_pid()')->aggregate;
    DB::commit();
    if ($action === 'assign_loss') {
        $result['outcome'] = 'COMMITTED_NO_RECEIPT';
        $result['assignment_id'] = null;
    }
    $result['duration_ms'] = (int) ((hrtime(true) - $startedAt) / 1_000_000);
} catch (DomainException|Symfony\Component\HttpKernel\Exception\HttpException|Illuminate\Database\QueryException|PDOException $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result['outcome'] = 'CONTROLLED_REJECTION';
    $result['rejection_marker'] = $exception instanceof DomainException
        ? $exception->getMessage()
        : ($exception instanceof Symfony\Component\HttpKernel\Exception\HttpException ? 'HTTP_' . $exception->getStatusCode() : 'DATABASE_REJECTION');
} catch (Throwable) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result['outcome'] = 'INTERNAL_FAILURE';
    $result['exit_code'] = 1;
} finally {
    file_put_contents((string) $config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    DB::disconnect('pgsql');
}

exit((int) $result['exit_code']);
