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
    try {
        $pdo->exec("INSERT INTO guest_payment_transactions (id, property_id, payment_number, reservation_id, guest_id, currency, amount, tender_type, cashier_session_id, lifecycle_status, recording_idempotency_key, recorded_at, recorded_by, source_snapshot, created_at, updated_at, created_by) VALUES ('" . (string) \Illuminate\Support\Str::ulid() . "', '" . (string) \Illuminate\Support\Str::ulid() . "', 'BAD', '" . (string) \Illuminate\Support\Str::ulid() . "', '" . (string) \Illuminate\Support\Str::ulid() . "', 'USD', 1, 'CASH', '" . (string) \Illuminate\Support\Str::ulid() . "', 'RECORDED', 'bad', now(), '" . (string) \Illuminate\Support\Str::ulid() . "', '{}', now(), now(), '" . (string) \Illuminate\Support\Str::ulid() . "')");
        $result['up_same_property_fk_enforced'] = false;
    } catch (PDOException) {
        $result['up_same_property_fk_enforced'] = true;
    }
    try {
        $pdo->exec("INSERT INTO folio_items (id, property_id, folio_id, item_type, description, quantity, amount, is_void, posted_at, source_domain, source_type, source_id, guest_payment_allocation_id, created_at, updated_at) VALUES ('" . (string) \Illuminate\Support\Str::ulid() . "', (SELECT property_id FROM folios LIMIT 1), (SELECT id FROM folios LIMIT 1), 'payment', 'bad source', 1, -1, false, now(), 'pms_cashiering', 'guest_payment_allocation', '" . (string) \Illuminate\Support\Str::ulid() . "', '" . (string) \Illuminate\Support\Str::ulid() . "', now(), now())");
        $result['up_typed_source_fk_enforced'] = false;
    } catch (PDOException) {
        $result['up_typed_source_fk_enforced'] = true;
    }

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
