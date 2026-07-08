<?php

declare(strict_types=1);

$config = json_decode(file_get_contents($argv[1] ?? ''), true);
$dbName = $config['db_name'];
$barrierDir = $config['barrier_dir'];
$basePath = $config['base_path'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';

if (! preg_match('/^ivorq_concurrency_fd_a3_[a-z0-9_\-]+$/', $dbName) || $dbName === 'ivorq_testing') {
    exit(1);
}

function fdA3Admin(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fdA3Quote(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function fdA3Wait(string $dir, string $name, int $timeout, bool $optional = false): void
{
    $end = time() + $timeout;
    while (time() < $end) {
        if (file_exists($dir . '/' . $name)) {
            return;
        }
        usleep(20000);
    }
    if (! $optional) {
        throw new RuntimeException("Timeout: {$name}");
    }
}

function fdA3Read(string $dir, string $id): array
{
    $path = $dir . "/result-{$id}.json";
    return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: ['outcome' => 'PARSE_ERROR']) : ['outcome' => 'NO_RESULT'];
}

function fdA3Run(array $config, string $scenario, array $fixture, string $lockTable, string $lockId): array
{
    $dir = $config['barrier_dir'];
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && preg_match('/(ready|locking|posted|start|result|cfg)-/', basename($file))) {
            @unlink($file);
        }
    }

    foreach (['A', 'B'] as $id) {
        file_put_contents($dir . "/cfg-{$id}.json", json_encode([
            'worker_id' => $id,
            'scenario' => $scenario,
            'barrier_dir' => $dir,
            'result_file' => $dir . "/result-{$id}.json",
            'db_name' => $config['db_name'],
            'base_path' => $config['base_path'],
            'fixture' => $fixture,
        ]));
    }

    $worker = $config['base_path'] . '/tests/Postgres/Operations/FrontDesk/Support/FrontDeskRoomMoveConcurrencyWorker.php';
    $procA = proc_open(PHP_BINARY . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($dir . '/cfg-A.json'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipesA, $config['base_path']);
    $procB = proc_open(PHP_BINARY . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($dir . '/cfg-B.json'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipesB, $config['base_path']);
    if (! is_resource($procA) || ! is_resource($procB)) {
        return ['outcome' => 'PROC_FAIL'];
    }
    @fclose($pipesA[0]); @fclose($pipesA[1]); @fclose($pipesB[0]); @fclose($pipesB[1]);

    fdA3Wait($dir, 'ready-A', 60);
    fdA3Wait($dir, 'ready-B', 60);

    $pdo = new PDO("pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}", $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id FROM {$lockTable} WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $lockId]);
    touch($dir . '/start-' . $scenario . '.signal');
    fdA3Wait($dir, 'locking-A', 60);
    fdA3Wait($dir, 'locking-B', 60);
    usleep(250000);
    $pdo->commit();
    $pdo = null;

    fdA3Wait($dir, 'posted-A', 60, true);
    fdA3Wait($dir, 'posted-B', 60, true);
    @proc_close($procA); @proc_close($procB);

    return ['worker_a' => fdA3Read($dir, 'A'), 'worker_b' => fdA3Read($dir, 'B')];
}

function fdA3SeedRoom(string $propertyId, string $number): string
{
    $id = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('rooms')->insert([
        'id' => $id, 'property_id' => $propertyId, 'room_number' => $number, 'room_type' => 'deluxe',
        'cleanliness_status' => 'inspected', 'readiness_state' => 'ready_for_arrival', 'occupancy_status' => 'vacant',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function fdA3SeedGuest(string $propertyId, string $name): string
{
    $id = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $id, 'property_id' => $propertyId, 'guest_code' => 'GST-' . \Illuminate\Support\Str::random(6),
        'full_name' => $name, 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function fdA3SeedReservation(string $propertyId, string $guestId, string $roomId, string $number): string
{
    $id = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $id, 'property_id' => $propertyId, 'reservation_number' => $number, 'primary_guest_id' => $guestId,
        'adults' => 1, 'children' => 0, 'arrival_date' => '2026-07-08', 'departure_date' => '2026-07-09',
        'nights' => 1, 'reservation_source' => 'direct', 'status' => 'confirmed', 'reserved_room_type' => 'deluxe',
        'assigned_room_id' => $roomId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function fdA3CheckedInStay($actor, string $companyId, string $propertyId, string $sourceRoom): array
{
    $guest = fdA3SeedGuest($propertyId, 'FD A3 Race Guest');
    $reservation = fdA3SeedReservation($propertyId, $guest, $sourceRoom, 'RES-FD3-' . \Illuminate\Support\Str::random(6));
    $assigned = app(\Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::class)->assign($actor, $reservation, $sourceRoom, null, 'assign-' . \Illuminate\Support\Str::ulid());
    $context = 'check-in-' . \Illuminate\Support\Str::ulid();
    $hash = app(\Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::class)->prepareConfirmation($actor, $assigned['stay']->id, $context);
    app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm($actor, \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::INTENT, 'password', $companyId, $propertyId, $hash);
    $stay = app(\Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::class)->checkIn($actor, $assigned['stay']->id, $context);
    return [$stay->id, $reservation, $guest];
}

function fdA3Counts(PDO $pdo, string $targetRoomId): array
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM front_desk_stays WHERE current_room_id = :room AND status = 'IN_HOUSE') AS active_target_room_occupancy_count,
            (SELECT COUNT(*) FROM front_desk_room_assignments WHERE room_id = :room AND assignment_kind = 'ROOM_MOVE') AS room_move_assignment_count,
            (SELECT COUNT(*) FROM front_desk_room_assignments WHERE assignment_kind = 'INITIAL_ASSIGNMENT') AS initial_assignment_count,
            (SELECT COUNT(*) FROM front_desk_room_assignments a LEFT JOIN front_desk_stays s ON s.id = a.front_desk_stay_id WHERE s.id IS NULL) AS orphan_assignment_count
    ");
    $stmt->execute(['room' => $targetRoomId]);
    return (array) $stmt->fetch(PDO::FETCH_ASSOC);
}

$result = [
    'db_name' => $dbName,
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'same_target_fixture' => [],
    'duplicate_fixture' => [],
    'same_target_room_move' => [],
    'duplicate_same_stay_move' => [],
    'error' => null,
    'drop_error' => null,
];

try {
    $admin = fdA3Admin($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . fdA3Quote($dbName));
    $admin->exec('CREATE DATABASE ' . fdA3Quote($dbName));
    $result['db_created'] = true;

    chdir($basePath);
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => $dbName]);
    \Illuminate\Support\Facades\DB::purge('pgsql'); \Illuminate\Support\Facades\DB::reconnect('pgsql');
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert(['id' => $companyId, 'name' => 'FD A3 Concurrency Company', 'slug' => 'fd-a3-' . \Illuminate\Support\Str::random(6), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('properties')->insert(['id' => $propertyId, 'company_id' => $companyId, 'name' => 'FD A3 Concurrency Property', 'slug' => 'fd-a3-property-' . \Illuminate\Support\Str::random(6), 'code' => 'F3' . \Illuminate\Support\Str::random(2), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $actorId, 'name' => 'FD A3 Concurrency Actor', 'email' => 'fd-a3-' . \Illuminate\Support\Str::random(6) . '@example.test', 'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([\Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService::VIEW_PERMISSION, \Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::CREATE_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::EXECUTE_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService::EXECUTE_PERMISSION] as $permission) {
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    setPermissionsTeamId($propertyId);
    $actor = \Modules\Foundation\User\Models\User::findOrFail($actorId);
    $actor->givePermissionTo([\Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService::VIEW_PERMISSION, \Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::CREATE_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::EXECUTE_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION, \Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService::EXECUTE_PERMISSION]);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);
    session(['active_property_id' => $propertyId, 'active_company_id' => $companyId, 'current_property_id' => $propertyId]);

    $target = fdA3SeedRoom($propertyId, '1609');
    [$stayA] = fdA3CheckedInStay($actor, $companyId, $propertyId, fdA3SeedRoom($propertyId, '1601'));
    [$stayB] = fdA3CheckedInStay($actor, $companyId, $propertyId, fdA3SeedRoom($propertyId, '1602'));
    $sameFixture = ['company_id' => $companyId, 'property_id' => $propertyId, 'actor_id' => $actorId, 'stay_a_id' => $stayA, 'stay_b_id' => $stayB, 'target_room_id' => $target, 'move_reason' => 'Concurrent same target move'];
    $result['same_target_fixture'] = $sameFixture;
    $workerConfig = $config + ['db_name' => $dbName, 'barrier_dir' => $barrierDir, 'base_path' => $basePath];
    $sameRun = fdA3Run($workerConfig, 'same_target', $sameFixture, 'rooms', $target);
    $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sameCounts = fdA3Counts($pdo, $target);
    $result['same_target_room_move'] = $sameRun + ['pid_different' => ($sameRun['worker_a']['pid'] ?? 0) !== ($sameRun['worker_b']['pid'] ?? -1), 'pg_different' => ($sameRun['worker_a']['pg_backend_pid'] ?? 0) !== ($sameRun['worker_b']['pg_backend_pid'] ?? -1), 'outcomes' => [$sameRun['worker_a']['outcome'] ?? '?', $sameRun['worker_b']['outcome'] ?? '?'], 'lock_identity' => 'rooms:' . $target, 'final_active_target_room_occupancy_count' => (int) $sameCounts['active_target_room_occupancy_count'], 'final_room_move_assignment_count' => (int) $sameCounts['room_move_assignment_count'], 'historical_initial_assignment_count' => (int) $sameCounts['initial_assignment_count'], 'orphan_assignment_count' => (int) $sameCounts['orphan_assignment_count']];

    $target2 = fdA3SeedRoom($propertyId, '1619');
    [$stayC] = fdA3CheckedInStay($actor, $companyId, $propertyId, fdA3SeedRoom($propertyId, '1611'));
    $dupFixture = ['company_id' => $companyId, 'property_id' => $propertyId, 'actor_id' => $actorId, 'stay_a_id' => $stayC, 'target_room_id' => $target2, 'move_reason' => 'Concurrent duplicate same stay move'];
    $result['duplicate_fixture'] = ['stay_id' => $stayC, 'target_room_id' => $target2];
    $dupRun = fdA3Run($workerConfig, 'duplicate_same_stay', $dupFixture, 'front_desk_stays', $stayC);
    $dupCounts = fdA3Counts($pdo, $target2);
    $result['duplicate_same_stay_move'] = $dupRun + ['pid_different' => ($dupRun['worker_a']['pid'] ?? 0) !== ($dupRun['worker_b']['pid'] ?? -1), 'pg_different' => ($dupRun['worker_a']['pg_backend_pid'] ?? 0) !== ($dupRun['worker_b']['pg_backend_pid'] ?? -1), 'outcomes' => [$dupRun['worker_a']['outcome'] ?? '?', $dupRun['worker_b']['outcome'] ?? '?'], 'lock_identity' => 'front_desk_stays:' . $stayC, 'final_active_target_room_occupancy_count' => (int) $dupCounts['active_target_room_occupancy_count'], 'final_room_move_assignment_count' => (int) $dupCounts['room_move_assignment_count'], 'historical_initial_assignment_count' => (int) $dupCounts['initial_assignment_count'] - 2, 'orphan_assignment_count' => (int) $dupCounts['orphan_assignment_count']];
    $pdo = null;
} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

try { \Illuminate\Support\Facades\DB::disconnect(); \Illuminate\Support\Facades\DB::purge('pgsql'); } catch (Throwable) {}

try {
    $admin = fdA3Admin($dbHost, $dbPort, $dbUser, $dbPass);
    $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
    $stmt->execute(['db' => $dbName]);
    $admin->exec('DROP DATABASE IF EXISTS ' . fdA3Quote($dbName));
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
exit(0);
