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

$result = ['db_name' => $dbName, 'db_created' => false, 'db_dropped' => false, 'migrations_ok' => false,
    'over_receipt' => [], 'duplicate' => [], 'error' => null, 'drop_error' => null];

try {
    // ── PHASE 1: Provision ──────────────────────────────────────────────
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

    // ── PHASE 2: Fixture ────────────────────────────────────────────────
    $companyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert(['id' => $companyId, 'name' => 'C Company', 'slug' => 'c-company-' . \Illuminate\Support\Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('properties')->insert(['id' => $propertyId, 'company_id' => $companyId, 'name' => 'C Prop', 'slug' => 'c-prop-' . \Illuminate\Support\Str::random(4), 'code' => 'CP' . \Illuminate\Support\Str::random(2), 'created_at' => now(), 'updated_at' => now()]);

    \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $config['user_id'], 'name' => 'C User', 'email' => 'c-user-' . \Illuminate\Support\Str::random(4) . '@test.local', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $config['approver_id'], 'name' => 'C Approver', 'email' => 'c-approver-' . \Illuminate\Support\Str::random(4) . '@test.local', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $config['receiver_id'], 'name' => 'C Receiver', 'email' => 'c-receiver-' . \Illuminate\Support\Str::random(4) . '@test.local', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()]);

    foreach (['inventory.purchasing.goods-receipt.receive','inventory.ledger.view'] as $p) {
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    $receiver = \Modules\Foundation\User\Models\User::findOrFail($config['receiver_id']);
    $receiver->givePermissionTo('inventory.purchasing.goods-receipt.receive');

    $catId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_categories')->insert(['id' => $catId, 'property_id' => $propertyId, 'name' => 'C Cat', 'created_at' => now(), 'updated_at' => now()]);
    $itemId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_items')->insert(['id' => $itemId, 'property_id' => $propertyId, 'category_id' => $catId, 'sku' => 'C-ITM', 'name' => 'C Item', 'inventory_type' => 'goods', 'weighted_average_cost' => 0, 'is_active' => true, 'criticality' => 'low', 'reorder_point' => 0, 'is_batch_tracked' => false, 'is_expiry_tracked' => false, 'created_at' => now(), 'updated_at' => now()]);
    $locId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_locations')->insert(['id' => $locId, 'property_id' => $propertyId, 'name' => 'C Loc', 'type' => 'internal', 'created_at' => now(), 'updated_at' => now()]);
    $unitId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_units')->insert(['id' => $unitId, 'property_id' => $propertyId, 'code' => 'C-PCE', 'name' => 'C Unit', 'created_at' => now(), 'updated_at' => now()]);

    $vcId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('vendor_categories')->insert(['id' => $vcId, 'property_id' => $propertyId, 'category_code' => 'VC-C', 'name' => 'C VC', 'created_at' => now(), 'updated_at' => now()]);
    $vendorId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('vendors')->insert(['id' => $vendorId, 'property_id' => $propertyId, 'vendor_code' => 'V-C', 'name' => 'C Vendor', 'vendor_category_id' => $vcId, 'company_id' => $companyId, 'is_active' => true, 'is_approved' => true, 'created_at' => now(), 'updated_at' => now()]);
    $deptId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('departments')->insert(['id' => $deptId, 'property_id' => $propertyId, 'name' => 'C Dept', 'code' => 'CD' . \Illuminate\Support\Str::random(4), 'created_at' => now(), 'updated_at' => now()]);

    $prId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_requests')->insert(['id' => $prId, 'property_id' => $propertyId, 'request_no' => 'PR-C-' . \Illuminate\Support\Str::random(4), 'department_id' => $deptId, 'requester_id' => $config['user_id'], 'status' => 'APPROVED', 'estimated_total' => 100.00, 'required_date' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\DB::table('purchase_request_lines')->insert(['id' => (string) \Illuminate\Support\Str::ulid(), 'purchase_request_id' => $prId, 'inventory_item_id' => $itemId, 'description' => 'C Line', 'quantity' => 10.000, 'unit_id' => $unitId, 'estimated_unit_cost' => 10.00, 'estimated_total_cost' => 100.00, 'created_at' => now(), 'updated_at' => now()]);

    $poId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_orders')->insert(['id' => $poId, 'property_id' => $propertyId, 'po_no' => 'PO-C-' . \Illuminate\Support\Str::random(8), 'purchase_request_id' => $prId, 'vendor_id' => $vendorId, 'issue_date' => now(), 'expected_delivery_date' => now()->addDays(14), 'status' => 'APPROVED', 'created_by' => $config['user_id'], 'approved_by' => $config['approver_id'], 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $poLineId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_order_lines')->insert(['id' => $poLineId, 'purchase_order_id' => $poId, 'inventory_item_id' => $itemId, 'description' => 'C PO Line', 'unit_id' => $unitId, 'ordered_quantity' => 10.000, 'received_quantity' => 0, 'unit_cost' => 10.00, 'line_total' => 100.00, 'created_at' => now(), 'updated_at' => now()]);

    $fixture = ['property_id' => $propertyId, 'company_id' => $companyId, 'purchase_order_id' => $poId, 'purchase_order_line_id' => $poLineId, 'inventory_item_id' => $itemId, 'inventory_location_id' => $locId, 'inventory_unit_id' => $unitId, 'actor_id' => $config['receiver_id']];

    // ── PHASE 3: Contention proof ──────────────────────────────────────

    $result['over_receipt'] = runScenario($config, $dbName, $barrierDir, $fixture, 'over', 6.000, 6.000, (string) \Illuminate\Support\Str::ulid(), (string) \Illuminate\Support\Str::ulid());

    \Illuminate\Support\Facades\DB::table('purchase_order_lines')->where('id', $poLineId)->update(['received_quantity' => 0]);
    \Illuminate\Support\Facades\DB::table('purchase_orders')->where('id', $poId)->update(['status' => 'APPROVED']);
    \Illuminate\Support\Facades\DB::table('goods_receipt_lines')->delete();
    \Illuminate\Support\Facades\DB::table('goods_receipts')->delete();
    \Illuminate\Support\Facades\DB::table('inventory_stock_movements')->delete();

    $shared = (string) \Illuminate\Support\Str::ulid();
    $result['duplicate'] = runScenario($config, $dbName, $barrierDir, $fixture, 'dup', 3.000, 3.000, $shared, $shared);

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

// ── PHASE 4: Shutdown ───────────────────────────────────────────────────
try { \Illuminate\Support\Facades\DB::disconnect(); } catch (\Throwable $e) {}
try { \Illuminate\Support\Facades\DB::purge('pgsql'); } catch (\Throwable $e) {}

// ── PHASE 5: Cleanup ────────────────────────────────────────────────────
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

// ── Write result ─────────────────────────────────────────────────────────
$resultFile = $config['result_file'] ?? null;
if ($resultFile) { file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT)); }
exit(0);

// ── runScenario ──────────────────────────────────────────────────────────
function runScenario(array $cfg, string $dbName, string $barrierDir, array $f, string $tag, float $qA, float $qB, string $ikA, string $ikB): array {
    $reqA = buildReq($f, $qA, $ikA);
    $reqB = buildReq($f, $qB, $ikB);
    $cfgA = wCfg('A', $cfg, $dbName, $barrierDir, $reqA);
    $cfgB = wCfg('B', $cfg, $dbName, $barrierDir, $reqB);
    file_put_contents($barrierDir . '/cfg-A.json', json_encode($cfgA));
    file_put_contents($barrierDir . '/cfg-B.json', json_encode($cfgB));

    $workerScript = $cfg['base_path'] . '/tests/Postgres/Operations/Inventory/Support/ControlledGoodsReceiptConcurrencyWorker.php';
    $dspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $procA = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($barrierDir . '/cfg-A.json'), $dspec, $pA, $cfg['base_path']);
    $procB = @proc_open(PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($barrierDir . '/cfg-B.json'), $dspec, $pB, $cfg['base_path']);
    if (!is_resource($procA) || !is_resource($procB)) return ['outcome' => 'PROC_FAIL'];
    @fclose($pA[0]); @fclose($pA[1]); @fclose($pB[0]); @fclose($pB[1]);

    wfs($barrierDir, 'ready-A', 60);
    wfs($barrierDir, 'ready-B', 60);

    $db = new PDO("pgsql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$dbName}", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->beginTransaction();
    $db->exec("SELECT id FROM purchase_order_lines WHERE id = '" . str_replace("'", "''", $f['purchase_order_line_id']) . "' FOR UPDATE");

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
    return ['tag' => $tag, 'worker_a' => $rA, 'worker_b' => $rB,
        'pid_different' => ($rA['pid']??0) !== ($rB['pid']??-1),
        'pg_different' => ($rA['pg_backend_pid']??0) !== ($rB['pg_backend_pid']??-1)];
}

function buildReq(array $f, float $q, string $ik): array {
    return ['purchase_order_id' => $f['purchase_order_id'], 'lines' => [['purchase_order_line_id' => $f['purchase_order_line_id'], 'inventory_location_id' => $f['inventory_location_id'], 'inventory_unit_id' => $f['inventory_unit_id'], 'received_quantity' => $q, 'idempotency_key' => $ik]], 'actor_id' => $f['actor_id'], 'property_id' => $f['property_id'], 'company_id' => $f['company_id']];
}

function wCfg(string $id, array $c, string $db, string $dir, array $req): array {
    return ['worker_id' => $id, 'barrier_dir' => $dir, 'result_file' => $dir . "/result-{$id}.json", 'request' => $req, 'db_name' => $db, 'db_host' => $c['db_host'], 'db_port' => $c['db_port'], 'db_user' => $c['db_user'], 'db_pass' => $c['db_pass'], 'base_path' => $c['base_path']];
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
