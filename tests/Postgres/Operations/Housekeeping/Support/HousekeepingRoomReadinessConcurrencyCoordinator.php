<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || ! file_exists($configPath)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (! $config || empty($config['db_name']) || empty($config['barrier_dir'])) {
    fwrite(STDERR, "CONFIG_INVALID\n");
    exit(1);
}

$dbName = $config['db_name'];
$barrierDir = $config['barrier_dir'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';
$basePath = $config['base_path'];

if (! preg_match('/^ivorq_concurrency_hk_b_[a-z0-9_\-]+$/', $dbName)) {
    fwrite(STDERR, "GUARD_DB_NAME\n");
    exit(1);
}
if (in_array($dbName, ['postgres', 'template0', 'template1', 'ivorq_testing'], true)) {
    fwrite(STDERR, "GUARD_FORBIDDEN\n");
    exit(1);
}

function hkAdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function hkQuoteId(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function hkTerminateConns(PDO $admin, string $db): int
{
    $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
    $stmt->execute(['db' => $db]);

    return $stmt->rowCount();
}

function hkActiveConns(PDO $admin, string $db): int
{
    $stmt = $admin->prepare('SELECT COUNT(*) FROM pg_stat_activity WHERE datname = :db');
    $stmt->execute(['db' => $db]);

    return (int) $stmt->fetchColumn();
}

function hkWaitFile(string $dir, string $name, int $timeout, bool $optional = false): void
{
    $path = $dir . '/' . $name;
    $end = time() + max($timeout, 5);
    while (time() < $end) {
        if (file_exists($path)) {
            return;
        }
        usleep(20000);
    }
    if (! $optional) {
        throw new RuntimeException("Timeout: {$name}");
    }
}

function hkReadResult(string $dir, string $id): array
{
    $path = $dir . "/result-{$id}.json";
    if (! file_exists($path)) {
        return ['outcome' => 'NO_RESULT', 'pid' => -1, 'pg_backend_pid' => -1];
    }

    return json_decode(file_get_contents($path), true) ?: ['outcome' => 'PARSE_ERROR'];
}

function hkWorkerConfig(string $id, array $config, string $scenario, array $fixture, string $resultFile): array
{
    return [
        'worker_id' => $id,
        'scenario' => $scenario,
        'barrier_dir' => $config['barrier_dir'],
        'result_file' => $resultFile,
        'db_name' => $config['db_name'],
        'db_host' => $config['db_host'],
        'db_port' => $config['db_port'],
        'db_user' => $config['db_user'],
        'db_pass' => $config['db_pass'],
        'base_path' => $config['base_path'],
        'fixture' => $fixture,
    ];
}

function hkRunScenario(array $config, string $scenario, array $fixture): array
{
    $dir = $config['barrier_dir'];
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && preg_match('/(ready|locking|posted|start|result|cfg)-/', basename($file))) {
            @unlink($file);
        }
    }

    $cfgA = hkWorkerConfig('A', $config, $scenario, $fixture, $dir . '/result-A.json');
    $cfgB = hkWorkerConfig('B', $config, $scenario, $fixture, $dir . '/result-B.json');
    file_put_contents($dir . '/cfg-A.json', json_encode($cfgA));
    file_put_contents($dir . '/cfg-B.json', json_encode($cfgB));

    $workerScript = $config['base_path'] . '/tests/Postgres/Operations/Housekeeping/Support/HousekeepingRoomReadinessConcurrencyWorker.php';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-A.json'), $descriptor, $pipesA, $config['base_path']);
    $procB = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-B.json'), $descriptor, $pipesB, $config['base_path']);
    if (! is_resource($procA) || ! is_resource($procB)) {
        return ['outcome' => 'PROC_FAIL'];
    }

    @fclose($pipesA[0]);
    @fclose($pipesA[1]);
    @fclose($pipesB[0]);
    @fclose($pipesB[1]);

    hkWaitFile($dir, 'ready-A', 60);
    hkWaitFile($dir, 'ready-B', 60);

    $pdo = new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE id = :room_id FOR UPDATE');
    $stmt->execute(['room_id' => $fixture['room_id']]);

    touch($dir . '/start-' . $scenario . '.signal');
    hkWaitFile($dir, 'locking-A', 60);
    hkWaitFile($dir, 'locking-B', 60);
    usleep(250000);
    $pdo->commit();
    $pdo = null;

    hkWaitFile($dir, 'posted-A', 60, true);
    hkWaitFile($dir, 'posted-B', 60, true);

    @proc_close($procA);
    @proc_close($procB);

    $rA = hkReadResult($dir, 'A');
    $rB = hkReadResult($dir, 'B');

    $db = new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $readiness = $db->query("SELECT readiness_state FROM rooms WHERE id = '{$fixture['room_id']}'")->fetchColumn();
    $transitionCount = (int) $db->query("SELECT COUNT(*) FROM housekeeping_room_readiness_transitions WHERE room_id = '{$fixture['room_id']}'")->fetchColumn();
    $orphans = (int) $db->query("SELECT COUNT(*) FROM housekeeping_room_readiness_transitions t LEFT JOIN rooms r ON r.id = t.room_id WHERE r.id IS NULL")->fetchColumn();
    $db = null;

    return [
        'worker_a' => $rA,
        'worker_b' => $rB,
        'worker_a_pid' => $rA['pid'] ?? -1,
        'worker_b_pid' => $rB['pid'] ?? -1,
        'worker_a_pg_backend_pid' => $rA['pg_backend_pid'] ?? -1,
        'worker_b_pg_backend_pid' => $rB['pg_backend_pid'] ?? -1,
        'pid_different' => ($rA['pid'] ?? 0) !== ($rB['pid'] ?? -1),
        'pg_different' => ($rA['pg_backend_pid'] ?? 0) !== ($rB['pg_backend_pid'] ?? -1),
        'outcomes' => [$rA['outcome'] ?? '?', $rB['outcome'] ?? '?'],
        'lock_identity' => 'rooms:' . $fixture['room_id'],
        'final_readiness_state' => $readiness,
        'transition_evidence_count' => $transitionCount,
        'orphan_evidence_count' => $orphans,
    ];
}

$result = [
    'db_name' => $dbName,
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'fixture' => [],
    'duplicate_concurrency' => [],
    'release_concurrency' => [],
    'error' => null,
    'drop_error' => null,
];

try {
    $admin = hkAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . hkQuoteId($dbName));
    $admin->exec('CREATE DATABASE ' . hkQuoteId($dbName));
    $admin = null;
    $result['db_created'] = true;

    chdir($basePath);
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    config(['database.connections.pgsql.database' => $dbName]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    \Illuminate\Support\Facades\DB::reconnect('pgsql');
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    $roomId = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId,
        'name' => 'HK Concurrency Company',
        'slug' => 'hk-company-' . \Illuminate\Support\Str::random(6),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId,
        'company_id' => $companyId,
        'name' => 'HK Concurrency Property',
        'slug' => 'hk-property-' . \Illuminate\Support\Str::random(6),
        'code' => 'HK' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC',
        'currency' => 'USD',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId,
        'name' => 'HK Concurrency Actor',
        'email' => 'hk-concurrency-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('rooms')->insert([
        'id' => $roomId,
        'property_id' => $propertyId,
        'room_number' => '1701',
        'room_type' => 'deluxe',
        'cleanliness_status' => 'dirty',
        'readiness_state' => 'waiting_cleaning',
        'occupancy_status' => 'vacant',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        \Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        \Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
    ] as $permission) {
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    setPermissionsTeamId($propertyId);
    $actor = \Modules\Foundation\User\Models\User::findOrFail($actorId);
    $actor->givePermissionTo([
        \Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        \Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
    ]);

    $fixture = [
        'company_id' => $companyId,
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'room_id' => $roomId,
    ];
    $result['fixture'] = $fixture;

    $workerConfig = $config + ['db_name' => $dbName, 'barrier_dir' => $barrierDir, 'base_path' => $basePath];
    $result['duplicate_concurrency'] = hkRunScenario($workerConfig, 'start_cleaning', $fixture);

    \Illuminate\Support\Facades\DB::table('rooms')
        ->where('id', $roomId)
        ->update([
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
            'updated_at' => now(),
        ]);

    $confirmationService = app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class);
    $confirmationService->confirm(
        $actor,
        \Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
        'password',
        $companyId,
        $propertyId,
        hash('sha256', 'hk-concurrency-fixture-placeholder')
    );

    $result['release_concurrency'] = hkRunScenario($workerConfig, 'release_ready', $fixture);
} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

try {
    \Illuminate\Support\Facades\DB::disconnect();
    \Illuminate\Support\Facades\DB::purge('pgsql');
} catch (Throwable $exception) {
}

try {
    $admin = hkAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    hkTerminateConns($admin, $dbName);
    for ($i = 0; $i < 10; $i++) {
        if (hkActiveConns($admin, $dbName) === 0) {
            break;
        }
        usleep(200000);
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . hkQuoteId($dbName));
    $admin = null;
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

if (! empty($config['result_file'])) {
    file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
}
exit(0);
