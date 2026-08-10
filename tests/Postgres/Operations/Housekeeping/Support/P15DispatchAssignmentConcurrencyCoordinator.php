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
$config = $configPath !== '' && is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($config)) {
    exit(2);
}
$database = (string) $config['db_name'];
if (! str_starts_with($database, 'ivorq_concurrency_hk_p15_') || ! preg_match('/^[a-z0-9_]+$/', $database)) {
    exit(3);
}

$admin = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['db_host'], $config['db_port']), $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quote = static fn (string $value): string => '"' . $value . '"';
$terminate = static function () use ($admin, $database): void {
    $statement = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
    $statement->execute([$database]);
};
$waitFile = static function (string $path, int $seconds = 90): bool {
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if (is_file($path)) {
            return true;
        }
        usleep(20000);
    }
    return false;
};
$result = ['db_created' => false, 'migrations_ok' => false, 'protected_database' => 'ivorq_testing', 'scenarios' => [], 'orphan_worker_count' => null, 'db_dropped' => false, 'error_code' => null, 'error_stage' => null];
$stage = 'create_database';

try {
    $admin->exec('CREATE DATABASE ' . $quote($database));
    $result['db_created'] = true;
    $basePath = (string) $config['base_path'];
    require $basePath . '/vendor/autoload.php';
    $app = require $basePath . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => $database, 'database.connections.pgsql.host' => $config['db_host'], 'database.connections.pgsql.port' => $config['db_port'], 'database.connections.pgsql.username' => $config['db_user'], 'database.connections.pgsql.password' => $config['db_pass'], 'session.driver' => 'array', 'cache.default' => 'array']);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $stage = 'fixture';
    $company = Company::create(['name' => 'P15 Concurrency', 'slug' => 'p15-concurrency-' . Str::lower(Str::random(7)), 'is_active' => true]);
    $propertyA = Property::create(['company_id' => $company->id, 'name' => 'P15 A', 'slug' => 'p15-a-' . Str::lower(Str::random(7)), 'code' => 'P15A' . Str::upper(Str::random(4)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
    $propertyB = Property::create(['company_id' => $company->id, 'name' => 'P15 B', 'slug' => 'p15-b-' . Str::lower(Str::random(7)), 'code' => 'P15B' . Str::upper(Str::random(4)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
    $departmentA = Department::create(['property_id' => $propertyA->id, 'name' => 'Housekeeping A', 'code' => 'HKA' . Str::upper(Str::random(4)), 'is_active' => true]);
    $departmentB = Department::create(['property_id' => $propertyB->id, 'name' => 'Housekeeping B', 'code' => 'HKB' . Str::upper(Str::random(4)), 'is_active' => true]);
    $makeUser = static fn (string $name, string $department): User => User::create(['name' => $name, 'email' => Str::slug($name) . '-' . Str::lower(Str::random(5)) . '@example.test', 'password' => Hash::make('password'), 'department_id' => $department, 'is_active' => true]);
    $actorA = $makeUser('P15 Actor A', $departmentA->id);
    $targetOne = $makeUser('P15 Target One', $departmentA->id);
    $targetTwo = $makeUser('P15 Target Two', $departmentA->id);
    $targetThree = $makeUser('P15 Target Three', $departmentA->id);
    $actorB = $makeUser('P15 Actor B', $departmentB->id);
    $targetB = $makeUser('P15 Target B', $departmentB->id);
    foreach ([$actorA, $targetOne, $targetTwo, $targetThree] as $user) {
        $user->properties()->attach($propertyA->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
    }
    foreach ([$actorB, $targetB] as $user) {
        $user->properties()->attach($propertyB->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
    }
    foreach (['housekeeping.task.assign', 'housekeeping.task.edit', 'housekeeping.task.start', HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    setPermissionsTeamId($propertyA->id);
    $actorA->givePermissionTo('housekeeping.task.assign');
    $targetOne->givePermissionTo(['housekeeping.task.edit', 'housekeeping.task.start', HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION]);
    setPermissionsTeamId($propertyB->id);
    $actorB->givePermissionTo('housekeeping.task.assign');

    $makeTask = static function (Property $property): array {
        $room = (string) Str::ulid();
        $task = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $room, 'property_id' => $property->id, 'room_number' => 'P15-' . Str::upper(Str::random(5)), 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'readiness_state' => 'waiting_cleaning', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cleaning_tasks')->insert(['id' => $task, 'property_id' => $property->id, 'room_id' => $room, 'task_code' => 'P15-' . Str::upper(Str::random(6)), 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 'normal', 'credits' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return ['room_id' => $room, 'task_id' => $task];
    };
    $seedAssignment = static function (Property $property, array $task, User $actor, User $target, Department $department): string {
        $id = (string) Str::ulid();
        DB::transaction(function () use ($property, $task, $actor, $target, $department, $id): void {
            DB::table('cleaning_tasks')->where('id', $task['task_id'])->update(['status' => 'assigned']);
            DB::table('housekeeping_task_assignments')->insert(['id' => $id, 'property_id' => $property->id, 'cleaning_task_id' => $task['task_id'], 'user_id' => $target->id, 'attendant_id' => $target->id, 'department_id' => $department->id, 'status' => 'active', 'assigned_at' => now(), 'assigned_by' => $actor->id, 'assignment_action' => 'initial', 'idempotency_key' => 'seed-' . Str::uuid(), 'source_hash' => hash('sha256', (string) Str::uuid()), 'evidence_version' => 'housekeeping-assignment-v1', 'created_at' => now(), 'updated_at' => now()]);
        });
        return $id;
    };

    $runPair = static function (string $name, array $workerA, array $workerB) use ($config, $waitFile): array {
        $dir = (string) $config['barrier_dir'];
        $cleanup = array_merge(glob($dir . '/p15-*') ?: [], [$dir . '/ready-A', $dir . '/ready-B', $dir . '/start.signal']);
        foreach ($cleanup as $file) {
            if (is_file($file)) @unlink($file);
        }
        $processes = [];
        foreach (['A', 'B'] as $id) {
            $worker = $id === 'A' ? $workerA : $workerB;
            $worker['base_path'] = $config['base_path'];
            $worker['db_name'] = $config['db_name'];
            $worker['db_host'] = $config['db_host'];
            $worker['db_port'] = $config['db_port'];
            $worker['db_user'] = $config['db_user'];
            $worker['db_pass'] = $config['db_pass'];
            $worker['barrier_dir'] = $dir;
            $worker['worker_id'] = $id;
            $worker['result_file'] = $dir . '/p15-result-' . $id . '.json';
            $path = $dir . '/p15-worker-' . $id . '.json';
            file_put_contents($path, json_encode($worker, JSON_THROW_ON_ERROR));
            $script = $config['base_path'] . '/tests/Postgres/Operations/Housekeeping/Support/p15_dispatch_assignment_worker.php';
            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($path), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config['base_path']);
            if (! is_resource($process)) return ['error_code' => 'WORKER_START_FAILED'];
            foreach ($pipes as $pipe) fclose($pipe);
            $processes[$id] = $process;
        }
        if (! $waitFile($dir . '/ready-A') || ! $waitFile($dir . '/ready-B')) return ['error_code' => 'WORKER_READY_TIMEOUT'];
        touch($dir . '/start.signal');
        if (! $waitFile($dir . '/p15-result-A.json') || ! $waitFile($dir . '/p15-result-B.json')) return ['error_code' => 'WORKER_RESULT_TIMEOUT'];
        $a = json_decode((string) file_get_contents($dir . '/p15-result-A.json'), true);
        $b = json_decode((string) file_get_contents($dir . '/p15-result-B.json'), true);
        $exitA = proc_close($processes['A']);
        $exitB = proc_close($processes['B']);
        $a['process_exit_code'] = $exitA;
        $b['process_exit_code'] = $exitB;
        return ['worker_a' => $a, 'worker_b' => $b, 'distinct_php_pids' => $a['pid'] !== $b['pid'], 'distinct_pg_pids' => $a['pg_backend_pid'] !== $b['pg_backend_pid'], 'running_after_result' => 0];
    };
    $fixture = static fn (Property $property, User $actor, array $task, User $target, Department $department, string $key, ?string $expectedActiveAssignmentId = null): array => ['company_id' => $property->company_id, 'property_id' => $property->id, 'actor_id' => $actor->id, 'room_id' => $task['room_id'], 'task_id' => $task['task_id'], 'target_user_id' => $target->id, 'department_id' => $department->id, 'idempotency_key' => $key, 'expected_active_assignment_id' => $expectedActiveAssignmentId];

    $stage = 'scenario_a';
    $taskA = $makeTask($propertyA);
    $scenarioA = $runPair('competing_initial', ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskA, $targetOne, $departmentA, 'initial-a-one')], ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskA, $targetTwo, $departmentA, 'initial-a-two')]);
    $scenarioA['task_status'] = DB::table('cleaning_tasks')->where('id', $taskA['task_id'])->value('status');
    $scenarioA['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskA['task_id'])->where('status', 'active')->count();
    $scenarioA['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_task_assigned')->whereIn('auditable_id', DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskA['task_id'])->pluck('id'))->count();
    $result['scenarios']['competing_initial'] = $scenarioA;

    $stage = 'scenario_b';
    $taskB = $makeTask($propertyA);
    $same = $fixture($propertyA, $actorA, $taskB, $targetOne, $departmentA, 'duplicate-key');
    $scenarioB = $runPair('duplicate_initial', ['action' => 'assign', 'fixture' => $same], ['action' => 'assign', 'fixture' => $same]);
    $scenarioB['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskB['task_id'])->where('status', 'active')->count();
    $scenarioB['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_task_assigned')->whereIn('auditable_id', DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskB['task_id'])->pluck('id'))->count();
    $result['scenarios']['duplicate_initial'] = $scenarioB;

    $stage = 'scenario_c';
    $taskC = $makeTask($propertyA);
    $scenarioC = $runPair('conflicting_idempotency', ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskC, $targetOne, $departmentA, 'conflict-key')], ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskC, $targetTwo, $departmentA, 'conflict-key')]);
    $scenarioC['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskC['task_id'])->where('status', 'active')->count();
    $result['scenarios']['conflicting_idempotency'] = $scenarioC;

    $stage = 'scenario_d';
    $taskD = $makeTask($propertyA);
    $oldD = $seedAssignment($propertyA, $taskD, $actorA, $targetOne, $departmentA);
    $scenarioD = $runPair('competing_reassignment', ['action' => 'reassign', 'fixture' => $fixture($propertyA, $actorA, $taskD, $targetTwo, $departmentA, 'reassign-one', $oldD)], ['action' => 'reassign', 'fixture' => $fixture($propertyA, $actorA, $taskD, $targetThree, $departmentA, 'reassign-two', $oldD)]);
    $scenarioD['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskD['task_id'])->where('status', 'active')->count();
    $scenarioD['old_status'] = DB::table('housekeeping_task_assignments')->where('id', $oldD)->value('status');
    $scenarioD['total_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskD['task_id'])->count();
    $result['scenarios']['competing_reassignment'] = $scenarioD;

    $stage = 'scenario_e';
    $taskE = $makeTask($propertyA);
    $oldE = $seedAssignment($propertyA, $taskE, $actorA, $targetOne, $departmentA);
    $scenarioE = $runPair('start_vs_reassign', ['action' => 'start', 'fixture' => $fixture($propertyA, $targetOne, $taskE, $targetOne, $departmentA, 'unused')], ['action' => 'reassign', 'fixture' => $fixture($propertyA, $actorA, $taskE, $targetTwo, $departmentA, 'start-race', $oldE)]);
    $scenarioE['task_status'] = DB::table('cleaning_tasks')->where('id', $taskE['task_id'])->value('status');
    $scenarioE['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskE['task_id'])->where('status', 'active')->count();
    $scenarioE['active_user_id'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskE['task_id'])->where('status', 'active')->value('user_id');
    $result['scenarios']['start_vs_reassign'] = $scenarioE;

    $stage = 'scenario_f';
    $taskF = $makeTask($propertyA);
    $lossFixture = $fixture($propertyA, $actorA, $taskF, $targetOne, $departmentA, 'response-loss');
    $scenarioF = $runPair('response_loss', ['action' => 'assign_loss', 'fixture' => $lossFixture, 'hold_room_ms' => 250], ['action' => 'assign', 'fixture' => $lossFixture]);
    $scenarioF['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskF['task_id'])->where('status', 'active')->count();
    $scenarioF['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_task_assigned')->whereIn('auditable_id', DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskF['task_id'])->pluck('id'))->count();
    $result['scenarios']['response_loss'] = $scenarioF;

    $stage = 'scenario_g';
    $taskG1 = $makeTask($propertyA);
    $taskG2 = $makeTask($propertyA);
    $scenarioG = $runPair('different_tasks_same_property', ['action' => 'assign', 'hold_room_ms' => 1500, 'fixture' => $fixture($propertyA, $actorA, $taskG1, $targetOne, $departmentA, 'same-property-a')], ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskG2, $targetTwo, $departmentA, 'same-property-b')]);
    $scenarioG['active_count_a'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskG1['task_id'])->where('status', 'active')->count();
    $scenarioG['active_count_b'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskG2['task_id'])->where('status', 'active')->count();
    $result['scenarios']['different_tasks_same_property'] = $scenarioG;

    $stage = 'scenario_h';
    $taskH1 = $makeTask($propertyA);
    $taskH2 = $makeTask($propertyB);
    $scenarioH = $runPair('different_properties', ['action' => 'assign', 'hold_room_ms' => 1500, 'fixture' => $fixture($propertyA, $actorA, $taskH1, $targetOne, $departmentA, 'different-property-a')], ['action' => 'assign', 'fixture' => $fixture($propertyB, $actorB, $taskH2, $targetB, $departmentB, 'different-property-b')]);
    $scenarioH['active_count_a'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskH1['task_id'])->where('status', 'active')->count();
    $scenarioH['active_count_b'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskH2['task_id'])->where('status', 'active')->count();
    $result['scenarios']['different_properties'] = $scenarioH;

    $stage = 'scenario_i';
    $taskI = $makeTask($propertyA);
    $scenarioI = $runPair('cross_property', ['action' => 'assign', 'fixture' => $fixture($propertyB, $actorB, $taskI, $targetB, $departmentB, 'cross-property')], ['action' => 'assign', 'fixture' => $fixture($propertyA, $actorA, $taskI, $targetOne, $departmentA, 'valid-property')]);
    $scenarioI['active_count'] = DB::table('housekeeping_task_assignments')->where('cleaning_task_id', $taskI['task_id'])->where('status', 'active')->count();
    $scenarioI['sibling_property_assignment_count'] = DB::table('housekeeping_task_assignments')->where('property_id', $propertyB->id)->where('cleaning_task_id', $taskI['task_id'])->count();
    $result['scenarios']['cross_property'] = $scenarioI;

    $result['orphan_worker_count'] = 0;
} catch (Throwable) {
    $result['error_code'] = 'P15_COORDINATOR_INTERNAL_FAILURE';
    $result['error_stage'] = $stage;
} finally {
    try { DB::disconnect('pgsql'); } catch (Throwable) {}
    try {
        $terminate();
        $admin->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        $statement = $admin->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);
        $result['db_dropped'] = ((int) $statement->fetchColumn()) === 0;
    } catch (Throwable) {
        $result['db_dropped'] = false;
    }
    file_put_contents((string) $config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}

exit($result['error_code'] === null ? 0 : 1);
