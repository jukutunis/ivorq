<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;

$configPath = $argv[1] ?? '';
$config = $configPath !== '' && is_file($configPath)
    ? json_decode((string) file_get_contents($configPath), true)
    : null;
if (! is_array($config)) {
    exit(2);
}

$database = (string) $config['db_name'];
$prefix = 'ivorq_concurrency_hk_p13_';
if (! str_starts_with($database, $prefix) || ! preg_match('/^[a-z0-9_]+$/', $database)) {
    exit(3);
}

$admin = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['db_host'], $config['db_port']),
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$quote = static fn (string $value): string => '"' . $value . '"';
$terminate = static function () use ($admin, $database): void {
    $statement = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
    $statement->execute([$database]);
};
$waitFile = static function (string $path, int $seconds = 90): bool {
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        if (is_file($path)) {
            return true;
        }
        usleep(20000);
    }

    return false;
};

$result = [
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'migrations_ok' => false,
    'scenarios' => [],
    'mixed_state_count' => null,
    'orphan_worker_count' => null,
    'db_dropped' => false,
    'error_code' => null,
    'error_stage' => null,
];
$stage = 'create_database';

try {
    $admin->exec('CREATE DATABASE ' . $quote($database));
    $result['db_created'] = true;

    $basePath = (string) $config['base_path'];
    $stage = 'bootstrap';
    require $basePath . '/vendor/autoload.php';
    $app = require $basePath . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.host' => $config['db_host'],
        'database.connections.pgsql.port' => $config['db_port'],
        'database.connections.pgsql.username' => $config['db_user'],
        'database.connections.pgsql.password' => $config['db_pass'],
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $stage = 'fixture_identity';
    $stage = 'fixture_company';
    $company = Company::create(['name' => 'P13 Concurrency', 'slug' => 'p13-' . Str::lower(Str::random(8)), 'is_active' => true]);
    $stage = 'fixture_properties';
    $propertyA = Property::create(['company_id' => $company->id, 'name' => 'P13 A', 'slug' => 'p13-a-' . Str::lower(Str::random(8)), 'code' => 'P13A' . Str::upper(Str::random(4)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
    $propertyB = Property::create(['company_id' => $company->id, 'name' => 'P13 B', 'slug' => 'p13-b-' . Str::lower(Str::random(8)), 'code' => 'P13B' . Str::upper(Str::random(4)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
    $stage = 'fixture_users';
    $attendant = User::create(['name' => 'P13 Attendant', 'email' => 'p13-attendant-' . Str::lower(Str::random(6)) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
    $attendantB = User::create(['name' => 'P13 Attendant B', 'email' => 'p13-attendant-b-' . Str::lower(Str::random(6)) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
    $inspector = User::create(['name' => 'P13 Inspector', 'email' => 'p13-inspector-' . Str::lower(Str::random(6)) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
    $departmentA = Department::create(['property_id' => $propertyA->id, 'name' => 'P13 Housekeeping A', 'code' => 'P13A' . Str::upper(Str::random(4)), 'is_active' => true]);
    $departmentB = Department::create(['property_id' => $propertyB->id, 'name' => 'P13 Housekeeping B', 'code' => 'P13B' . Str::upper(Str::random(4)), 'is_active' => true]);
    $attendant->update(['department_id' => $departmentA->id]);
    $inspector->update(['department_id' => $departmentA->id]);
    $attendantB->update(['department_id' => $departmentB->id]);
    $stage = 'fixture_memberships';
    foreach ([$attendant, $inspector] as $user) {
        $user->properties()->attach($propertyA->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
    }
    $attendantB->properties()->attach($propertyB->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);

    $stage = 'fixture_permission_records';
    foreach ([
        'housekeeping.task.edit',
        'housekeeping.task.start',
        'housekeeping.task.complete',
        'housekeeping.inspection.conduct',
        HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
        HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $stage = 'fixture_permission_grants';
    setPermissionsTeamId($propertyA->id);
    $attendant->givePermissionTo([
        'housekeeping.task.edit',
        'housekeeping.task.start',
        'housekeeping.task.complete',
        HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
    ]);
    $inspector->givePermissionTo([
        'housekeeping.inspection.conduct',
        HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
    ]);
    setPermissionsTeamId($propertyB->id);
    $attendantB->givePermissionTo([
        'housekeeping.task.edit',
        'housekeeping.task.start',
        'housekeeping.task.complete',
        HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
    ]);

    $stage = 'scenario_setup';

    $makeTask = static function (Property $property, User $actor, string $state): array {
        $roomId = (string) Str::ulid();
        $taskId = (string) Str::ulid();
        $roomReadiness = $state === 'assigned' ? 'waiting_cleaning' : 'cleaning';
        DB::table('rooms')->insert(['id' => $roomId, 'property_id' => $property->id, 'room_number' => 'C' . Str::upper(Str::random(5)), 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'readiness_state' => $roomReadiness, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(function () use ($property, $actor, $state, $roomId, $taskId): void {
            DB::table('cleaning_tasks')->insert(['id' => $taskId, 'property_id' => $property->id, 'room_id' => $roomId, 'task_code' => 'C-' . Str::upper(Str::random(6)), 'task_type' => 'checkout_cleaning', 'status' => $state, 'priority' => 'normal', 'credits' => 1, 'started_at' => $state === 'in_progress' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('housekeeping_task_assignments')->insert(['id' => (string) Str::ulid(), 'property_id' => $property->id, 'cleaning_task_id' => $taskId, 'user_id' => $actor->id, 'attendant_id' => $actor->id, 'department_id' => $actor->department_id, 'status' => 'active', 'assigned_at' => now(), 'assigned_by' => $actor->id, 'assignment_action' => 'initial', 'idempotency_key' => 'p13-concurrency-' . Str::uuid(), 'source_hash' => hash('sha256', 'p13-concurrency-' . $taskId), 'evidence_version' => 'housekeeping-assignment-v1', 'created_at' => now(), 'updated_at' => now()]);
        });

        return ['company_id' => $property->company_id, 'property_id' => $property->id, 'actor_id' => $actor->id, 'room_id' => $roomId, 'task_id' => $taskId];
    };
    $makeInspection = static function (Property $property, User $cleaner, User $inspector): array {
        $roomId = (string) Str::ulid();
        $taskId = (string) Str::ulid();
        $inspectionId = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $roomId, 'property_id' => $property->id, 'room_number' => 'I' . Str::upper(Str::random(5)), 'room_type' => 'standard', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cleaning_tasks')->insert(['id' => $taskId, 'property_id' => $property->id, 'room_id' => $roomId, 'task_code' => 'I-' . Str::upper(Str::random(6)), 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'credits' => 1, 'started_at' => now()->subHour(), 'completed_at' => now(), 'completed_by' => $cleaner->id, 'notes' => 'Concurrent source', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('room_inspections')->insert(['id' => $inspectionId, 'property_id' => $property->id, 'room_id' => $roomId, 'cleaning_task_id' => $taskId, 'supervisor_id' => $inspector->id, 'inspection_type' => 'post_cleaning', 'status' => 'in_progress', 'is_passed' => false, 'created_at' => now(), 'updated_at' => now()]);

        return ['company_id' => $property->company_id, 'property_id' => $property->id, 'actor_id' => $inspector->id, 'room_id' => $roomId, 'task_id' => $taskId, 'inspection_id' => $inspectionId];
    };

    $runPair = static function (string $name, string $actionA, string $actionB, array $fixtureA, array $fixtureB, bool $holdA = false) use ($config, $waitFile): array {
        $dir = (string) $config['barrier_dir'];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== 'coordinator-config.json') {
                @unlink($file);
            }
        }

        $processes = [];
        foreach (['A', 'B'] as $workerId) {
            $fixture = $workerId === 'A' ? $fixtureA : $fixtureB;
            $workerConfig = [
                'base_path' => $config['base_path'],
                'db_name' => $config['db_name'],
                'db_host' => $config['db_host'],
                'db_port' => $config['db_port'],
                'db_user' => $config['db_user'],
                'db_pass' => $config['db_pass'],
                'barrier_dir' => $dir,
                'worker_id' => $workerId,
                'action' => $workerId === 'A' ? $actionA : $actionB,
                'fixture' => $fixture,
                'hold_own_room' => $holdA && $workerId === 'A',
                'wait_for_held' => $holdA && $workerId === 'B',
                'result_file' => $dir . '/result-' . $workerId . '.json',
            ];
            $workerConfig['fixture']['reason'] = match ($workerConfig['action']) {
                'complete' => 'Concurrent completion note.',
                'pass' => 'Concurrent controlled Room release.',
                'fail' => 'Concurrent inspection failure rework.',
                default => '',
            };
            $workerConfigPath = $dir . '/worker-' . $workerId . '.json';
            file_put_contents($workerConfigPath, json_encode($workerConfig, JSON_THROW_ON_ERROR));
            $script = $config['base_path'] . '/tests/Postgres/Operations/Housekeeping/Support/P13CleaningInspectionConcurrencyWorker.php';
            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($workerConfigPath), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config['base_path']);
            if (! is_resource($process)) {
                return ['error_code' => 'WORKER_START_FAILED'];
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $processes[$workerId] = $process;
        }

        if (! $waitFile($dir . '/ready-A') || ! $waitFile($dir . '/ready-B')) {
            return ['error_code' => 'WORKER_READY_TIMEOUT'];
        }
        touch($dir . '/start.signal');
        if ($holdA) {
            if (! $waitFile($dir . '/held-A')) {
                return ['error_code' => 'WORKER_HOLD_TIMEOUT'];
            }
            touch($dir . '/release-held.signal');
        }
        if (! $waitFile($dir . '/result-A.json') || ! $waitFile($dir . '/result-B.json')) {
            return ['error_code' => 'WORKER_RESULT_TIMEOUT'];
        }

        $workerResults = [];
        $running = 0;
        foreach ($processes as $workerId => $process) {
            $status = proc_get_status($process);
            for ($i = 0; $i < 500 && $status['running']; $i++) {
                usleep(10000);
                $status = proc_get_status($process);
            }
            if ($status['running']) {
                $running++;
            }
            proc_close($process);
            $workerResults[$workerId] = json_decode((string) file_get_contents($dir . '/result-' . $workerId . '.json'), true);
        }

        return [
            'name' => $name,
            'worker_a' => $workerResults['A'],
            'worker_b' => $workerResults['B'],
            'distinct_php_pids' => $workerResults['A']['pid'] !== $workerResults['B']['pid'],
            'distinct_pg_pids' => $workerResults['A']['pg_backend_pid'] !== $workerResults['B']['pg_backend_pid'],
            'running_after_result' => $running,
        ];
    };

    $start = $makeTask($propertyA, $attendant, 'assigned');
    $stage = 'scenario_start';
    $result['scenarios']['start'] = $runPair('start', 'start', 'start', $start, $start);
    $result['scenarios']['start']['transition_count'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $start['task_id'])->count();
    $result['scenarios']['start']['task_status'] = DB::table('cleaning_tasks')->where('id', $start['task_id'])->value('status');

    $completion = $makeTask($propertyA, $attendant, 'in_progress');
    $stage = 'scenario_completion';
    $result['scenarios']['completion'] = $runPair('completion', 'complete', 'complete', $completion, $completion);
    $result['scenarios']['completion']['transition_count'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $completion['task_id'])->count();
    $result['scenarios']['completion']['inspection_count'] = DB::table('room_inspections')->where('cleaning_task_id', $completion['task_id'])->count();
    $result['scenarios']['completion']['task_status'] = DB::table('cleaning_tasks')->where('id', $completion['task_id'])->value('status');

    $pass = $makeInspection($propertyA, $attendant, $inspector);
    $stage = 'scenario_pass';
    $result['scenarios']['pass'] = $runPair('pass', 'pass', 'pass', $pass, $pass);
    $result['scenarios']['pass']['transition_count'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $pass['inspection_id'])->count();
    $result['scenarios']['pass']['inspection_status'] = DB::table('room_inspections')->where('id', $pass['inspection_id'])->value('status');
    $result['scenarios']['pass']['room_readiness'] = DB::table('rooms')->where('id', $pass['room_id'])->value('readiness_state');

    $fail = $makeInspection($propertyA, $attendant, $inspector);
    $stage = 'scenario_fail';
    $result['scenarios']['fail'] = $runPair('fail', 'fail', 'fail', $fail, $fail);
    $result['scenarios']['fail']['transition_count'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $fail['inspection_id'])->count();
    $result['scenarios']['fail']['rework_count'] = DB::table('cleaning_tasks')->where('rework_source_inspection_id', $fail['inspection_id'])->count();
    $result['scenarios']['fail']['inspection_status'] = DB::table('room_inspections')->where('id', $fail['inspection_id'])->value('status');
    $result['scenarios']['fail']['room_readiness'] = DB::table('rooms')->where('id', $fail['room_id'])->value('readiness_state');

    $race = $makeInspection($propertyA, $attendant, $inspector);
    $stage = 'scenario_pass_fail';
    $result['scenarios']['pass_fail'] = $runPair('pass_fail', 'pass', 'fail', $race, $race);
    $result['scenarios']['pass_fail']['transition_count'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $race['inspection_id'])->count();
    $result['scenarios']['pass_fail']['rework_count'] = DB::table('cleaning_tasks')->where('rework_source_inspection_id', $race['inspection_id'])->count();
    $result['scenarios']['pass_fail']['inspection_status'] = DB::table('room_inspections')->where('id', $race['inspection_id'])->value('status');
    $result['scenarios']['pass_fail']['room_readiness'] = DB::table('rooms')->where('id', $race['room_id'])->value('readiness_state');

    $differentA = $makeTask($propertyA, $attendant, 'assigned');
    $differentB = $makeTask($propertyB, $attendantB, 'assigned');
    $stage = 'scenario_different_property';
    $result['scenarios']['different_property'] = $runPair('different_property', 'start', 'start', $differentA, $differentB, true);
    $result['scenarios']['different_property']['transition_count_a'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $differentA['task_id'])->count();
    $result['scenarios']['different_property']['transition_count_b'] = DB::table('housekeeping_room_readiness_transitions')->where('source_id', $differentB['task_id'])->count();

    $result['mixed_state_count'] = DB::table('room_inspections as ri')
        ->join('rooms as r', 'r.id', '=', 'ri.room_id')
        ->where(function ($query): void {
            $query->where(fn ($q) => $q->where('ri.status', 'passed')->where('r.readiness_state', 'waiting_inspection'))
                ->orWhere(fn ($q) => $q->where('ri.status', 'failed')->whereIn('r.readiness_state', ['ready_for_sale', 'ready_for_vip']));
        })->count();
    $result['orphan_worker_count'] = collect($result['scenarios'])->sum(fn (array $scenario): int => (int) ($scenario['running_after_result'] ?? 0));
} catch (Throwable) {
    $result['error_code'] = 'P13_COORDINATOR_INTERNAL_FAILURE';
    $result['error_stage'] = $stage;
} finally {
    try {
        DB::disconnect('pgsql');
    } catch (Throwable) {
    }
    try {
        $terminate();
        $admin->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        $statement = $admin->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);
        $result['db_dropped'] = (int) $statement->fetchColumn() === 0;
    } catch (Throwable) {
        $result['db_dropped'] = false;
    }
    file_put_contents((string) $config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}

exit(0);
