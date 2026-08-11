<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;

$configPath = $argv[1] ?? '';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($config)) {
    exit(2);
}

$boot = static function (array $settings): void {
    require_once $settings['base_path'] . '/vendor/autoload.php';
    $app = require $settings['base_path'] . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => $settings['db_host'],
        'database.connections.pgsql.port' => $settings['db_port'],
        'database.connections.pgsql.database' => $settings['db_name'],
        'database.connections.pgsql.username' => $settings['db_user'],
        'database.connections.pgsql.password' => $settings['db_pass'],
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
};

if (($config['mode'] ?? '') === 'worker') {
    $result = [
        'pid' => getmypid(),
        'pg_backend_pid' => 0,
        'outcome' => 'INTERNAL_FAILURE',
        'inspection_id' => $config['inspection_id'] ?? null,
        'duration_ms' => 0,
    ];

    try {
        $boot($config);
        session([
            'active_company_id' => $config['company_id'],
            'active_property_id' => $config['property_id'],
            'current_property_id' => $config['property_id'],
        ]);
        app(CurrentPropertyService::class)->setPropertyId($config['property_id']);
        setPermissionsTeamId($config['property_id']);
        $actor = User::withoutGlobalScopes()->findOrFail($config['actor_id']);
        $result['pg_backend_pid'] = (int) DB::scalar('SELECT pg_backend_pid()');
        touch($config['ready_file']);
        for ($i = 0; $i < 24000 && ! is_file($config['start_file']); $i++) {
            usleep(10000);
        }

        $started = hrtime(true);
        $action = $config['action'];
        if ($action === 'claim') {
            $execute = static function () use ($actor, $config, &$result): void {
                $claim = app(HousekeepingInspectionClaimService::class)->claim(
                    $actor,
                    $config['inspection_id'],
                    $config['command_key'],
                );
                $result['outcome'] = $claim->replayed ? 'REPLAYED' : 'CLAIMED';
                if (($config['hold_ms'] ?? 0) > 0) {
                    usleep(((int) $config['hold_ms']) * 1000);
                }
            };
            if (($config['hold_ms'] ?? 0) > 0) {
                DB::transaction($execute);
            } else {
                $execute();
            }
        } elseif ($action === 'pass') {
            $lifecycle = app(HousekeepingCleaningInspectionReadinessLifecycleService::class);
            $lifecycle->confirmInspectionPass($actor, $config['inspection_id'], $config['reason'], 'password');
            $lifecycle->passInspection($actor, $config['inspection_id'], $config['reason']);
            $result['outcome'] = 'PASSED';
        } elseif ($action === 'fail') {
            app(HousekeepingCleaningInspectionReadinessLifecycleService::class)
                ->failInspection($actor, $config['inspection_id'], $config['reason']);
            $result['outcome'] = 'FAILED';
        }
        $result['duration_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
    } catch (DomainException|Symfony\Component\HttpKernel\Exception\HttpException|Illuminate\Database\QueryException) {
        $result['outcome'] = 'CONTROLLED_REJECTION';
    } catch (Throwable) {
        $result['outcome'] = 'INTERNAL_FAILURE';
    } finally {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('pgsql');
        } catch (Throwable) {
        }
        file_put_contents($config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    exit(0);
}

$result = [
    'db_created' => false,
    'migrations_ok' => false,
    'protected_database' => 'ivorq_testing',
    'scenarios' => [],
    'orphan_worker_count' => 0,
    'db_dropped' => false,
    'error_code' => null,
    'error_stage' => null,
];
$database = $config['db_name'];
$admin = null;
$running = [];

try {
    $result['error_stage'] = 'create_database';
    $admin = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['db_host'], $config['db_port']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    if (! preg_match('/^ivorq_concurrency_hk_p17_[a-z0-9]+$/', $database)) {
        throw new RuntimeException('DATABASE_NAME_REJECTED');
    }
    $admin->exec('CREATE DATABASE "' . $database . '"');
    $result['db_created'] = true;

    $result['error_stage'] = 'migrate';
    $boot($config);
    Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $permissionNames = [
        HousekeepingInspectionClaimService::CLAIM_PERMISSION,
        HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
    ];
    foreach ($permissionNames as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $createPropertyActors = static function (string $label) use ($permissionNames): array {
        $company = Company::create(['name' => "P17 {$label} Company", 'slug' => 'p17-' . Str::lower(Str::random(10)), 'is_active' => true]);
        $property = Property::create(['company_id' => $company->id, 'name' => "P17 {$label} Property", 'slug' => 'p17-' . Str::lower(Str::random(10)), 'code' => 'P17' . Str::upper(Str::random(5)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        $users = [];
        foreach (['cleaner', 'inspector_a', 'inspector_b'] as $role) {
            $user = User::create(['name' => "P17 {$label} {$role}", 'email' => 'p17-' . Str::lower(Str::random(12)) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
            DB::table('property_user')->insert(['property_id' => $property->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $users[$role] = $user;
        }
        setPermissionsTeamId($property->id);
        foreach ($users as $user) {
            $user->givePermissionTo($permissionNames);
        }

        return ['company' => $company, 'property' => $property] + $users;
    };

    $actorsA = $createPropertyActors('A');
    $actorsB = $createPropertyActors('B');

    $fixture = static function (array $actors, string $label): array {
        $room = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $room, 'property_id' => $actors['property']->id, 'room_number' => 'P17-' . $label . '-' . Str::upper(Str::random(4)), 'room_type' => 'deluxe', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $task = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert(['id' => $task, 'property_id' => $actors['property']->id, 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'completed_at' => now(), 'completed_by' => $actors['cleaner']->id, 'created_at' => now(), 'updated_at' => now()]);
        $inspection = (string) Str::ulid();
        DB::table('room_inspections')->insert(['id' => $inspection, 'property_id' => $actors['property']->id, 'room_id' => $room, 'cleaning_task_id' => $task, 'inspection_type' => 'post_cleaning', 'status' => 'pending', 'is_passed' => false, 'created_at' => now(), 'updated_at' => now()]);

        return ['company_id' => $actors['company']->id, 'property_id' => $actors['property']->id, 'room_id' => $room, 'task_id' => $task, 'inspection_id' => $inspection, 'cleaner_id' => $actors['cleaner']->id];
    };

    $setContext = static function (array $actors): void {
        session(['active_company_id' => $actors['company']->id, 'active_property_id' => $actors['property']->id, 'current_property_id' => $actors['property']->id]);
        app(CurrentPropertyService::class)->setPropertyId($actors['property']->id);
        setPermissionsTeamId($actors['property']->id);
    };

    $claimFixture = static function (array $actors, array $source, string $key) use ($setContext): void {
        $setContext($actors);
        app(HousekeepingInspectionClaimService::class)->claim($actors['inspector_a'], $source['inspection_id'], $key);
    };

    $runPair = static function (string $name, array $workerA, array $workerB) use ($config, &$running): array {
        $barrier = $config['barrier_dir'];
        $start = $barrier . DIRECTORY_SEPARATOR . "{$name}-start.signal";
        $workers = [];
        foreach (['a' => $workerA, 'b' => $workerB] as $id => $worker) {
            $ready = $barrier . DIRECTORY_SEPARATOR . "{$name}-{$id}-ready.signal";
            $workerResult = $barrier . DIRECTORY_SEPARATOR . "{$name}-{$id}-result.json";
            $workerConfig = $barrier . DIRECTORY_SEPARATOR . "{$name}-{$id}-config.json";
            file_put_contents($workerConfig, json_encode($worker + [
                'mode' => 'worker',
                'base_path' => $config['base_path'],
                'db_name' => $config['db_name'],
                'db_host' => $config['db_host'],
                'db_port' => $config['db_port'],
                'db_user' => $config['db_user'],
                'db_pass' => $config['db_pass'],
                'ready_file' => $ready,
                'start_file' => $start,
                'result_file' => $workerResult,
            ], JSON_THROW_ON_ERROR));
            $script = __FILE__;
            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($workerConfig), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config['base_path']);
            if (! is_resource($process)) {
                throw new RuntimeException('WORKER_START_FAILED');
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $workers[$id] = ['process' => $process, 'ready' => $ready, 'result' => $workerResult];
            $running[] = $process;
        }
        $until = time() + 180;
        while (time() < $until && (! is_file($workers['a']['ready']) || ! is_file($workers['b']['ready']))) {
            usleep(10000);
        }
        touch($start);
        while (time() < $until && (! is_file($workers['a']['result']) || ! is_file($workers['b']['result']))) {
            usleep(10000);
        }
        $scenario = [];
        foreach (['a', 'b'] as $id) {
            $workerResult = is_file($workers[$id]['result'])
                ? json_decode((string) file_get_contents($workers[$id]['result']), true)
                : ['outcome' => 'INTERNAL_FAILURE'];
            $workerResult['process_exit_code'] = proc_close($workers[$id]['process']);
            $scenario['worker_' . $id] = $workerResult;
        }
        $scenario['distinct_php_pids'] = ($scenario['worker_a']['pid'] ?? 0) !== ($scenario['worker_b']['pid'] ?? 0);
        $scenario['distinct_pg_pids'] = ($scenario['worker_a']['pg_backend_pid'] ?? 0) !== ($scenario['worker_b']['pg_backend_pid'] ?? 0);
        $scenario['running_after_result'] = 0;

        return $scenario;
    };

    $workerConfig = static fn (array $actors, array $source, User $actor, string $action, string $key, array $extra = []): array => $extra + [
        'company_id' => $actors['company']->id,
        'property_id' => $actors['property']->id,
        'actor_id' => $actor->id,
        'inspection_id' => $source['inspection_id'],
        'action' => $action,
        'command_key' => $key,
        'reason' => 'P17 controlled terminal decision ' . Str::lower(Str::random(6)),
    ];

    $result['error_stage'] = 'scenarios';
    $source = $fixture($actorsA, 'A1');
    $scenario = $runPair('competing_claimants',
        $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'claim', 'p17-a1-' . Str::uuid()),
        $workerConfig($actorsA, $source, $actorsA['inspector_b'], 'claim', 'p17-a2-' . Str::uuid()));
    $scenario['claim_count'] = DB::table('room_inspections')->where('id', $source['inspection_id'])->whereNotNull('claim_evidence_version')->count();
    $scenario['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_inspection_claimed')->where('auditable_id', $source['inspection_id'])->count();
    $result['scenarios']['competing_claimants'] = $scenario;

    $source = $fixture($actorsA, 'B');
    $key = 'p17-replay-' . Str::uuid();
    $scenario = $runPair('exact_replay', $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'claim', $key), $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'claim', $key));
    $scenario['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_inspection_claimed')->where('auditable_id', $source['inspection_id'])->count();
    $result['scenarios']['exact_replay'] = $scenario;

    $sourceA = $fixture($actorsA, 'C1');
    $sourceB = $fixture($actorsA, 'C2');
    $key = 'p17-conflict-' . Str::uuid();
    $scenario = $runPair('conflicting_key', $workerConfig($actorsA, $sourceA, $actorsA['inspector_a'], 'claim', $key), $workerConfig($actorsA, $sourceB, $actorsA['inspector_a'], 'claim', $key));
    $scenario['claim_count'] = DB::table('room_inspections')->whereIn('id', [$sourceA['inspection_id'], $sourceB['inspection_id']])->whereNotNull('claim_evidence_version')->count();
    $result['scenarios']['conflicting_key'] = $scenario;

    $source = $fixture($actorsA, 'D');
    $scenario = $runPair('cleaner_race', $workerConfig($actorsA, $source, $actorsA['cleaner'], 'claim', 'p17-cleaner-' . Str::uuid()), $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'claim', 'p17-inspector-' . Str::uuid()));
    $scenario['cleaner_id'] = $source['cleaner_id'];
    $scenario['claimant_id'] = DB::table('room_inspections')->where('id', $source['inspection_id'])->value('supervisor_id');
    $result['scenarios']['cleaner_race'] = $scenario;

    $source = $fixture($actorsA, 'E');
    $claimFixture($actorsA, $source, 'p17-e-owner');
    $scenario = $runPair('terminal_owner', $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'fail', 'unused-e-a'), $workerConfig($actorsA, $source, $actorsA['inspector_b'], 'pass', 'unused-e-b'));
    $scenario['inspection_status'] = DB::table('room_inspections')->where('id', $source['inspection_id'])->value('status');
    $result['scenarios']['terminal_owner'] = $scenario;

    $source = $fixture($actorsA, 'F');
    $claimFixture($actorsA, $source, 'p17-f-owner');
    $reason = 'P17 concurrent claimant terminal decision';
    $scenario = $runPair('pass_fail', $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'pass', 'unused-f-a', ['reason' => $reason]), $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'fail', 'unused-f-b', ['reason' => $reason]));
    $scenario['inspection_status'] = DB::table('room_inspections')->where('id', $source['inspection_id'])->value('status');
    $result['scenarios']['pass_fail'] = $scenario;

    $source = $fixture($actorsB, 'G');
    $scenario = $runPair('cross_property', $workerConfig($actorsA, $source, $actorsA['inspector_a'], 'claim', 'p17-cross-a'), $workerConfig($actorsB, $source, $actorsB['inspector_a'], 'claim', 'p17-cross-b'));
    $scenario['sibling_claim_count'] = DB::table('room_inspections')->where('id', $source['inspection_id'])->where('property_id', $actorsB['property']->id)->whereNotNull('claim_evidence_version')->count();
    $result['scenarios']['cross_property'] = $scenario;

    $sourceA = $fixture($actorsA, 'H1');
    $sourceB = $fixture($actorsB, 'H2');
    $scenario = $runPair('different_properties', $workerConfig($actorsA, $sourceA, $actorsA['inspector_a'], 'claim', 'p17-independent-key', ['hold_ms' => 1500]), $workerConfig($actorsB, $sourceB, $actorsB['inspector_a'], 'claim', 'p17-independent-key'));
    $scenario['claim_count'] = DB::table('room_inspections')->whereIn('id', [$sourceA['inspection_id'], $sourceB['inspection_id']])->whereNotNull('claim_evidence_version')->count();
    $result['scenarios']['different_properties'] = $scenario;

    $result['error_stage'] = null;
} catch (Throwable) {
    $result['error_code'] = 'COORDINATOR_INTERNAL_FAILURE';
} finally {
    foreach ($running as $process) {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $result['orphan_worker_count']++;
            }
            proc_close($process);
        }
    }
    try {
        DB::disconnect('pgsql');
    } catch (Throwable) {
    }
    if ($admin instanceof PDO && $result['db_created']) {
        try {
            $statement = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
            $statement->execute([$database]);
            $admin->exec('DROP DATABASE IF EXISTS "' . $database . '"');
            $check = $admin->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
            $check->execute([$database]);
            $result['db_dropped'] = (int) $check->fetchColumn() === 0;
        } catch (Throwable) {
            $result['db_dropped'] = false;
        }
    }
    file_put_contents($config['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}

exit(0);
