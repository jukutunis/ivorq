<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || ! file_exists($configPath)) {
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
$dbName = $config['db_name'];
$barrierDir = $config['barrier_dir'];
$basePath = $config['base_path'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';

if (! preg_match('/^ivorq_concurrency_fd_a2_[a-z0-9_\-]+$/', $dbName) || $dbName === 'ivorq_testing') {
    exit(1);
}

function fda2AdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fda2QuoteId(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function fda2WaitFile(string $dir, string $name, int $timeout, bool $optional = false): void
{
    $path = $dir . '/' . $name;
    $end = time() + $timeout;
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

function fda2ReadResult(string $dir, string $id): array
{
    $path = $dir . "/result-{$id}.json";

    return file_exists($path)
        ? (json_decode(file_get_contents($path), true) ?: ['outcome' => 'PARSE_ERROR'])
        : ['outcome' => 'NO_RESULT', 'pid' => -1, 'pg_backend_pid' => -1];
}

function fda2RunWorkers(array $config, string $scenario, array $fixture, string $lockTable, string $lockId): array
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

    $workerScript = $config['base_path'] . '/tests/Postgres/Operations/FrontDesk/Support/FrontDeskAssignmentConcurrencyWorker.php';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-A.json'), $descriptor, $pipesA, $config['base_path']);
    $procB = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-B.json'), $descriptor, $pipesB, $config['base_path']);
    if (! is_resource($procA) || ! is_resource($procB)) {
        return ['outcome' => 'PROC_FAIL'];
    }
    @fclose($pipesA[0]); @fclose($pipesA[1]); @fclose($pipesB[0]); @fclose($pipesB[1]);

    fda2WaitFile($dir, 'ready-A', 60);
    fda2WaitFile($dir, 'ready-B', 60);

    $pdo = new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id FROM {$lockTable} WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $lockId]);

    touch($dir . '/start-' . $scenario . '.signal');
    fda2WaitFile($dir, 'locking-A', 60);
    fda2WaitFile($dir, 'locking-B', 60);
    usleep(250000);
    $pdo->commit();
    $pdo = null;

    fda2WaitFile($dir, 'posted-A', 60, true);
    fda2WaitFile($dir, 'posted-B', 60, true);
    @proc_close($procA);
    @proc_close($procB);

    return [
        'worker_a' => fda2ReadResult($dir, 'A'),
        'worker_b' => fda2ReadResult($dir, 'B'),
    ];
}

function fda2Counts(PDO $pdo, string $roomId): array
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM front_desk_stays) AS stay_count,
            (SELECT COUNT(*) FROM front_desk_stays WHERE current_room_id = :room AND status IN ('ROOM_ASSIGNED','CHECK_IN_CONFIRMATION_PENDING','IN_HOUSE')) AS active_room_occupancy_count,
            (SELECT COUNT(*) FROM front_desk_room_assignments WHERE room_id = :room) AS assignment_count,
            (SELECT COUNT(*) FROM front_desk_stays WHERE status = 'IN_HOUSE') AS in_house_count,
            (SELECT COUNT(*) FROM front_desk_stays s LEFT JOIN reservations r ON r.id = s.reservation_id WHERE r.id IS NULL) AS orphan_stay_count,
            (SELECT COUNT(*) FROM front_desk_room_assignments a LEFT JOIN front_desk_stays s ON s.id = a.front_desk_stay_id WHERE s.id IS NULL) AS orphan_assignment_count
    ");
    $stmt->execute(['room' => $roomId]);

    return (array) $stmt->fetch(PDO::FETCH_ASSOC);
}

$result = [
    'db_name' => $dbName,
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'assignment_fixture' => [],
    'check_in_fixture' => [],
    'assignment_concurrency' => [],
    'check_in_concurrency' => [],
    'error' => null,
    'drop_error' => null,
];

try {
    $admin = fda2AdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . fda2QuoteId($dbName));
    $admin->exec('CREATE DATABASE ' . fda2QuoteId($dbName));
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

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'FD A2 Concurrency Company', 'slug' => 'fd-a2-conc-' . \Illuminate\Support\Str::random(6),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'FD A2 Concurrency Property',
        'slug' => 'fd-a2-conc-property-' . \Illuminate\Support\Str::random(6), 'code' => 'F2' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId, 'name' => 'FD A2 Concurrency Actor',
        'email' => 'fd-a2-concurrency-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([
        \Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService::VIEW_PERMISSION,
        \Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::CREATE_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::EXECUTE_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION,
    ] as $permission) {
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    setPermissionsTeamId($propertyId);
    $actor = \Modules\Foundation\User\Models\User::findOrFail($actorId);
    $actor->givePermissionTo([
        \Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService::VIEW_PERMISSION,
        \Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::CREATE_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::EXECUTE_PERMISSION,
        \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION,
    ]);

    $seedGuest = function (string $name) use ($propertyId): string {
        $id = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('guests')->insert([
            'id' => $id, 'property_id' => $propertyId, 'guest_code' => 'GST-' . \Illuminate\Support\Str::random(6),
            'full_name' => $name, 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };
    $seedReservation = function (string $guestId, string $number, string $roomId) use ($propertyId): string {
        $id = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('reservations')->insert([
            'id' => $id, 'property_id' => $propertyId, 'reservation_number' => $number, 'primary_guest_id' => $guestId,
            'adults' => 1, 'children' => 0, 'arrival_date' => '2026-07-08', 'departure_date' => '2026-07-09',
            'nights' => 1, 'reservation_source' => 'direct', 'status' => 'confirmed', 'reserved_room_type' => 'deluxe',
            'assigned_room_id' => $roomId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };
    $seedRoom = function (string $number) use ($propertyId): string {
        $id = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('rooms')->insert([
            'id' => $id, 'property_id' => $propertyId, 'room_number' => $number, 'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected', 'readiness_state' => 'ready_for_arrival', 'occupancy_status' => 'vacant',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };

    $assignmentRoom = $seedRoom('1201');
    $reservationA = $seedReservation($seedGuest('Race Guest A'), 'RES-RACE-A', $assignmentRoom);
    $reservationB = $seedReservation($seedGuest('Race Guest B'), 'RES-RACE-B', $assignmentRoom);
    $assignmentFixture = [
        'company_id' => $companyId,
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'room_id' => $assignmentRoom,
        'reservation_a_id' => $reservationA,
        'reservation_b_id' => $reservationB,
    ];
    $result['assignment_fixture'] = $assignmentFixture;

    $workerConfig = $config + ['db_name' => $dbName, 'barrier_dir' => $barrierDir, 'base_path' => $basePath];
    $assignmentRun = fda2RunWorkers($workerConfig, 'assign', $assignmentFixture, 'rooms', $assignmentRoom);
    $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $assignmentCounts = fda2Counts($pdo, $assignmentRoom);
    $result['assignment_concurrency'] = $assignmentRun + [
        'pid_different' => ($assignmentRun['worker_a']['pid'] ?? 0) !== ($assignmentRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($assignmentRun['worker_a']['pg_backend_pid'] ?? 0) !== ($assignmentRun['worker_b']['pg_backend_pid'] ?? -1),
        'outcomes' => [$assignmentRun['worker_a']['outcome'] ?? '?', $assignmentRun['worker_b']['outcome'] ?? '?'],
        'lock_identity' => 'rooms:' . $assignmentRoom,
        'final_stay_count' => (int) $assignmentCounts['stay_count'],
        'final_active_room_occupancy_count' => (int) $assignmentCounts['active_room_occupancy_count'],
        'final_assignment_count' => (int) $assignmentCounts['assignment_count'],
        'orphan_stay_count' => (int) $assignmentCounts['orphan_stay_count'],
        'orphan_assignment_count' => (int) $assignmentCounts['orphan_assignment_count'],
    ];

    $checkInRoom = $seedRoom('1202');
    $checkInReservation = $seedReservation($seedGuest('Check In Race Guest'), 'RES-CHECKIN-RACE', $checkInRoom);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);
    session(['active_property_id' => $propertyId, 'active_company_id' => $companyId, 'current_property_id' => $propertyId]);
    $assigned = app(\Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::class)->assign($actor, $checkInReservation, $checkInRoom, null, 'seed-check-in-' . \Illuminate\Support\Str::ulid());
    $checkInFixture = [
        'company_id' => $companyId,
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'room_id' => $checkInRoom,
        'stay_id' => $assigned['stay']->id,
    ];
    $result['check_in_fixture'] = $checkInFixture;

    $checkInRun = fda2RunWorkers($workerConfig, 'check_in', $checkInFixture, 'front_desk_stays', $assigned['stay']->id);
    $checkInCounts = fda2Counts($pdo, $checkInRoom);
    $result['check_in_concurrency'] = $checkInRun + [
        'pid_different' => ($checkInRun['worker_a']['pid'] ?? 0) !== ($checkInRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($checkInRun['worker_a']['pg_backend_pid'] ?? 0) !== ($checkInRun['worker_b']['pg_backend_pid'] ?? -1),
        'outcomes' => [$checkInRun['worker_a']['outcome'] ?? '?', $checkInRun['worker_b']['outcome'] ?? '?'],
        'lock_identity' => 'front_desk_stays:' . $assigned['stay']->id,
        'final_in_house_count' => (int) $checkInCounts['in_house_count'],
        'final_active_room_occupancy_count' => (int) $checkInCounts['active_room_occupancy_count'],
        'final_assignment_count' => (int) $checkInCounts['assignment_count'],
        'orphan_stay_count' => (int) $checkInCounts['orphan_stay_count'],
        'orphan_assignment_count' => (int) $checkInCounts['orphan_assignment_count'],
    ];
    $pdo = null;
} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

try {
    \Illuminate\Support\Facades\DB::disconnect();
    \Illuminate\Support\Facades\DB::purge('pgsql');
} catch (Throwable) {
}

try {
    $admin = fda2AdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
    $stmt->execute(['db' => $dbName]);
    $admin->exec('DROP DATABASE IF EXISTS ' . fda2QuoteId($dbName));
    $admin = null;
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

if (! empty($config['result_file'])) {
    file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
}
exit(0);
