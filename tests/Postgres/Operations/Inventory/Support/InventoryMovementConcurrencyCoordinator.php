<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || !file_exists($configPath)) { fwrite(STDERR, "CONFIG_MISSING\n"); exit(1); }

$config = json_decode(file_get_contents($configPath), true);
if (!$config || empty($config['db_name']) || empty($config['barrier_dir'])) { fwrite(STDERR, "CONFIG_INVALID\n"); exit(1); }

$dbName = $config['db_name'];
$barrierDir = $config['barrier_dir'];
$dbHost = $config['db_host'] ?? '127.0.0.1';
$dbPort = $config['db_port'] ?? '5432';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';
$basePath = $config['base_path'];

if (!preg_match('/^ivorq_concurrency_[a-z0-9_\-]+$/', $dbName)) { fwrite(STDERR, "GUARD_DB_NAME\n"); exit(1); }
if (in_array($dbName, ['postgres','template0','template1','ivorq_testing'])) { fwrite(STDERR, "GUARD_FORBIDDEN\n"); exit(1); }

function openAdminPDO(string $host, string $port, string $user, string $pass): PDO {
    return new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function quoteId(string $name): string {
    $clean = preg_replace('/[^a-z0-9_\-]/', '', $name);
    return '"' . $clean . '"';
}

function terminateConns(PDO $admin, string $db): int {
    $stmt = $admin->prepare("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()");
    $stmt->execute(['db' => $db]);
    return $stmt->rowCount();
}

function activeConns(PDO $admin, string $db): int {
    $stmt = $admin->prepare("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = :db");
    $stmt->execute(['db' => $db]);
    return (int) $stmt->fetchColumn();
}

$result = ['db_name' => $dbName, 'db_created' => false, 'db_dropped' => false,
    'migrations_ok' => false, 'issue_concurrency' => [], 'error' => null, 'drop_error' => null];

try {
    // PHASE 1: Provision
    $admin = openAdminPDO($dbHost, $dbPort, $dbUser, $dbPass);
    $admin->exec('DROP DATABASE IF EXISTS ' . quoteId($dbName));
    $admin->exec('CREATE DATABASE ' . quoteId($dbName));
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

    // PHASE 2: Fixture
    $companyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'IC Company', 'slug' => 'ic-company-' . \Illuminate\Support\Str::random(4),
        'created_at' => now(), 'updated_at' => now()
    ]);

    $propertyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'IC Prop',
        'slug' => 'ic-prop-' . \Illuminate\Support\Str::random(4), 'code' => 'IC' . \Illuminate\Support\Str::random(2),
        'created_at' => now(), 'updated_at' => now()
    ]);

    $actorId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $actorId, 'name' => 'IC Actor', 'email' => 'ic-actor-' . \Illuminate\Support\Str::random(4) . '@test.local',
        'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()
    ]);

    $catId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_categories')->insert([
        'id' => $catId, 'property_id' => $propertyId, 'name' => 'IC Cat',
        'created_at' => now(), 'updated_at' => now()
    ]);

    $itemId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_items')->insert([
        'id' => $itemId, 'property_id' => $propertyId, 'category_id' => $catId,
        'sku' => 'IC-ITM', 'name' => 'IC Item', 'inventory_type' => 'goods',
        'weighted_average_cost' => 0, 'is_active' => true, 'criticality' => 'low',
        'reorder_point' => 0, 'is_batch_tracked' => false, 'is_expiry_tracked' => false,
        'created_at' => now(), 'updated_at' => now()
    ]);

    $locId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_locations')->insert([
        'id' => $locId, 'property_id' => $propertyId, 'name' => 'IC Loc', 'type' => 'internal',
        'created_at' => now(), 'updated_at' => now()
    ]);

    $unitId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_units')->insert([
        'id' => $unitId, 'property_id' => $propertyId, 'code' => 'IC-PCE', 'name' => 'IC Unit',
        'created_at' => now(), 'updated_at' => now()
    ]);

    // Seed initial controlled quantity: GOODS_RECEIPT / IN of 10
    $postingService = app(\Modules\Operations\Inventory\Services\InventoryLedgerPostingService::class);
    $postingService->post([
        'property_id' => $propertyId,
        'inventory_item_id' => $itemId,
        'inventory_location_id' => $locId,
        'inventory_unit_id' => $unitId,
        'movement_type' => \Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum::GoodsReceipt,
        'direction' => \Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum::In,
        'source_leg' => \Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum::Primary,
        'quantity' => 10.000,
        'source_domain' => 'purchasing',
        'source_type' => 'GoodsReceiptLine',
        'source_id' => (string) \Illuminate\Support\Str::ulid(),
        'correlation_id' => (string) \Illuminate\Support\Str::ulid(),
        'idempotency_key' => (string) \Illuminate\Support\Str::ulid(),
        'occurred_at' => \Illuminate\Support\Carbon::now(),
        'created_by' => $actorId,
    ]);

    $fixture = [
        'property_id' => $propertyId,
        'company_id' => $companyId,
        'inventory_item_id' => $itemId,
        'inventory_location_id' => $locId,
        'inventory_unit_id' => $unitId,
        'actor_id' => $actorId,
    ];

    // PHASE 3: Concurrency proof — two workers each try to issue 6 from 10
    $result['issue_concurrency'] = runScenario($config, $dbName, $barrierDir, $fixture);

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

// PHASE 4: Shutdown
try { \Illuminate\Support\Facades\DB::disconnect(); } catch (\Throwable $e) {}
try { \Illuminate\Support\Facades\DB::purge('pgsql'); } catch (\Throwable $e) {}

// PHASE 5: Cleanup
try {
    $admin2 = openAdminPDO($dbHost, $dbPort, $dbUser, $dbPass);
    terminateConns($admin2, $dbName);
    for ($i = 0; $i < 10; $i++) {
        if (activeConns($admin2, $dbName) === 0) break;
        usleep(200000);
    }
    $admin2->exec('DROP DATABASE IF EXISTS ' . quoteId($dbName));
    $admin2 = null;
    $result['db_dropped'] = true;
} catch (\Throwable $e) {
    $result['drop_error'] = $e->getMessage();
}

$resultFile = $config['result_file'] ?? null;
if ($resultFile) { file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT)); }
exit(0);

// ── runScenario ──────────────────────────────────────────────────────────
function runScenario(array $cfg, string $dbName, string $barrierDir, array $f): array {
    $sourceIdA = (string) \Illuminate\Support\Str::ulid();
    $sourceIdB = (string) \Illuminate\Support\Str::ulid();
    $correlationIdA = (string) \Illuminate\Support\Str::ulid();
    $correlationIdB = (string) \Illuminate\Support\Str::ulid();
    $ikA = (string) \Illuminate\Support\Str::ulid();
    $ikB = (string) \Illuminate\Support\Str::ulid();

    $reqA = [
        'property_id' => $f['property_id'],
        'company_id' => $f['company_id'],
        'inventory_item_id' => $f['inventory_item_id'],
        'inventory_location_id' => $f['inventory_location_id'],
        'inventory_unit_id' => $f['inventory_unit_id'],
        'actor_id' => $f['actor_id'],
        'quantity' => 6.000,
        'source_domain' => 'inventory',
        'source_type' => 'InventoryIssueLine',
        'source_id' => $sourceIdA,
        'correlation_id' => $correlationIdA,
        'idempotency_key' => $ikA,
    ];
    $reqB = [
        'property_id' => $f['property_id'],
        'company_id' => $f['company_id'],
        'inventory_item_id' => $f['inventory_item_id'],
        'inventory_location_id' => $f['inventory_location_id'],
        'inventory_unit_id' => $f['inventory_unit_id'],
        'actor_id' => $f['actor_id'],
        'quantity' => 6.000,
        'source_domain' => 'inventory',
        'source_type' => 'InventoryIssueLine',
        'source_id' => $sourceIdB,
        'correlation_id' => $correlationIdB,
        'idempotency_key' => $ikB,
    ];

    $cfgA = wCfg('A', $cfg, $dbName, $barrierDir, $reqA);
    $cfgB = wCfg('B', $cfg, $dbName, $barrierDir, $reqB);
    file_put_contents($barrierDir . '/cfg-A.json', json_encode($cfgA));
    file_put_contents($barrierDir . '/cfg-B.json', json_encode($cfgB));

    $workerScript = $cfg['base_path'] . '/tests/Postgres/Operations/Inventory/Support/InventoryMovementConcurrencyWorker.php';
    $dspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($barrierDir . '/cfg-A.json'), $dspec, $pA, $cfg['base_path']);
    $procB = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($barrierDir . '/cfg-B.json'), $dspec, $pB, $cfg['base_path']);
    if (!is_resource($procA) || !is_resource($procB)) return ['outcome' => 'PROC_FAIL'];
    @fclose($pA[0]); @fclose($pA[1]); @fclose($pB[0]); @fclose($pB[1]);

    wfs($barrierDir, 'ready-A', 60);
    wfs($barrierDir, 'ready-B', 60);

    $db = new PDO("pgsql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$dbName}", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->beginTransaction();
    $db->exec("SELECT id FROM inventory_items WHERE id = '" . str_replace("'", "''", $f['inventory_item_id']) . "' FOR UPDATE");

    touch($barrierDir . '/start.signal');

    wfs($barrierDir, 'locking-A', 60);
    wfs($barrierDir, 'locking-B', 60);
    usleep(200000);
    $db->commit();
    $db = null;

    wfs($barrierDir, 'posted-A', 60, true);
    wfs($barrierDir, 'posted-B', 60, true);

    @proc_close($procA);
    @proc_close($procB);

    $rA = rw($barrierDir, 'A');
    $rB = rw($barrierDir, 'B');
    return [
        'worker_a' => $rA, 'worker_b' => $rB,
        'pid_different' => ($rA['pid']??0) !== ($rB['pid']??-1),
        'pg_different' => ($rA['pg_backend_pid']??0) !== ($rB['pg_backend_pid']??-1),
        'outcomes' => [$rA['outcome']??'?', $rB['outcome']??'?'],
    ];
}

function wCfg(string $id, array $c, string $db, string $dir, array $req): array {
    return [
        'worker_id' => $id, 'barrier_dir' => $dir,
        'result_file' => $dir . "/result-{$id}.json",
        'request' => $req,
        'db_name' => $db, 'db_host' => $c['db_host'], 'db_port' => $c['db_port'],
        'db_user' => $c['db_user'], 'db_pass' => $c['db_pass'],
        'base_path' => $c['base_path']
    ];
}

function wfs(string $dir, string $name, int $timeout, bool $optional = false): void {
    $path = $dir . '/' . $name;
    $end = time() + max($timeout, 5);
    while (time() < $end) { if (file_exists($path)) return; usleep(20000); }
    if (!$optional) throw new \RuntimeException("Timeout: {$name}");
}

function rw(string $dir, string $id): array {
    $p = $dir . "/result-{$id}.json";
    if (!file_exists($p)) return ['outcome' => 'NO_RESULT', 'pid' => -1, 'pg_backend_pid' => -1];
    return json_decode(file_get_contents($p), true) ?: ['outcome' => 'PARSE_ERROR'];
}
