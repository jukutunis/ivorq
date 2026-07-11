<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || !file_exists($configPath)) {
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

if (!preg_match('/^ivorq_concurrency_glf_b_[a-z0-9_\-]+$/', $dbName) || $dbName === 'ivorq_testing') {
    exit(1);
}

function glfBAdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function glfBDbPdo(string $host, string $port, string $dbName, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname={$dbName}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function glfBQuoteId(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function glfBWaitFile(string $dir, string $name, int $timeout): void
{
    $path = $dir . '/' . $name;
    $end = time() + $timeout;
    while (time() < $end) {
        if (file_exists($path)) {
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException("Timeout waiting for {$name}");
}

function glfBReadResult(string $dir, string $id): array
{
    $path = $dir . "/result-{$id}.json";
    return file_exists($path)
        ? (json_decode(file_get_contents($path), true) ?: ['outcome' => 'PARSE_ERROR'])
        : ['outcome' => 'NO_RESULT'];
}

function glfBRunWorkers(array $config, string $scenario, array $fixture): array
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
        ], JSON_PRETTY_PRINT));
    }

    $workerScript = $config['base_path'] . '/tests/Postgres/Operations/PMS/Support/GuestPaymentConcurrencyWorker.php';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-A.json'), $descriptor, $pipesA, $config['base_path']);
    $procB = proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($dir . '/cfg-B.json'), $descriptor, $pipesB, $config['base_path']);
    if (!is_resource($procA) || !is_resource($procB)) {
        return ['worker_a' => ['outcome' => 'PROC_FAIL'], 'worker_b' => ['outcome' => 'PROC_FAIL']];
    }

    @fclose($pipesA[0]);
    @fclose($pipesA[1]);
    @fclose($pipesB[0]);
    @fclose($pipesB[1]);

    glfBWaitFile($dir, 'ready-A', 60);
    glfBWaitFile($dir, 'ready-B', 60);
    touch($dir . '/start-' . $scenario . '.signal');
    glfBWaitFile($dir, 'result-A-ready', 90);
    glfBWaitFile($dir, 'result-B-ready', 90);
    @proc_close($procA);
    @proc_close($procB);

    $run = [
        'worker_a' => glfBReadResult($dir, 'A'),
        'worker_b' => glfBReadResult($dir, 'B'),
    ];
    $run['pid_different'] = ($run['worker_a']['pid'] ?? 0) !== ($run['worker_b']['pid'] ?? -1);
    $run['pg_different'] = ($run['worker_a']['pg_backend_pid'] ?? 0) !== ($run['worker_b']['pg_backend_pid'] ?? -1);
    $run['outcomes'] = [$run['worker_a']['outcome'] ?? '?', $run['worker_b']['outcome'] ?? '?'];
    sort($run['outcomes']);

    return $run;
}

function glfBTable(string $table): \Illuminate\Database\Query\Builder
{
    return \Illuminate\Support\Facades\DB::table($table);
}

function glfBSeedReservation(string $propertyId, string $prefix): array
{
    $guestId = (string) \Illuminate\Support\Str::ulid();
    $reservationId = (string) \Illuminate\Support\Str::ulid();
    glfBTable('guests')->insert([
        'id' => $guestId,
        'property_id' => $propertyId,
        'guest_code' => $prefix . '-GST',
        'full_name' => $prefix . ' Guest',
        'guest_type' => 'individual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    glfBTable('reservations')->insert([
        'id' => $reservationId,
        'property_id' => $propertyId,
        'reservation_number' => $prefix . '-RES',
        'primary_guest_id' => $guestId,
        'adults' => 1,
        'children' => 0,
        'arrival_date' => '2026-07-10',
        'departure_date' => '2026-07-12',
        'nights' => 2,
        'reservation_source' => 'walk_in',
        'status' => 'tentative',
        'reserved_room_type' => 'standard',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['guest_id' => $guestId, 'reservation_id' => $reservationId];
}

function glfBSeedFolio(string $propertyId, string $reservationId, string $guestId, string $prefix): string
{
    $folioId = (string) \Illuminate\Support\Str::ulid();
    $nextWindow = ((int) glfBTable('folios')
        ->where('property_id', $propertyId)
        ->where('reservation_id', $reservationId)
        ->max('window_number')) + 1;

    glfBTable('folios')->insert([
        'id' => $folioId,
        'property_id' => $propertyId,
        'folio_number' => $prefix . '-FOL',
        'reservation_id' => $reservationId,
        'guest_id' => $guestId,
        'status' => 'open',
        'currency' => 'USD',
        'window_number' => $nextWindow,
        'opening_idempotency_key' => $prefix . '-open',
        'total_charges' => '0.00',
        'total_payments' => '0.00',
        'balance' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $folioId;
}

function glfBActiveTotal(PDO $pdo, string $paymentId): string
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN r.id IS NULL THEN a.amount ELSE 0 END), 0)::numeric(12,2)::text
          FROM guest_payment_allocations a
          LEFT JOIN guest_payment_reversals r
            ON r.property_id = a.property_id
           AND r.guest_payment_allocation_id = a.id
           AND r.reversal_type = 'ALLOCATION_REVERSAL'
         WHERE a.guest_payment_transaction_id = :pid
    ");
    $stmt->execute(['pid' => $paymentId]);
    return $stmt->fetchColumn() ?: '0.00';
}

function glfBCounts(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$result = [
    'db_name' => $dbName,
    'protected_database' => 'ivorq_testing',
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'error' => null,
    'drop_error' => null,
];

try {
    $admin = glfBAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . glfBQuoteId($dbName));
    $admin->exec('CREATE DATABASE ' . glfBQuoteId($dbName));
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
    $sessionId = (string) \Illuminate\Support\Str::ulid();

    glfBTable('companies')->insert([
        'id' => $companyId,
        'name' => 'GLF-B Concurrency Co',
        'slug' => 'glf-b-conc-' . \Illuminate\Support\Str::random(6),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    glfBTable('properties')->insert([
        'id' => $propertyId,
        'company_id' => $companyId,
        'name' => 'GLF-B Concurrency Property',
        'slug' => 'glf-b-conc-prop-' . \Illuminate\Support\Str::random(6),
        'code' => 'GB' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC',
        'currency' => 'USD',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    glfBTable('users')->insert([
        'id' => $actorId,
        'name' => 'GLF-B Concurrency Actor',
        'email' => 'glf-b-conc-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    glfBTable('property_user')->insert([
        'user_id' => $actorId,
        'property_id' => $propertyId,
        'is_default' => true,
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    glfBTable('cashier_sessions')->insert([
        'id' => $sessionId,
        'property_id' => $propertyId,
        'cashier_user_id' => $actorId,
        'status' => 'OPEN',
        'opened_at' => now(),
        'opened_by' => $actorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::RECORD_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::ALLOCATE_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::VOID_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_PERMISSION,
    ] as $permission) {
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    \Modules\Foundation\User\Models\User::findOrFail($actorId)->givePermissionTo([
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::RECORD_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::ALLOCATE_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::VOID_PERMISSION,
        \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_PERMISSION,
    ]);

    $workerConfig = $config + ['db_name' => $dbName, 'barrier_dir' => $barrierDir, 'base_path' => $basePath];
    $baseFixture = [
        'company_id' => $companyId,
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'cashier_session_id' => $sessionId,
    ];

    $pdo = glfBDbPdo($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

    $rec = glfBSeedReservation($propertyId, 'REC');
    $run = glfBRunWorkers($workerConfig, 'recording_replay', $baseFixture + ['reservation_id' => $rec['reservation_id']]);
    $result['recording_replay'] = $run + [
        'payment_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_transactions WHERE reservation_id = :rid', ['rid' => $rec['reservation_id']]),
        'payment_number_count' => glfBCounts($pdo, 'SELECT COUNT(DISTINCT payment_number) FROM guest_payment_transactions WHERE reservation_id = :rid', ['rid' => $rec['reservation_id']]),
    ];

    $numA = glfBSeedReservation($propertyId, 'NUMA');
    $numB = glfBSeedReservation($propertyId, 'NUMB');
    $run = glfBRunWorkers($workerConfig, 'payment_number_safety', $baseFixture + [
        'reservation_id_a' => $numA['reservation_id'],
        'reservation_id_b' => $numB['reservation_id'],
    ]);
    $result['payment_number_safety'] = $run + [
        'payment_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_transactions WHERE reservation_id IN (:a, :b)', ['a' => $numA['reservation_id'], 'b' => $numB['reservation_id']]),
        'payment_number_count' => glfBCounts($pdo, 'SELECT COUNT(DISTINCT payment_number) FROM guest_payment_transactions WHERE reservation_id IN (:a, :b)', ['a' => $numA['reservation_id'], 'b' => $numB['reservation_id']]),
    ];

    \Illuminate\Support\Facades\Auth::login(\Modules\Foundation\User\Models\User::findOrFail($actorId));
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);
    session(['active_property_id' => $propertyId, 'current_property_id' => $propertyId, 'active_company_id' => $companyId]);
    $service = app(\Modules\Operations\PMS\Services\GuestPaymentLifecycleService::class);

    $alloc = glfBSeedReservation($propertyId, 'ALR');
    $allocFolio = glfBSeedFolio($propertyId, $alloc['reservation_id'], $alloc['guest_id'], 'ALR');
    $allocPayment = $service->recordCashPayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $alloc['reservation_id'], $sessionId, '50.00', 'setup-allocation-replay');
    $run = glfBRunWorkers($workerConfig, 'allocation_replay', $baseFixture + ['payment_id' => $allocPayment->id, 'folio_id' => $allocFolio]);
    $stmt = $pdo->prepare('SELECT total_payments, balance FROM folios WHERE id = :id');
    $stmt->execute(['id' => $allocFolio]);
    $folioState = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['allocation_replay'] = $run + [
        'allocation_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_allocations WHERE guest_payment_transaction_id = :pid', ['pid' => $allocPayment->id]),
        'payment_item_count' => glfBCounts($pdo, "SELECT COUNT(*) FROM folio_items WHERE guest_payment_allocation_id IS NOT NULL AND item_type = 'payment' AND folio_id = :fid", ['fid' => $allocFolio]),
        'folio_total_payments' => $folioState['total_payments'] ?? null,
        'folio_balance' => $folioState['balance'] ?? null,
    ];

    $over = glfBSeedReservation($propertyId, 'OVER');
    $overFolio = glfBSeedFolio($propertyId, $over['reservation_id'], $over['guest_id'], 'OVER');
    $overPayment = $service->recordCashPayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $over['reservation_id'], $sessionId, '100.00', 'setup-over');
    $run = glfBRunWorkers($workerConfig, 'over_allocation_race', $baseFixture + ['payment_id' => $overPayment->id, 'folio_id' => $overFolio]);
    $result['over_allocation_race'] = $run + [
        'allocation_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_allocations WHERE guest_payment_transaction_id = :pid', ['pid' => $overPayment->id]),
        'payment_item_count' => glfBCounts($pdo, "SELECT COUNT(*) FROM folio_items WHERE item_type = 'payment' AND folio_id = :fid", ['fid' => $overFolio]),
        'active_allocation_total' => glfBActiveTotal($pdo, $overPayment->id),
    ];

    $split = glfBSeedReservation($propertyId, 'SPLIT');
    $splitFolioA = glfBSeedFolio($propertyId, $split['reservation_id'], $split['guest_id'], 'SPLITA');
    $splitFolioB = glfBSeedFolio($propertyId, $split['reservation_id'], $split['guest_id'], 'SPLITB');
    $splitPayment = $service->recordCashPayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $split['reservation_id'], $sessionId, '100.00', 'setup-split');
    $run = glfBRunWorkers($workerConfig, 'valid_split_race', $baseFixture + ['payment_id' => $splitPayment->id, 'folio_id_a' => $splitFolioA, 'folio_id_b' => $splitFolioB]);
    $stmt = $pdo->prepare('SELECT lifecycle_status FROM guest_payment_transactions WHERE id = :id');
    $stmt->execute(['id' => $splitPayment->id]);
    $splitStatus = $stmt->fetchColumn();
    $result['valid_split_race'] = $run + [
        'allocation_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_allocations WHERE guest_payment_transaction_id = :pid', ['pid' => $splitPayment->id]),
        'payment_item_count' => glfBCounts($pdo, "SELECT COUNT(*) FROM folio_items WHERE item_type = 'payment' AND folio_id IN (:a, :b)", ['a' => $splitFolioA, 'b' => $splitFolioB]),
        'active_allocation_total' => glfBActiveTotal($pdo, $splitPayment->id),
        'payment_status' => $splitStatus,
        'folio_a_total_payments' => $pdo->query("SELECT total_payments FROM folios WHERE id = '{$splitFolioA}'")->fetchColumn(),
        'folio_b_total_payments' => $pdo->query("SELECT total_payments FROM folios WHERE id = '{$splitFolioB}'")->fetchColumn(),
    ];

    $dbl = glfBSeedReservation($propertyId, 'DBL');
    $dblFolio = glfBSeedFolio($propertyId, $dbl['reservation_id'], $dbl['guest_id'], 'DBL');
    $dblPayment = $service->recordCashPayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $dbl['reservation_id'], $sessionId, '70.00', 'setup-dbl');
    $dblAllocation = $service->allocatePayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $dblPayment->id, $dblFolio, '70.00', 'setup-dbl-alloc');
    $run = glfBRunWorkers($workerConfig, 'double_reversal_race', $baseFixture + ['allocation_id' => $dblAllocation->id]);
    $result['double_reversal_race'] = $run + [
        'reversal_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_reversals WHERE guest_payment_allocation_id = :aid', ['aid' => $dblAllocation->id]),
        'reversal_item_count' => glfBCounts($pdo, "SELECT COUNT(*) FROM folio_items WHERE item_type = 'payment_reversal' AND folio_id = :fid", ['fid' => $dblFolio]),
        'payment_status' => $pdo->query("SELECT lifecycle_status FROM guest_payment_transactions WHERE id = '{$dblPayment->id}'")->fetchColumn(),
        'folio_total_payments' => $pdo->query("SELECT total_payments FROM folios WHERE id = '{$dblFolio}'")->fetchColumn(),
    ];

    $avr = glfBSeedReservation($propertyId, 'AVR');
    $avrFolioA = glfBSeedFolio($propertyId, $avr['reservation_id'], $avr['guest_id'], 'AVRA');
    $avrFolioB = glfBSeedFolio($propertyId, $avr['reservation_id'], $avr['guest_id'], 'AVRB');
    $avrPayment = $service->recordCashPayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $avr['reservation_id'], $sessionId, '100.00', 'setup-avr');
    $avrAllocation = $service->allocatePayment(\Modules\Foundation\User\Models\User::findOrFail($actorId), $avrPayment->id, $avrFolioA, '40.00', 'setup-avr-alloc');
    $run = glfBRunWorkers($workerConfig, 'allocation_versus_reversal', $baseFixture + [
        'payment_id' => $avrPayment->id,
        'existing_allocation_id' => $avrAllocation->id,
        'new_folio_id' => $avrFolioB,
    ]);
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN item_type = 'payment' AND amount < 0 THEN 0 - amount WHEN item_type = 'payment_reversal' AND amount > 0 THEN 0 - amount ELSE 0 END), 0)::numeric(12,2)::text AS payments,
            COALESCE(SUM(CASE WHEN item_type = 'payment' AND amount < 0 THEN amount WHEN item_type = 'payment_reversal' AND amount > 0 THEN amount ELSE 0 END), 0)::numeric(12,2)::text AS balance
          FROM folio_items
         WHERE folio_id IN (:a, :b)
           AND is_void = false
    ");
    $stmt->execute(['a' => $avrFolioA, 'b' => $avrFolioB]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT SUM(total_payments)::numeric(12,2)::text AS payments, SUM(balance)::numeric(12,2)::text AS balance FROM folios WHERE id IN (:a, :b)");
    $stmt->execute(['a' => $avrFolioA, 'b' => $avrFolioB]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['allocation_versus_reversal_race'] = $run + [
        'allocation_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_allocations WHERE guest_payment_transaction_id = :pid', ['pid' => $avrPayment->id]),
        'reversal_count' => glfBCounts($pdo, 'SELECT COUNT(*) FROM guest_payment_reversals WHERE guest_payment_transaction_id = :pid', ['pid' => $avrPayment->id]),
        'active_allocation_total' => glfBActiveTotal($pdo, $avrPayment->id),
        'payment_status' => $pdo->query("SELECT lifecycle_status FROM guest_payment_transactions WHERE id = '{$avrPayment->id}'")->fetchColumn(),
        'fresh_total_payments' => $fresh['payments'] ?? null,
        'cached_total_payments' => $cached['payments'] ?? null,
        'fresh_balance' => $fresh['balance'] ?? null,
        'cached_balance' => $cached['balance'] ?? null,
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
    $admin = glfBAdminPdo($dbHost, $dbPort, $dbUser, $dbPass);
    $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
    $stmt->execute(['db' => $dbName]);
    $admin->exec('DROP DATABASE IF EXISTS ' . glfBQuoteId($dbName));
    $admin = null;
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

if (!empty($config['result_file'])) {
    file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
}
exit(0);
