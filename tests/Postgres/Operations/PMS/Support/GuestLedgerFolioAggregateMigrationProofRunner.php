<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || ! file_exists($configPath)) {
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
$runId = $config['run_id'];
$basePath = $config['base_path'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';

$dbName = 'ivorq_mig_proof_glf_a_' . $runId;

if ($dbName === 'ivorq_testing') {
    exit(1);
}

function glfAQuoteId(string $name): string
{
    return '"' . preg_replace('/[^a-z0-9_\-]/', '', $name) . '"';
}

function glfAAdminPdo(string $host, string $port, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function glfADbPdo(string $host, string $port, string $dbName, string $user, string $pass): PDO
{
    return new PDO("pgsql:host={$host};port={$port};dbname={$dbName}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

$glfAMigrationPath = 'Modules/Operations/PMS/database/migrations/2026_07_11_000040_harden_folio_aggregate.php';

$result = [
    'db_name' => $dbName,
    'db_created' => false,
    'db_dropped' => false,
    'pre_migration_ok' => false,
    'legacy_folios_inserted' => 0,
    'legacy_items_inserted' => 0,
    'migrate_up_ok' => false,
    'window_backfill_ok' => false,
    'idempotency_backfill_ok' => false,
    'positive_window_check_ok' => false,
    'window_unique_ok' => false,
    'idempotency_unique_ok' => false,
    'composite_fk_ok' => false,
    'folio_count_after_up' => 0,
    'item_count_after_up' => 0,
    'migrate_down_ok' => false,
    'columns_removed_ok' => false,
    'constraints_removed_ok' => false,
    'folio_count_after_down' => 0,
    'item_count_after_down' => 0,
    'migrate_reup_ok' => false,
    'reup_backfill_ok' => false,
    'folio_count_after_reup' => 0,
    'item_count_after_reup' => 0,
    'error' => null,
    'drop_error' => null,
];

try {
    // ── 1. Create disposable database ───────────────────────────────────────
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

    // ── 2. Run ALL migrations, then rollback only GLF-A → pre-GLF-A state ──
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    \Illuminate\Support\Facades\Artisan::call('migrate:rollback', [
        '--path' => $glfAMigrationPath,
        '--force' => true,
    ]);
    $result['pre_migration_ok'] = true;

    // ── 3. Insert deterministic legacy Folio and FolioItem evidence ────────
    $companyId = (string) \Illuminate\Support\Str::ulid();
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    $userId = (string) \Illuminate\Support\Str::ulid();
    $guestId = (string) \Illuminate\Support\Str::ulid();
    $reservationId = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'GLF-A Mig Proof Co', 'slug' => 'glf-a-mig-co-' . \Illuminate\Support\Str::random(6),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'GLF-A Mig Proof Property',
        'slug' => 'glf-a-mig-prop-' . \Illuminate\Support\Str::random(6), 'code' => 'MP' . \Illuminate\Support\Str::random(2),
        'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $userId, 'name' => 'GLF-A Mig Actor',
        'email' => 'glf-a-mig-' . \Illuminate\Support\Str::random(6) . '@example.test',
        'password' => bcrypt('password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('property_user')->insert([
        'user_id' => $userId, 'property_id' => $propertyId,
        'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('guests')->insert([
        'id' => $guestId, 'property_id' => $propertyId, 'guest_code' => 'GST-MIG',
        'full_name' => 'Migration Guest', 'guest_type' => 'individual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('reservations')->insert([
        'id' => $reservationId, 'property_id' => $propertyId, 'reservation_number' => 'RES-MIG',
        'primary_guest_id' => $guestId, 'adults' => 1, 'children' => 0,
        'arrival_date' => '2026-07-10', 'departure_date' => '2026-07-12', 'nights' => 2,
        'reservation_source' => 'direct', 'status' => 'tentative', 'reserved_room_type' => 'standard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Insert 2 legacy folios (pre-GLF-A — no window_number, idempotency_key)
    $folio1Id = (string) \Illuminate\Support\Str::ulid();
    $folio2Id = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('folios')->insert([
        'id' => $folio1Id, 'property_id' => $propertyId,
        'folio_number' => 'FOL-00001', 'reservation_id' => $reservationId,
        'guest_id' => $guestId, 'status' => 'open', 'currency' => 'USD',
        'total_charges' => '100.00', 'total_payments' => '0.00', 'balance' => '100.00',
        'created_at' => '2026-07-10 10:00:00', 'updated_at' => '2026-07-10 10:00:00',
    ]);
    \Illuminate\Support\Facades\DB::table('folios')->insert([
        'id' => $folio2Id, 'property_id' => $propertyId,
        'folio_number' => 'FOL-00002', 'reservation_id' => $reservationId,
        'guest_id' => $guestId, 'status' => 'open', 'currency' => 'USD',
        'total_charges' => '50.00', 'total_payments' => '0.00', 'balance' => '50.00',
        'created_at' => '2026-07-10 11:00:00', 'updated_at' => '2026-07-10 11:00:00',
    ]);
    $result['legacy_folios_inserted'] = 2;

    // Insert 2 legacy folio items
    $item1Id = (string) \Illuminate\Support\Str::ulid();
    $item2Id = (string) \Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\DB::table('folio_items')->insert([
        'id' => $item1Id, 'property_id' => $propertyId, 'folio_id' => $folio1Id,
        'item_type' => 'room_charge', 'description' => 'Legacy Room Charge',
        'quantity' => 1, 'amount' => 100.00, 'is_void' => false,
        'posted_at' => '2026-07-10 10:30:00', 'posted_by' => $userId,
        'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('folio_items')->insert([
        'id' => $item2Id, 'property_id' => $propertyId, 'folio_id' => $folio2Id,
        'item_type' => 'tax', 'description' => 'Legacy Tax',
        'quantity' => 1, 'amount' => 50.00, 'is_void' => false,
        'posted_at' => '2026-07-10 11:30:00', 'posted_by' => $userId,
        'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $result['legacy_items_inserted'] = 2;

    $legacyFolioIds = [$folio1Id, $folio2Id];
    $legacyItemIds = [$item1Id, $item2Id];

    // ── 4. Apply GLF-A migration ───────────────────────────────────────────
    \Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => $glfAMigrationPath,
        '--force' => true,
    ]);
    $result['migrate_up_ok'] = true;

    $pdo = glfADbPdo($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

    // Verify window backfill
    $stmt = $pdo->prepare('SELECT id, window_number FROM folios ORDER BY id');
    $stmt->execute();
    $folioMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $folioMap[$f['id']] = (int) $f['window_number'];
    }
    $result['window_backfill_ok'] = ($folioMap[$folio1Id] ?? 0) === 1
        && ($folioMap[$folio2Id] ?? 0) === 2;

    // Verify idempotency backfill
    $stmt = $pdo->prepare('SELECT id, opening_idempotency_key FROM folios ORDER BY id');
    $stmt->execute();
    $idemMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $idemMap[$f['id']] = $f['opening_idempotency_key'];
    }
    $result['idempotency_backfill_ok'] = ($idemMap[$folio1Id] ?? '') === ('legacy-' . $folio1Id)
        && ($idemMap[$folio2Id] ?? '') === ('legacy-' . $folio2Id);

    // Verify positive window check
    try {
        $pdo->exec("UPDATE folios SET window_number = 0 WHERE id = '{$folio2Id}'");
        $result['positive_window_check_ok'] = false;
    } catch (PDOException $e) {
        $result['positive_window_check_ok'] = str_contains($e->getMessage(), 'window_number_positive_check');
    }

    // Verify window uniqueness
    try {
        $pdo->exec("UPDATE folios SET window_number = 1 WHERE id = '{$folio2Id}'");
        $result['window_unique_ok'] = false;
    } catch (PDOException $e) {
        $result['window_unique_ok'] = str_contains($e->getMessage(), 'folios_property_reservation_window_unique');
    }

    // Verify idempotency uniqueness
    try {
        $pdo->exec("UPDATE folios SET opening_idempotency_key = 'legacy-{$folio1Id}' WHERE id = '{$folio2Id}'");
        $result['idempotency_unique_ok'] = false;
    } catch (PDOException $e) {
        $result['idempotency_unique_ok'] = str_contains($e->getMessage(), 'folios_property_idempotency_key_unique');
    }

    // Verify composite FK exists in schema
    $fkExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM pg_constraint WHERE conname = 'folio_items_property_folio_foreign'"
    )->fetchColumn();
    $result['composite_fk_schema_exists'] = $fkExists > 0;

    // Verify composite FK enforcement
    $otherPropId = (string) \Illuminate\Support\Str::ulid();
    try {
        $pdo->exec("INSERT INTO folio_items (id, property_id, folio_id, item_type, description, quantity, amount, posted_at, created_at, updated_at) VALUES ('" . (string) \Illuminate\Support\Str::ulid() . "', '{$otherPropId}', '{$folio1Id}', 'room_charge', 'FK test', 1, 10, now(), now(), now())");
        $result['composite_fk_ok'] = false;
        $result['composite_fk_error'] = 'INSERT succeeded unexpectedly';
    } catch (PDOException $e) {
        $result['composite_fk_ok'] = str_contains($e->getMessage(), 'folio_items_property_folio_foreign')
            || str_contains($e->getMessage(), 'violates foreign key');
        $result['composite_fk_error'] = substr($e->getMessage(), 0, 200);
    }

    $result['folio_count_after_up'] = (int) $pdo->query('SELECT COUNT(*) FROM folios')->fetchColumn();
    $result['item_count_after_up'] = (int) $pdo->query('SELECT COUNT(*) FROM folio_items')->fetchColumn();

    // ── 5. Roll back only GLF-A ────────────────────────────────────────────
    \Illuminate\Support\Facades\Artisan::call('migrate:rollback', [
        '--path' => $glfAMigrationPath,
        '--force' => true,
    ]);
    $result['migrate_down_ok'] = true;

    // Verify columns removed
    $folioCols = [];
    foreach ($pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'folios' ORDER BY ordinal_position") as $row) {
        $folioCols[] = $row['column_name'];
    }
    $result['columns_removed_ok'] = ! in_array('window_number', $folioCols)
        && ! in_array('opening_idempotency_key', $folioCols);

    // Verify constraints removed
    $constraints = $pdo->query("SELECT conname FROM pg_constraint WHERE conrelid = 'folios'::regclass")->fetchAll(PDO::FETCH_COLUMN);
    $glfAConstraintNames = ['folios_property_id_id_unique', 'folios_window_number_positive_check',
        'folios_property_reservation_window_unique', 'folios_property_idempotency_key_unique',
        'folios_reservation_window_index'];
    $constraintsRemoved = true;
    foreach ($glfAConstraintNames as $cn) {
        if (in_array($cn, $constraints)) {
            $constraintsRemoved = false;
            break;
        }
    }
    $itemConstraints = $pdo->query("SELECT conname FROM pg_constraint WHERE conrelid = 'folio_items'::regclass")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('folio_items_property_folio_foreign', $itemConstraints)) {
        $constraintsRemoved = false;
    }
    $result['constraints_removed_ok'] = $constraintsRemoved;

    // Verify data preserved
    $result['folio_count_after_down'] = (int) $pdo->query('SELECT COUNT(*) FROM folios')->fetchColumn();
    $result['item_count_after_down'] = (int) $pdo->query('SELECT COUNT(*) FROM folio_items')->fetchColumn();

    foreach ($legacyFolioIds as $lid) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM folios WHERE id = :id');
        $stmt->execute(['id' => $lid]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException("Legacy folio {$lid} missing after DOWN");
        }
    }
    foreach ($legacyItemIds as $lid) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM folio_items WHERE id = :id');
        $stmt->execute(['id' => $lid]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException("Legacy item {$lid} missing after DOWN");
        }
    }

    // ── 6. Reapply GLF-A ───────────────────────────────────────────────────
    \Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => $glfAMigrationPath,
        '--force' => true,
    ]);
    $result['migrate_reup_ok'] = true;

    // Verify backfill again
    $stmt = $pdo->prepare('SELECT id, window_number FROM folios ORDER BY id');
    $stmt->execute();
    $reupMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $reupMap[$f['id']] = (int) $f['window_number'];
    }
    $result['reup_backfill_ok'] = ($reupMap[$folio1Id] ?? 0) === 1
        && ($reupMap[$folio2Id] ?? 0) === 2;

    foreach ($legacyFolioIds as $lid) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM folios WHERE id = :id');
        $stmt->execute(['id' => $lid]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException("Legacy folio {$lid} missing after REUP");
        }
    }
    foreach ($legacyItemIds as $lid) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM folio_items WHERE id = :id');
        $stmt->execute(['id' => $lid]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException("Legacy item {$lid} missing after REUP");
        }
    }

    $result['folio_count_after_reup'] = (int) $pdo->query('SELECT COUNT(*) FROM folios')->fetchColumn();
    $result['item_count_after_reup'] = (int) $pdo->query('SELECT COUNT(*) FROM folio_items')->fetchColumn();

    $pdo = null;

} catch (Throwable $exception) {
    $result['error'] = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
}

// ── 7. Drop disposable database ────────────────────────────────────────────
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
