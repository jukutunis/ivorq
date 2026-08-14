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
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimRecoveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

$configPath = $argv[1] ?? '';
$settings = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($settings)) {
    exit(2);
}

$boot = static function (array $config): void {
    require_once $config['base_path'].'/vendor/autoload.php';
    $app = require $config['base_path'].'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => $config['db_host'],
        'database.connections.pgsql.port' => $config['db_port'],
        'database.connections.pgsql.database' => $config['db_name'],
        'database.connections.pgsql.username' => $config['db_user'],
        'database.connections.pgsql.password' => $config['db_pass'],
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
};

if (($settings['mode'] ?? '') === 'worker') {
    $result = [
        'pid' => getmypid(),
        'pg_backend_pid' => 0,
        'process_exit_code' => 0,
        'outcome' => 'INTERNAL_FAILURE',
        'replacement_id' => $settings['replacement_id'] ?? null,
        'transaction_level' => -1,
    ];
    try {
        $boot($settings);
        session([
            'active_company_id' => $settings['company_id'],
            'active_property_id' => $settings['property_id'],
            'current_property_id' => $settings['property_id'],
        ]);
        app(CurrentPropertyService::class)->setPropertyId($settings['property_id']);
        setPermissionsTeamId($settings['property_id']);
        $actor = User::withoutGlobalScopes()->findOrFail($settings['actor_id']);
        $service = app(HousekeepingInspectionClaimRecoveryService::class);
        $service->confirmReassignment(
            $actor,
            $settings['inspection_id'],
            $settings['replacement_id'],
            $settings['reason'],
            $settings['command_key'],
            'password',
        );
        $result['pg_backend_pid'] = (int) DB::scalar('SELECT pg_backend_pid()');
        touch($settings['ready_file']);
        for ($attempt = 0; $attempt < 12000 && ! is_file($settings['start_file']); $attempt++) {
            usleep(10000);
        }
        $recovery = $service->reassign(
            $actor,
            $settings['inspection_id'],
            $settings['replacement_id'],
            $settings['reason'],
            $settings['command_key'],
        );
        $result['outcome'] = $recovery->replayed ? 'REPLAYED' : 'RECOVERED';
        $result['evidence_id'] = $recovery->reassignment->id;
    } catch (DomainException|HttpException|Illuminate\Database\QueryException) {
        $result['outcome'] = 'CONTROLLED_REJECTION';
    } catch (Throwable) {
        $result['outcome'] = 'INTERNAL_FAILURE';
    } finally {
        try {
            $result['transaction_level'] = DB::transactionLevel();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('pgsql');
        } catch (Throwable) {
        }
        file_put_contents($settings['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }
    exit(0);
}

$result = [
    'db_created' => false,
    'migrations_ok' => false,
    'protected_database' => 'ivorq_testing',
    'worker_a' => [],
    'worker_b' => [],
    'recovery_count' => -1,
    'audit_count' => -1,
    'original_fields_unchanged' => false,
    'one_effective_claimant' => false,
    'db_dropped' => false,
    'error_code' => null,
];
$admin = null;
$processes = [];

try {
    if (! preg_match('/^ivorq_concurrency_hk_p19_[a-z0-9]+$/', $settings['db_name'])) {
        throw new RuntimeException('DATABASE_NAME_REJECTED');
    }
    $admin = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $settings['db_host'], $settings['db_port']),
        $settings['db_user'],
        $settings['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $admin->exec('CREATE DATABASE "'.$settings['db_name'].'"');
    $result['db_created'] = true;
    $boot($settings);
    Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = Artisan::output() !== null;

    $company = Company::create(['name' => 'P19 Concurrency', 'slug' => 'p19-concurrency-'.Str::lower(Str::random(8)), 'is_active' => true]);
    $property = Property::create([
        'company_id' => $company->id, 'name' => 'P19 Property', 'slug' => 'p19-property-'.Str::lower(Str::random(8)),
        'code' => 'P19'.Str::upper(Str::random(6)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true,
    ]);
    session(['active_company_id' => $company->id, 'active_property_id' => $property->id, 'current_property_id' => $property->id]);
    app(CurrentPropertyService::class)->setPropertyId($property->id);
    setPermissionsTeamId($property->id);
    $makeUser = static function (string $name) use ($property): User {
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    };
    foreach (['housekeeping.inspection.conduct', 'housekeeping.inspection.approve'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $cleaner = $makeUser('P19 Cleaner');
    $original = $makeUser('P19 Original');
    $intervenor = $makeUser('P19 Intervenor');
    $replacementA = $makeUser('P19 Replacement A');
    $replacementB = $makeUser('P19 Replacement B');
    $original->givePermissionTo('housekeeping.inspection.conduct');
    $intervenor->givePermissionTo('housekeeping.inspection.approve');
    $replacementA->givePermissionTo('housekeeping.inspection.conduct');
    $replacementB->givePermissionTo('housekeeping.inspection.conduct');

    $roomId = (string) Str::ulid();
    DB::table('rooms')->insert([
        'id' => $roomId, 'property_id' => $property->id, 'room_number' => 'P19-RACE', 'room_type' => 'deluxe',
        'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'occupancy_status' => 'vacant',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $task = CleaningTask::create([
        'property_id' => $property->id, 'room_id' => $roomId, 'task_code' => 'P19-RACE-TASK',
        'task_type' => 'checkout_cleaning', 'status' => 'completed', 'started_at' => now()->subHour(),
        'completed_at' => now(), 'completed_by' => $cleaner->id,
    ]);
    $inspection = RoomInspection::create([
        'property_id' => $property->id, 'room_id' => $roomId, 'cleaning_task_id' => $task->id,
        'inspection_type' => 'post_cleaning', 'status' => 'pending',
    ]);
    app(HousekeepingInspectionClaimService::class)->claim($original, $inspection->id, 'p19-race-original');
    $fields = ['supervisor_id', 'claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'];
    $before = collect($fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all();
    $original->update(['is_active' => false]);

    $startFile = $settings['barrier_dir'].DIRECTORY_SEPARATOR.'start';
    $script = __FILE__;
    foreach (['a' => $replacementA, 'b' => $replacementB] as $label => $replacement) {
        $workerConfig = $settings['barrier_dir'].DIRECTORY_SEPARATOR."worker-{$label}.json";
        $workerResult = $settings['barrier_dir'].DIRECTORY_SEPARATOR."result-{$label}.json";
        file_put_contents($workerConfig, json_encode([
            ...$settings,
            'mode' => 'worker',
            'company_id' => $company->id,
            'property_id' => $property->id,
            'inspection_id' => $inspection->id,
            'actor_id' => $intervenor->id,
            'replacement_id' => $replacement->id,
            'reason' => "Concurrent controlled recovery {$label}.",
            'command_key' => "p19-race-command-{$label}",
            'ready_file' => $settings['barrier_dir'].DIRECTORY_SEPARATOR."ready-{$label}",
            'start_file' => $startFile,
            'result_file' => $workerResult,
        ], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, $script, $workerConfig], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $settings['base_path']);
        if (! is_resource($process)) {
            throw new RuntimeException('WORKER_START_FAILED');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $processes[$label] = ['process' => $process, 'result' => $workerResult];
    }
    for ($attempt = 0; $attempt < 1200; $attempt++) {
        if (is_file($settings['barrier_dir'].DIRECTORY_SEPARATOR.'ready-a') && is_file($settings['barrier_dir'].DIRECTORY_SEPARATOR.'ready-b')) {
            break;
        }
        usleep(100000);
    }
    touch($startFile);
    for ($attempt = 0; $attempt < 1200; $attempt++) {
        if (is_file($processes['a']['result']) && is_file($processes['b']['result'])) {
            break;
        }
        usleep(100000);
    }
    foreach ($processes as $label => $process) {
        $result["worker_{$label}"] = is_file($process['result'])
            ? (json_decode((string) file_get_contents($process['result']), true) ?: [])
            : ['outcome' => 'INTERNAL_FAILURE'];
        proc_close($process['process']);
        $processes[$label]['process'] = null;
    }
    $evidence = DB::table('housekeeping_inspection_claim_reassignments')->where('room_inspection_id', $inspection->id)->get();
    $after = collect($fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all();
    $result['recovery_count'] = $evidence->count();
    $result['audit_count'] = DB::table('audit_logs')->where('event', 'housekeeping_inspection_claim_reassigned')->count();
    $result['original_fields_unchanged'] = $before === $after;
    $result['one_effective_claimant'] = $evidence->count() === 1
        && in_array($evidence->first()->replacement_claimant_id ?? null, [$replacementA->id, $replacementB->id], true)
        && ($evidence->first()->original_claimant_id ?? null) === $original->id;
} catch (Throwable) {
    $result['error_code'] = 'COORDINATOR_FAILED';
} finally {
    try {
        DB::disconnect('pgsql');
    } catch (Throwable) {
    }
    foreach ($processes as $process) {
        if (! is_resource($process['process'])) {
            continue;
        }
        $status = proc_get_status($process['process']);
        if ($status['running'] ?? false) {
            proc_terminate($process['process']);
        }
    }
    if ($admin instanceof PDO && preg_match('/^ivorq_concurrency_hk_p19_[a-z0-9]+$/', $settings['db_name'])) {
        try {
            $quoted = $admin->quote($settings['db_name']);
            $admin->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = {$quoted}");
            $admin->exec('DROP DATABASE IF EXISTS "'.$settings['db_name'].'"');
            $result['db_dropped'] = true;
        } catch (Throwable) {
        }
    }
    file_put_contents($settings['result_file'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}
