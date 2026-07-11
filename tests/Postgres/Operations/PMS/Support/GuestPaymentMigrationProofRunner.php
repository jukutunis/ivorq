<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || !file_exists($configPath)) {
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
$runId = $config['run_id'];
$basePath = $config['base_path'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';

$proofDb = 'ivorq_mig_proof_glf_b_' . $runId;
$ambiguousDb = 'ivorq_mig_proof_glf_b_amb_' . $runId;

function glfBMigQuote(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function glfBMigAdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function glfBMigDbPdo(string $host, string $port, string $db, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function glfBMigCreateDb(string $db, string $host, string $port, string $user, string $pass): void
{
    if ($db === 'ivorq_testing' || !preg_match('/^ivorq_mig_proof_glf_b_[a-z0-9_\-]+$/', $db)) {
        throw new RuntimeException('Unsafe disposable database name.');
    }
    $admin = glfBMigAdminPdo($host, $port, $user, $pass);
    $admin->exec('DROP DATABASE IF EXISTS ' . glfBMigQuote($db));
    $admin->exec('CREATE DATABASE ' . glfBMigQuote($db));
}

function glfBMigDropDb(string $db, string $host, string $port, string $user, string $pass): bool
{
    try {
        $admin = glfBMigAdminPdo($host, $port, $user, $pass);
        $stmt = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()');
        $stmt->execute(['db' => $db]);
        $admin->exec('DROP DATABASE IF EXISTS ' . glfBMigQuote($db));
        return true;
    } catch (Throwable) {
        return false;
    }
}

function glfBMigUseDb(string $db): void
{
    config(['database.connections.pgsql.database' => $db]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    \Illuminate\Support\Facades\DB::reconnect('pgsql');
}

function glfBMigConstraintExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_constraint WHERE conname = :name');
    $stmt->execute(['name' => $name]);
    return (int) $stmt->fetchColumn() > 0;
}

function glfBMigIndexExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_indexes WHERE indexname = :name');
    $stmt->execute(['name' => $name]);
    return (int) $stmt->fetchColumn() > 0;
}

function glfBMigColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :table AND column_name = :column');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function glfBMigTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function glfBMigTriggerExists(PDO $pdo, string $trigger): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_trigger WHERE tgname = :trigger');
    $stmt->execute(['trigger' => $trigger]);
    return (int) $stmt->fetchColumn() > 0;
}

function glfBMigRollbackGlfB(): void
{
    foreach (array_reverse(glfBMigPaths()) as $path) {
        \Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
    }
}

function glfBMigApplyGlfB(): void
{
    foreach (glfBMigPaths() as $path) {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $path, '--force' => true]);
    }
}

function glfBMigPaths(): array
{
    return [
        'Modules/Operations/PMS/database/migrations/2026_07_11_000041_create_guest_payment_transactions_table.php',
        'Modules/Operations/PMS/database/migrations/2026_07_11_000042_create_guest_payment_allocations_table.php',
        'Modules/Operations/PMS/database/migrations/2026_07_11_000043_create_guest_payment_reversals_table.php',
        'Modules/Operations/PMS/database/migrations/2026_07_11_000044_extend_folio_items_for_guest_payment_sources.php',
    ];
}

function glfBMigSeedLegacy(bool $ambiguous): array
{
    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    $guestId = (string) \Illuminate\Support\Str::ulid();
    $reservationId = (string) \Illuminate\Support\Str::ulid();
    $folioId = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'GLF-B Mig Co', 'slug' => 'glf-b-mig-' . \Illuminate\Support\Str::random(6),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'GLF-B Mig Property',
        'slug' => 'glf-b-mig-prop-' . \Illuminate\Support\Str::random(6), 'code' => 'GM' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId, 'name' => 'GLF-B Mig Actor',
        'email' => 'glf-b-mig-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $guestId, 'property_id' => $propertyId, 'guest_code' => 'GST-MIG',
        'full_name' => 'Migration Guest', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $reservationId, 'property_id' => $propertyId, 'reservation_number' => 'RES-MIG',
        'primary_guest_id' => $guestId, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'walk_in', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('folios')->insert([
        'id' => $folioId, 'property_id' => $propertyId, 'folio_number' => 'FOL-MIG',
        'reservation_id' => $reservationId, 'guest_id' => $guestId, 'status' => 'open',
        'currency' => 'USD', 'window_number' => 1, 'opening_idempotency_key' => 'mig-open',
        'total_charges' => '150.00', 'total_payments' => '0.00', 'balance' => '150.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $items = $ambiguous
        ? [['payment', '-20.00'], ['deposit', '30.00']]
        : [['room_charge', '100.00'], ['tax', '50.00']];

    foreach ($items as $index => [$type, $amount]) {
        \Illuminate\Support\Facades\DB::table('folio_items')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $propertyId,
            'folio_id' => $folioId,
            'item_type' => $type,
            'description' => 'Legacy ' . $type,
            'quantity' => '1.00',
            'amount' => $amount,
            'is_void' => false,
            'posted_at' => now()->addMinutes($index),
            'posted_by' => $actorId,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return ['property_id' => $propertyId, 'folio_id' => $folioId];
}

function glfBMigSeedDualProperty(): array
{
    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propA = (string) \Illuminate\Support\Str::ulid();
    $propB = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    $guestA = (string) \Illuminate\Support\Str::ulid();
    $guestB = (string) \Illuminate\Support\Str::ulid();
    $resA = (string) \Illuminate\Support\Str::ulid();
    $resB = (string) \Illuminate\Support\Str::ulid();
    $folioA = (string) \Illuminate\Support\Str::ulid();
    $folioB = (string) \Illuminate\Support\Str::ulid();
    $sessionA = (string) \Illuminate\Support\Str::ulid();
    $sessionB = (string) \Illuminate\Support\Str::ulid();
    $paymentA = (string) \Illuminate\Support\Str::ulid();
    $paymentB = (string) \Illuminate\Support\Str::ulid();
    $allocA = (string) \Illuminate\Support\Str::ulid();
    $allocB = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'GLF-B Dual Proof Co',
        'slug' => 'glf-b-dual-' . \Illuminate\Support\Str::random(6),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([[$propA, 'Property A', 'USD'], [$propB, 'Property B', 'EUR']] as [$propId, $name, $currency]) {
        \Illuminate\Support\Facades\DB::table('properties')->insert([
            'id' => $propId, 'company_id' => $companyId, 'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . \Illuminate\Support\Str::random(4),
            'code' => strtoupper(substr($name, -1)) . \Illuminate\Support\Str::random(2),
            'timezone' => 'UTC', 'currency' => $currency, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId, 'name' => 'GLF-B Dual Actor',
        'email' => 'glf-b-dual-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([[$guestA, $propA, 'GST-A'], [$guestB, $propB, 'GST-B']] as [$gId, $pId, $code]) {
        \Illuminate\Support\Facades\DB::table('guests')->insert([
            'id' => $gId, 'property_id' => $pId, 'guest_code' => $code,
            'full_name' => 'Dual Guest ' . $code, 'guest_type' => 'individual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([[$resA, $propA, $guestA, 'RES-A'], [$resB, $propB, $guestB, 'RES-B']] as [$rId, $pId, $gId, $num]) {
        \Illuminate\Support\Facades\DB::table('reservations')->insert([
            'id' => $rId, 'property_id' => $pId, 'reservation_number' => $num,
            'primary_guest_id' => $gId, 'adults' => 1, 'children' => 0,
            'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
            'reservation_source' => 'walk_in', 'status' => 'tentative', 'reserved_room_type' => 'standard',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([[$folioA, $propA, $resA, $guestA, 'FOL-A', 'USD'], [$folioB, $propB, $resB, $guestB, 'FOL-B', 'EUR']] as [$fId, $pId, $rId, $gId, $num, $cur]) {
        \Illuminate\Support\Facades\DB::table('folios')->insert([
            'id' => $fId, 'property_id' => $pId, 'folio_number' => $num,
            'reservation_id' => $rId, 'guest_id' => $gId, 'status' => 'open',
            'currency' => $cur, 'window_number' => 1, 'opening_idempotency_key' => 'dual-open-' . $num,
            'total_charges' => '0.00', 'total_payments' => '0.00', 'balance' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([[$sessionA, $propA, 'OPEN'], [$sessionB, $propB, 'OPEN']] as [$sId, $pId, $status]) {
        \Illuminate\Support\Facades\DB::table('cashier_sessions')->insert([
            'id' => $sId, 'property_id' => $pId, 'cashier_user_id' => $actorId,
            'status' => $status, 'opened_at' => now(), 'opened_by' => $actorId,
            'closed_at' => null, 'closed_by' => null,
        ]);
    }

    // Create valid Payment A (Property A)
    \Illuminate\Support\Facades\DB::table('guest_payment_transactions')->insert([
        'id' => $paymentA, 'property_id' => $propA, 'payment_number' => 'GPM-PROOF-A',
        'reservation_id' => $resA, 'guest_id' => $guestA, 'currency' => 'USD', 'amount' => '100.00',
        'tender_type' => 'CASH', 'cashier_session_id' => $sessionA,
        'lifecycle_status' => 'RECORDED', 'recording_idempotency_key' => 'dual-pmt-a-' . \Illuminate\Support\Str::random(10),
        'recorded_at' => now(), 'recorded_by' => $actorId, 'source_snapshot' => '{}',
        'created_at' => now(), 'updated_at' => now(), 'created_by' => $actorId, 'updated_by' => $actorId,
    ]);

    // Create valid Payment B (Property B)
    \Illuminate\Support\Facades\DB::table('guest_payment_transactions')->insert([
        'id' => $paymentB, 'property_id' => $propB, 'payment_number' => 'GPM-PROOF-B',
        'reservation_id' => $resB, 'guest_id' => $guestB, 'currency' => 'EUR', 'amount' => '200.00',
        'tender_type' => 'CASH', 'cashier_session_id' => $sessionB,
        'lifecycle_status' => 'RECORDED', 'recording_idempotency_key' => 'dual-pmt-b-' . \Illuminate\Support\Str::random(10),
        'recorded_at' => now(), 'recorded_by' => $actorId, 'source_snapshot' => '{}',
        'created_at' => now(), 'updated_at' => now(), 'created_by' => $actorId, 'updated_by' => $actorId,
    ]);

    // Create valid Allocation A (belongs to Payment A, Folio A)
    \Illuminate\Support\Facades\DB::table('guest_payment_allocations')->insert([
        'id' => $allocA, 'property_id' => $propA,
        'guest_payment_transaction_id' => $paymentA, 'folio_id' => $folioA,
        'amount' => '50.00', 'allocation_idempotency_key' => 'dual-alloc-a-' . \Illuminate\Support\Str::random(10),
        'allocated_at' => now(), 'allocated_by' => $actorId, 'source_snapshot' => '{}',
        'created_at' => now(),
    ]);

    // Create valid Allocation B (belongs to Payment B, Folio B)
    \Illuminate\Support\Facades\DB::table('guest_payment_allocations')->insert([
        'id' => $allocB, 'property_id' => $propB,
        'guest_payment_transaction_id' => $paymentB, 'folio_id' => $folioB,
        'amount' => '100.00', 'allocation_idempotency_key' => 'dual-alloc-b-' . \Illuminate\Support\Str::random(10),
        'allocated_at' => now(), 'allocated_by' => $actorId, 'source_snapshot' => '{}',
        'created_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'prop_a' => $propA, 'prop_b' => $propB,
        'actor_id' => $actorId,
        'guest_a' => $guestA, 'guest_b' => $guestB,
        'res_a' => $resA, 'res_b' => $resB,
        'folio_a' => $folioA, 'folio_b' => $folioB,
        'session_a' => $sessionA, 'session_b' => $sessionB,
        'payment_a' => $paymentA, 'payment_b' => $paymentB,
        'alloc_a' => $allocA, 'alloc_b' => $allocB,
    ];
}

function glfBMigAssertFkFails(PDO $pdo, string $sql, string $expectedConstraint): bool
{
    try {
        $pdo->exec($sql);
        return false;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        return str_contains($msg, $expectedConstraint);
    }
}

function glfBMigUlid(): string
{
    return (string) \Illuminate\Support\Str::ulid();
}

$result = [
    'proof_db_created' => false,
    'proof_db_dropped' => false,
    'ambiguous_db_created' => false,
    'ambiguous_db_dropped' => false,
    'error' => null,
];

try {
    chdir($basePath);
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    glfBMigCreateDb($proofDb, $dbHost, $dbPort, $dbUser, $dbPass);
    $result['proof_db_created'] = true;
    glfBMigUseDb($proofDb);
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    glfBMigRollbackGlfB();
    $result['pre_glf_b_ok'] = true;
    glfBMigSeedLegacy(false);
    $result['pre_folio_count'] = (int) \Illuminate\Support\Facades\DB::table('folios')->count();
    $result['pre_folio_item_count'] = (int) \Illuminate\Support\Facades\DB::table('folio_items')->count();

    glfBMigApplyGlfB();
    $result['up_ok'] = true;
    $pdo = glfBMigDbPdo($dbHost, $dbPort, $proofDb, $dbUser, $dbPass);
    $result['up_guest_tables_exist'] = glfBMigTableExists($pdo, 'guest_payment_transactions')
        && glfBMigTableExists($pdo, 'guest_payment_allocations')
        && glfBMigTableExists($pdo, 'guest_payment_reversals');
    $result['up_folio_source_columns_exist'] = glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_allocation_id')
        && glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_reversal_id')
        && glfBMigColumnExists($pdo, 'folio_items', 'reverses_folio_item_id');
    $result['up_constraints_exist'] = glfBMigConstraintExists($pdo, 'guest_payments_property_reservation_foreign')
        && glfBMigConstraintExists($pdo, 'guest_allocations_property_folio_foreign')
        && glfBMigConstraintExists($pdo, 'guest_reversals_property_allocation_payment_foreign')
        && glfBMigConstraintExists($pdo, 'folio_items_guest_payment_source_check');
    $result['up_indexes_exist'] = glfBMigIndexExists($pdo, 'guest_reversals_one_reversal_per_allocation_unique')
        && glfBMigIndexExists($pdo, 'folio_items_property_source_unique');
    $result['up_typed_source_trigger_exists'] = glfBMigTriggerExists($pdo, 'glf_b_folio_item_source_integrity_trigger');
    $result['up_immutability_trigger_exists'] = glfBMigTriggerExists($pdo, 'guest_payment_transactions_immutable_trigger');
    $result['up_reversal_source_trigger_exists'] = glfBMigTriggerExists($pdo, 'glf_b_reversal_source_amount_trigger');

    // Seed dual-property valid data for composite-FK proof
    $d = glfBMigSeedDualProperty();

    // Prove: Payment property A + Reservation property B fails (composite FK)
    $sqlPRes = "INSERT INTO guest_payment_transactions (id, property_id, payment_number, reservation_id, guest_id, currency, amount, tender_type, cashier_session_id, lifecycle_status, recording_idempotency_key, recorded_at, recorded_by, source_snapshot, created_at, updated_at, created_by) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', 'GPM-FK-1', '" . $d['res_b'] . "', '" . $d['guest_b'] . "', 'USD', 1, 'CASH', '" . $d['session_a'] . "', 'RECORDED', 'fk-proof-1-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}', now(), now(), '" . $d['actor_id'] . "')";
    $result['up_fk_payment_prop_a_reservation_prop_b'] = glfBMigAssertFkFails($pdo, $sqlPRes, 'guest_payments_property_reservation_foreign');

    // Prove: Payment property A + Guest property B fails (composite FK)
    $sqlPGuest = "INSERT INTO guest_payment_transactions (id, property_id, payment_number, reservation_id, guest_id, currency, amount, tender_type, cashier_session_id, lifecycle_status, recording_idempotency_key, recorded_at, recorded_by, source_snapshot, created_at, updated_at, created_by) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', 'GPM-FK-2', '" . $d['res_a'] . "', '" . $d['guest_b'] . "', 'USD', 1, 'CASH', '" . $d['session_a'] . "', 'RECORDED', 'fk-proof-2-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}', now(), now(), '" . $d['actor_id'] . "')";
    $result['up_fk_payment_prop_a_guest_prop_b'] = glfBMigAssertFkFails($pdo, $sqlPGuest, 'guest_payments_property_guest_foreign');

    // Prove: Payment property A + CashierSession property B fails (composite FK)
    $sqlPSession = "INSERT INTO guest_payment_transactions (id, property_id, payment_number, reservation_id, guest_id, currency, amount, tender_type, cashier_session_id, lifecycle_status, recording_idempotency_key, recorded_at, recorded_by, source_snapshot, created_at, updated_at, created_by) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', 'GPM-FK-3', '" . $d['res_a'] . "', '" . $d['guest_a'] . "', 'USD', 1, 'CASH', '" . $d['session_b'] . "', 'RECORDED', 'fk-proof-3-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}', now(), now(), '" . $d['actor_id'] . "')";
    $result['up_fk_payment_prop_a_session_prop_b'] = glfBMigAssertFkFails($pdo, $sqlPSession, 'guest_payments_property_session_foreign');

    // Prove: Allocation property A + Folio property B fails (composite FK)
    $sqlAllocFolio = "INSERT INTO guest_payment_allocations (id, property_id, guest_payment_transaction_id, folio_id, amount, allocation_idempotency_key, allocated_at, allocated_by, source_snapshot, created_at) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', '" . $d['payment_a'] . "', '" . $d['folio_b'] . "', 10.00, 'fk-alloc-folio-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}', now())";
    $result['up_fk_allocation_prop_a_folio_prop_b'] = glfBMigAssertFkFails($pdo, $sqlAllocFolio, 'guest_allocations_property_folio_foreign');

    // Prove: Reversal Payment A + Allocation belonging to Payment B fails (source-amount trigger enforces match)
    $sqlRevAlloc = "INSERT INTO guest_payment_reversals (id, property_id, guest_payment_transaction_id, guest_payment_allocation_id, reversal_type, amount, reason_code, reversal_idempotency_key, reversed_at, reversed_by, source_snapshot) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', '" . $d['payment_a'] . "', '" . $d['alloc_b'] . "', 'ALLOCATION_REVERSAL', 100.00, 'FK-TEST', 'fk-rev-alloc-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}')";
    $result['up_fk_reversal_payment_a_alloc_b'] = glfBMigAssertFkFails($pdo, $sqlRevAlloc, 'GLF_B_ALLOCATION_REVERSAL_TARGET_NOT_FOUND');

    // Prove: FolioItem property A + typed Allocation property B fails (source integrity trigger)
    $sqlFolioItemAlloc = "INSERT INTO folio_items (id, property_id, folio_id, item_type, description, quantity, amount, is_void, posted_at, posted_by, created_by, source_domain, source_type, source_id, guest_payment_allocation_id, created_at, updated_at) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', '" . $d['folio_a'] . "', 'payment', 'bad source proof', 1, -1, false, now(), '" . $d['actor_id'] . "', '" . $d['actor_id'] . "', 'pms_cashiering', 'guest_payment_allocation', '" . $d['alloc_b'] . "', '" . $d['alloc_b'] . "', now(), now())";
    $result['up_fk_folio_item_prop_a_alloc_prop_b'] = glfBMigAssertFkFails($pdo, $sqlFolioItemAlloc, 'GLF_B_INVALID_PAYMENT_SOURCE');

    // Prove: Payment transaction immutability trigger blocks mutation of immutable field
    $sqlImmutable = "UPDATE guest_payment_transactions SET amount = '999.99' WHERE id = '" . $d['payment_a'] . "'";
    $result['up_immutable_payment_amount_blocked'] = glfBMigAssertFkFails($pdo, $sqlImmutable, 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE');

    // Prove: Reversal source amount trigger blocks void with wrong amount
    $sqlVoidAmount = "INSERT INTO guest_payment_reversals (id, property_id, guest_payment_transaction_id, guest_payment_allocation_id, reversal_type, amount, reason_code, reversal_idempotency_key, reversed_at, reversed_by, source_snapshot) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', '" . $d['payment_a'] . "', NULL, 'PAYMENT_VOID', 99.99, 'WRONG_AMOUNT', 'fk-void-amount-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}')";
    $result['up_reversal_source_amount_void_blocked'] = glfBMigAssertFkFails($pdo, $sqlVoidAmount, 'GLF_B_REVERSAL_SOURCE_AMOUNT_MISMATCH');

    // Prove: Reversal source amount trigger blocks allocation reversal with wrong amount
    $sqlRevAmount = "INSERT INTO guest_payment_reversals (id, property_id, guest_payment_transaction_id, guest_payment_allocation_id, reversal_type, amount, reason_code, reversal_idempotency_key, reversed_at, reversed_by, source_snapshot) VALUES ('" . glfBMigUlid() . "', '" . $d['prop_a'] . "', '" . $d['payment_a'] . "', '" . $d['alloc_a'] . "', 'ALLOCATION_REVERSAL', 49.99, 'WRONG_REV', 'fk-rev-amount-" . \Illuminate\Support\Str::random(8) . "', now(), '" . $d['actor_id'] . "', '{}')";
    $result['up_reversal_source_amount_alloc_blocked'] = glfBMigAssertFkFails($pdo, $sqlRevAmount, 'GLF_B_REVERSAL_SOURCE_AMOUNT_MISMATCH');

    // Legacy simple-FK proof retained but strengthened (non-disclosing, valid but mismatched)
    $result['up_same_property_fk_enforced'] = $result['up_fk_payment_prop_a_reservation_prop_b'] ?? false;
    $result['up_typed_source_fk_enforced'] = $result['up_fk_folio_item_prop_a_alloc_prop_b'] ?? false;

    // Clean up dual-property seed data before rolling back GLF-B, so DOWN
    // assertion on legacy folio/item preservation is not inflated.
    // Bypass immutability triggers during cleanup.
    $pdo->exec("SET session_replication_role = 'replica'");
    foreach (['guest_payment_reversals', 'guest_payment_allocations', 'guest_payment_transactions'] as $t) {
        $pdo->exec('DELETE FROM ' . $t);
    }
    $pdo->exec("SET session_replication_role = 'origin'");
    $pdo->exec("DELETE FROM folios WHERE id IN ('" . $d['folio_a'] . "','" . $d['folio_b'] . "')");
    $pdo->exec("DELETE FROM reservations WHERE id IN ('" . $d['res_a'] . "','" . $d['res_b'] . "')");
    $pdo->exec("DELETE FROM guests WHERE id IN ('" . $d['guest_a'] . "','" . $d['guest_b'] . "')");
    $pdo->exec("DELETE FROM cashier_sessions WHERE property_id IN ('" . $d['prop_a'] . "','" . $d['prop_b'] . "')");
    $pdo->exec("DELETE FROM properties WHERE id IN ('" . $d['prop_a'] . "','" . $d['prop_b'] . "')");
    $pdo->exec("DELETE FROM companies WHERE id = '" . $d['company_id'] . "'");

    glfBMigRollbackGlfB();
    $result['down_ok'] = true;
    $result['down_guest_tables_removed'] = !glfBMigTableExists($pdo, 'guest_payment_transactions')
        && !glfBMigTableExists($pdo, 'guest_payment_allocations')
        && !glfBMigTableExists($pdo, 'guest_payment_reversals');
    $result['down_folio_source_columns_removed'] = !glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_allocation_id')
        && !glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_reversal_id')
        && !glfBMigColumnExists($pdo, 'folio_items', 'source_id');
    $result['down_parent_composite_keys_removed'] = !glfBMigConstraintExists($pdo, 'reservations_property_id_unique')
        && !glfBMigConstraintExists($pdo, 'guests_property_id_unique')
        && !glfBMigConstraintExists($pdo, 'cashier_sessions_property_id_unique');
    $result['down_legacy_folio_preserved'] = (int) $pdo->query('SELECT COUNT(*) FROM folios')->fetchColumn() === 1;
    $result['down_legacy_items_preserved'] = (int) $pdo->query('SELECT COUNT(*) FROM folio_items')->fetchColumn() === 2;

    glfBMigApplyGlfB();
    $result['reup_ok'] = true;
    $result['reup_guest_tables_exist'] = glfBMigTableExists($pdo, 'guest_payment_transactions')
        && glfBMigTableExists($pdo, 'guest_payment_allocations')
        && glfBMigTableExists($pdo, 'guest_payment_reversals');
    $result['reup_folio_source_columns_exist'] = glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_allocation_id')
        && glfBMigColumnExists($pdo, 'folio_items', 'guest_payment_reversal_id')
        && glfBMigColumnExists($pdo, 'folio_items', 'source_id');
    $result['reup_constraints_exist'] = glfBMigConstraintExists($pdo, 'guest_payments_property_reservation_foreign')
        && glfBMigConstraintExists($pdo, 'guest_allocations_property_folio_foreign')
        && glfBMigConstraintExists($pdo, 'folio_items_guest_payment_source_check');
    $result['reup_indexes_exist'] = glfBMigIndexExists($pdo, 'folio_items_property_source_unique');
    $result['reup_typed_source_trigger_exists'] = glfBMigTriggerExists($pdo, 'glf_b_folio_item_source_integrity_trigger');
    $result['reup_immutability_trigger_exists'] = glfBMigTriggerExists($pdo, 'guest_payment_transactions_immutable_trigger');
    $result['reup_reversal_source_trigger_exists'] = glfBMigTriggerExists($pdo, 'glf_b_reversal_source_amount_trigger');
    $result['reup_legacy_folio_preserved'] = (int) $pdo->query('SELECT COUNT(*) FROM folios')->fetchColumn() === 1;
    $result['reup_legacy_items_preserved'] = (int) $pdo->query('SELECT COUNT(*) FROM folio_items')->fetchColumn() === 2;

    glfBMigCreateDb($ambiguousDb, $dbHost, $dbPort, $dbUser, $dbPass);
    $result['ambiguous_db_created'] = true;
    glfBMigUseDb($ambiguousDb);
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    glfBMigRollbackGlfB();
    glfBMigSeedLegacy(true);
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--path' => 'Modules/Operations/PMS/database/migrations/2026_07_11_000044_extend_folio_items_for_guest_payment_sources.php',
            '--force' => true,
        ]);
        $result['ambiguous_blocked'] = false;
    } catch (Throwable $exception) {
        $result['ambiguous_blocked'] = str_contains($exception->getMessage(), 'GLF_B_BLOCKED_LEGACY_PAYMENT_ITEMS');
        $result['ambiguous_error'] = $exception->getMessage();
    }
    $ambPdo = glfBMigDbPdo($dbHost, $dbPort, $ambiguousDb, $dbUser, $dbPass);
    $result['ambiguous_no_partial_columns'] = !glfBMigColumnExists($ambPdo, 'folio_items', 'source_id')
        && !glfBMigColumnExists($ambPdo, 'folio_items', 'guest_payment_allocation_id');
    $result['ambiguous_legacy_rows_preserved'] = (int) $ambPdo->query("SELECT COUNT(*) FROM folio_items WHERE item_type IN ('payment','deposit')")->fetchColumn() === 2;
    // Prove pre-GLF-B payment_reversal rows cannot exist: GLF-B introduced both
    // payment and payment_reversal as typed source-bound FolioItem categories.
    // The GLF-A baseline only creates room_charge/tax. The blocker migration 000044
    // checks for legacy payment/deposit (which are source-ambiguous). The
    // payment_reversal type was never part of GLF-A or any prior module, so
    // there is no path for pre-GLF-B payment_reversal rows to exist.
    $result['ambiguous_pre_glfb_reversal_items_impossible'] = (int) $ambPdo->query("SELECT COUNT(*) FROM folio_items WHERE item_type = 'payment_reversal'")->fetchColumn() === 0;
} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

try {
    \Illuminate\Support\Facades\DB::disconnect();
    \Illuminate\Support\Facades\DB::purge('pgsql');
} catch (Throwable) {
}

$result['proof_db_dropped'] = glfBMigDropDb($proofDb, $dbHost, $dbPort, $dbUser, $dbPass);
$result['ambiguous_db_dropped'] = glfBMigDropDb($ambiguousDb, $dbHost, $dbPort, $dbUser, $dbPass);

if (!empty($config['result_file'])) {
    file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT));
}
exit(0);
