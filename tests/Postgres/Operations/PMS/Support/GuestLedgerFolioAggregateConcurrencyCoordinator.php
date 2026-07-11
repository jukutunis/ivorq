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

if (! preg_match('/^ivorq_concurrency_glf_a_[a-z0-9_\-]+$/', $dbName) || $dbName === 'ivorq_testing') {
    exit(1);
}

function glfAAdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function glfAQuoteId(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function glfAWaitFile(string $dir, string $name, int $timeout): void
{
    $path = $dir . '/' . $name;
    $end = time() + $timeout;
    while (time() < $end) {
        if (file_exists($path)) { return; }
        usleep(20000);
    }
    throw new RuntimeException("Timeout: {$name}");
}

function glfAReadResult(string $dir, string $id): array
{
    $path = $dir . "/result-{$id}.json";
    return file_exists($path)
        ? (json_decode(file_get_contents($path), true) ?: ['outcome' => 'PARSE_ERROR'])
        : ['outcome' => 'NO_RESULT'];
}

function glfARunWorkers(array $config, string $scenario, array $fixture): array
{
    $dir = $config['barrier_dir'];
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && preg_match('/(ready|start|result|cfg)-/', basename($file))) {
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

    $workerScript = $config['base_path'] . '/tests/Postgres/Operations/PMS/Support/GuestLedgerFolioAggregateConcurrencyWorker.php';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-A.json'), $descriptor, $pipesA, $config['base_path']);
    $procB = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-B.json'), $descriptor, $pipesB, $config['base_path']);
    if (! is_resource($procA) || ! is_resource($procB)) {
        return ['outcome' => 'PROC_FAIL'];
    }
    @fclose($pipesA[0]); @fclose($pipesA[1]); @fclose($pipesB[0]); @fclose($pipesB[1]);

    glfAWaitFile($dir, 'ready-A', 60);
    glfAWaitFile($dir, 'ready-B', 60);

    touch($dir . '/start-' . $scenario . '.signal');

    glfAWaitFile($dir, 'result-A-ready', 60);
    glfAWaitFile($dir, 'result-B-ready', 60);
    @proc_close($procA);
    @proc_close($procB);

    return [
        'worker_a' => glfAReadResult($dir, 'A'),
        'worker_b' => glfAReadResult($dir, 'B'),
    ];
}

$result = [
    'db_name' => $dbName,
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'same_key_concurrency' => [],
    'different_key_concurrency' => [],
    'error' => null,
    'drop_error' => null,
];

try {
    $admin = glfAAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . glfAQuoteId($dbName));
    $admin->exec('CREATE DATABASE ' . glfAQuoteId($dbName));
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

    // Seed fixture
    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    $guestId = (string) \Illuminate\Support\Str::ulid();
    $reservationId = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'GLF-A Concurrency Co', 'slug' => 'glf-a-conc-' . \Illuminate\Support\Str::random(6),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'GLF-A Concurrency Property',
        'slug' => 'glf-a-conc-prop-' . \Illuminate\Support\Str::random(6), 'code' => 'GA' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId, 'name' => 'GLF-A Concurrency Actor',
        'email' => 'glf-a-conc-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('property_user')->insert([
        'user_id' => $actorId, 'property_id' => $propertyId,
        'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $guestId, 'property_id' => $propertyId, 'guest_code' => 'GST-CONC',
        'full_name' => 'Concurrency Guest', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $reservationId, 'property_id' => $propertyId, 'reservation_number' => 'RES-CONC',
        'primary_guest_id' => $guestId, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fixture = [
        'company_id' => $companyId,
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'guest_id' => $guestId,
        'reservation_id' => $reservationId,
    ];

    $workerConfig = $config + ['db_name' => $dbName, 'barrier_dir' => $barrierDir, 'base_path' => $basePath];

    // Same-key concurrency
    $sameKeyRun = glfARunWorkers($workerConfig, 'same_key', $fixture);

    $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt, MAX(window_number) AS max_win FROM folios WHERE reservation_id = :rid');
    $stmt->execute(['rid' => $reservationId]);
    $sameKeyCounts = $stmt->fetch(PDO::FETCH_ASSOC);

    $result['same_key_concurrency'] = $sameKeyRun + [
        'pid_different' => ($sameKeyRun['worker_a']['pid'] ?? 0) !== ($sameKeyRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($sameKeyRun['worker_a']['pg_backend_pid'] ?? 0) !== ($sameKeyRun['worker_b']['pg_backend_pid'] ?? -1),
        'folio_count' => (int) ($sameKeyCounts['cnt'] ?? -1),
        'max_window' => (int) ($sameKeyCounts['max_win'] ?? -1),
    ];

    // Different-key concurrency — need a second reservation
    $guestId2 = (string) \Illuminate\Support\Str::ulid();
    $reservationId2 = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $guestId2, 'property_id' => $propertyId, 'guest_code' => 'GST-CONC2',
        'full_name' => 'Concurrency Guest 2', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $reservationId2, 'property_id' => $propertyId, 'reservation_number' => 'RES-CONC2',
        'primary_guest_id' => $guestId2, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fixture2 = $fixture;
    $fixture2['guest_id'] = $guestId2;
    $fixture2['reservation_id'] = $reservationId2;

    $diffKeyRun = glfARunWorkers($workerConfig, 'different_key', $fixture2);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM folios WHERE reservation_id = :rid');
    $stmt->execute(['rid' => $reservationId2]);
    $diffKeyCounts = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT DISTINCT window_number FROM folios WHERE reservation_id = :rid ORDER BY window_number');
    $stmt->execute(['rid' => $reservationId2]);
    $windows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $result['different_key_concurrency'] = $diffKeyRun + [
        'pid_different' => ($diffKeyRun['worker_a']['pid'] ?? 0) !== ($diffKeyRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($diffKeyRun['worker_a']['pg_backend_pid'] ?? 0) !== ($diffKeyRun['worker_b']['pg_backend_pid'] ?? -1),
        'folio_count' => (int) ($diffKeyCounts['cnt'] ?? -1),
        'windows' => array_map('intval', $windows),
    ];

    // ── Cross-reservation concurrency (same property, different reservations) ─
    $guestA = (string) \Illuminate\Support\Str::ulid();
    $guestB = (string) \Illuminate\Support\Str::ulid();
    $resA = (string) \Illuminate\Support\Str::ulid();
    $resB = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('guests')->insert([
        ['id' => $guestA, 'property_id' => $propertyId, 'guest_code' => 'GST-CR-A', 'full_name' => 'Cross Res Guest A', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $guestB, 'property_id' => $propertyId, 'guest_code' => 'GST-CR-B', 'full_name' => 'Cross Res Guest B', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now()],
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        ['id' => $resA, 'property_id' => $propertyId, 'reservation_number' => 'RES-CR-A', 'primary_guest_id' => $guestA, 'adults' => 1, 'children' => 0, 'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $resB, 'property_id' => $propertyId, 'reservation_number' => 'RES-CR-B', 'primary_guest_id' => $guestB, 'adults' => 1, 'children' => 0, 'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $crFixture = [
        'company_id' => $companyId, 'property_id' => $propertyId, 'actor_id' => $actorId,
        'guest_id_a' => $guestA, 'reservation_id_a' => $resA,
        'guest_id_b' => $guestB, 'reservation_id_b' => $resB,
    ];

    $crRun = glfARunWorkers($workerConfig, 'cross_reservation', $crFixture);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM folios WHERE reservation_id IN (:ra, :rb)');
    $stmt->execute(['ra' => $resA, 'rb' => $resB]);
    $crCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT DISTINCT window_number FROM folios WHERE reservation_id = :rid ORDER BY window_number");
    $stmt->execute(['rid' => $resA]);
    $winA = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt->execute(['rid' => $resB]);
    $winB = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $result['cross_reservation_concurrency'] = $crRun + [
        'pid_different' => ($crRun['worker_a']['pid'] ?? 0) !== ($crRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($crRun['worker_a']['pg_backend_pid'] ?? 0) !== ($crRun['worker_b']['pg_backend_pid'] ?? -1),
        'folio_count' => $crCount,
        'windows_a' => $winA,
        'windows_b' => $winB,
    ];

    // ── Cross-property concurrency ────────────────────────────────────────
    $propertyId2 = (string) \Illuminate\Support\Str::ulid();
    $actorId2 = (string) \Illuminate\Support\Str::ulid();
    $guestP2 = (string) \Illuminate\Support\Str::ulid();
    $resP2 = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId2, 'company_id' => $companyId, 'name' => 'GLF-A Property 2',
        'slug' => 'glf-a-conc-prop2-' . \Illuminate\Support\Str::random(6), 'code' => 'GB' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC', 'currency' => 'EUR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId2, 'name' => 'GLF-A Actor P2',
        'email' => 'glf-a-conc-p2-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('property_user')->insert([
        ['user_id' => $actorId, 'property_id' => $propertyId2, 'is_default' => false, 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $actorId2, 'property_id' => $propertyId2, 'is_default' => true, 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $guestP2, 'property_id' => $propertyId2, 'guest_code' => 'GST-CP',
        'full_name' => 'Cross Property Guest', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $resP2, 'property_id' => $propertyId2, 'reservation_number' => 'RES-CP',
        'primary_guest_id' => $guestP2, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Create fresh reservations for cross-property (avoid reusing ones from earlier scenarios)
    $cpGuestA = (string) \Illuminate\Support\Str::ulid();
    $cpResA = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $cpGuestA, 'property_id' => $propertyId, 'guest_code' => 'GST-CPA',
        'full_name' => 'Cross Prop Guest A', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $cpResA, 'property_id' => $propertyId, 'reservation_number' => 'RES-CPA',
        'primary_guest_id' => $cpGuestA, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $cpFixture = [
        'company_id' => $companyId, 'property_id_a' => $propertyId, 'property_id_b' => $propertyId2,
        'actor_id' => $actorId, 'actor_id_b' => $actorId2,
        'reservation_id_a' => $cpResA, 'guest_id_a' => $cpGuestA,
        'reservation_id_b' => $resP2, 'guest_id_b' => $guestP2,
    ];

    // Count folios before this scenario
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM folios');
    $stmt->execute();
    $cpBefore = (int) $stmt->fetchColumn();

    $cpRun = glfARunWorkers($workerConfig, 'cross_property', $cpFixture);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM folios');
    $stmt->execute();
    $cpAfter = (int) $stmt->fetchColumn();

    $result['cross_property_concurrency'] = $cpRun + [
        'pid_different' => ($cpRun['worker_a']['pid'] ?? 0) !== ($cpRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($cpRun['worker_a']['pg_backend_pid'] ?? 0) !== ($cpRun['worker_b']['pg_backend_pid'] ?? -1),
        'total_folios' => $cpAfter - $cpBefore,
        'property_id_a' => $propertyId,
        'property_id_b' => $propertyId2,
    ];

    // ── Post versus Void concurrency ───────────────────────────────────
    $pvPropertyId = $propertyId;
    $pvGuestId = (string) \Illuminate\Support\Str::ulid();
    $pvResId = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $pvGuestId, 'property_id' => $pvPropertyId, 'guest_code' => 'GST-PV',
        'full_name' => 'PostVoid Guest', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $pvResId, 'property_id' => $pvPropertyId, 'reservation_number' => 'RES-PV',
        'primary_guest_id' => $pvGuestId, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Setup: create folio with one charge through the aggregate
    \Illuminate\Support\Facades\Auth::login(\Modules\Foundation\User\Models\User::findOrFail($actorId));
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($pvPropertyId);
    session(['current_property_id' => $pvPropertyId]);

    $pvFolio = app(\Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService::class)
        ->openWindow(\Modules\Foundation\User\Models\User::findOrFail($actorId), $pvResId, 'pv-setup-key');

    $targetItem = app(\Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService::class)
        ->postItem(\Modules\Foundation\User\Models\User::findOrFail($actorId), $pvFolio->id, [
            'item_type'   => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::RoomCharge,
            'description' => 'Original charge to void',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

    $pvFixture = [
        'company_id' => $companyId, 'property_id' => $pvPropertyId, 'actor_id' => $actorId,
        'folio_id' => $pvFolio->id, 'void_target_item_id' => $targetItem->id,
    ];

    $pvRun = glfARunWorkers($workerConfig, 'post_vs_void', $pvFixture);

    // Final state: count active items, check individual item void state
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM folio_items WHERE folio_id = :fid AND is_void = false");
    $stmt->execute(['fid' => $pvFolio->id]);
    $activeItems = (int) $stmt->fetchColumn();

    // Verify original target item is void
    $stmt = $pdo->prepare("SELECT is_void FROM folio_items WHERE id = :iid");
    $stmt->execute(['iid' => $targetItem->id]);
    $originalIsVoid = (bool) $stmt->fetchColumn();

    // Verify new item (from worker A) is active
    $newItemId = $pvRun['worker_a']['item_id'] ?? null;
    $newItemActive = null;
    $newItemExists = null;
    if ($newItemId) {
        $stmt = $pdo->prepare("SELECT is_void FROM folio_items WHERE id = :iid");
        $stmt->execute(['iid' => $newItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $newItemExists = $row !== false;
        $newItemActive = $newItemExists && ! ((bool) $row['is_void']);
    }

    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN amount > 0 AND is_void = false THEN amount ELSE 0 END), 0)::numeric(12,2)::text AS charges,
        COALESCE(SUM(CASE WHEN amount < 0 AND is_void = false THEN ABS(amount) ELSE 0 END), 0)::numeric(12,2)::text AS payments,
        (COALESCE(SUM(CASE WHEN amount > 0 AND is_void = false THEN amount ELSE 0 END), 0)
         - COALESCE(SUM(CASE WHEN amount < 0 AND is_void = false THEN ABS(amount) ELSE 0 END), 0))::numeric(12,2)::text AS balance
    FROM folio_items WHERE folio_id = :fid");
    $stmt->execute(['fid' => $pvFolio->id]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    $freshCharges  = $fresh['charges'];
    $freshPayments = $fresh['payments'];
    $freshBalance  = $fresh['balance'];

    // Read cached totals from folio row
    $stmt = $pdo->prepare("SELECT total_charges, total_payments, balance, status FROM folios WHERE id = :fid");
    $stmt->execute(['fid' => $pvFolio->id]);
    $folioState = $stmt->fetch(PDO::FETCH_ASSOC);

    $result['post_vs_void_concurrency'] = $pvRun + [
        'pid_different' => ($pvRun['worker_a']['pid'] ?? 0) !== ($pvRun['worker_b']['pid'] ?? -1),
        'pg_different' => ($pvRun['worker_a']['pg_backend_pid'] ?? 0) !== ($pvRun['worker_b']['pg_backend_pid'] ?? -1),
        'active_item_count' => $activeItems,
        'original_item_is_void' => $originalIsVoid,
        'new_item_exists' => $newItemExists,
        'new_item_is_active' => $newItemActive,
        'final_charges' => $folioState['total_charges'] ?? '0.00',
        'final_payments' => $folioState['total_payments'] ?? '0.00',
        'final_balance' => $folioState['balance'] ?? '0.00',
        'fresh_charges' => $freshCharges,
        'fresh_payments' => $freshPayments,
        'fresh_balance' => $freshBalance,
        'folio_status' => $folioState['status'] ?? 'unknown',
    ];

    $pdo = null;

} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

try {
    \Illuminate\Support\Facades\DB::disconnect();
    \Illuminate\Support\Facades\DB::purge('pgsql');
} catch (Throwable) {}

try {
    $admin = glfAAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
    $stmt->execute(['db' => $dbName]);
    $admin->exec('DROP DATABASE IF EXISTS ' . glfAQuoteId($dbName));
    $admin = null;
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

if (! empty($config['result_file'])) {
    file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
}
exit(0);
