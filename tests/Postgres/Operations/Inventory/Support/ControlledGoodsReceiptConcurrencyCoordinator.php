<?php

/**
 * Sprint 37 — Isolated PostgreSQL Concurrency Coordinator
 *
 * Test-only. Runs as a separate PHP process invoked by the PHPUnit test.
 * Provisions a disposable PostgreSQL database, runs two independent worker
 * processes against it, proves receipt concurrency safety, and drops the DB.
 */

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || !file_exists($configPath)) {
    fwrite(STDERR, "COORDINATOR_CONFIG_MISSING\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (!$config || empty($config['db_name']) || empty($config['barrier_dir'])) {
    fwrite(STDERR, "COORDINATOR_CONFIG_INVALID\n");
    exit(1);
}

$dbName = $config['db_name'];
$barrierDir = $config['barrier_dir'];

if (!str_starts_with($dbName, 'ivorq_concurrency_')) {
    fwrite(STDERR, "COORDINATOR_GUARD_DB_NAME\n");
    exit(1);
}

$forbiddenNames = ['postgres', 'template0', 'template1', 'ivorq_testing'];
if (in_array($dbName, $forbiddenNames, true)) {
    fwrite(STDERR, "COORDINATOR_GUARD_FORBIDDEN_DB\n");
    exit(1);
}

$dbUsername = $config['db_user'] ?? '';
$dbPassword = $config['db_pass'] ?? '';

$result = [
    'db_name' => $dbName,
    'db_created' => false,
    'db_dropped' => false,
    'migrations_ok' => false,
    'test_over_receipt' => ['outcome' => 'NOT_RUN'],
    'test_duplicate' => ['outcome' => 'NOT_RUN'],
    'error' => null,
];

try {
    $adminDsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres',
        $config['db_host'] ?? '127.0.0.1', $config['db_port'] ?? '5432');
    $adminDb = new \PDO($adminDsn, $dbUsername, $dbPassword, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
    $adminDb->exec('DROP DATABASE IF EXISTS "' . str_replace('"', '""', $dbName) . '"');
    $adminDb->exec('CREATE DATABASE "' . str_replace('"', '""', $dbName) . '"');
    $adminDb = null;
    $result['db_created'] = true;

    chdir($config['base_path']);

    require $config['base_path'] . '/vendor/autoload.php';
    $app = require_once $config['base_path'] . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    config(['database.connections.pgsql.database' => $dbName]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    \Illuminate\Support\Facades\DB::reconnect('pgsql');

    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $companyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert([
        'id' => $companyId, 'name' => 'Conc Company', 'slug' => 'conc-company-' . \Illuminate\Support\Str::random(4),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $propertyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('properties')->insert([
        'id' => $propertyId, 'company_id' => $companyId, 'name' => 'Conc Property',
        'slug' => 'conc-prop-' . \Illuminate\Support\Str::random(4), 'code' => 'CONC' . \Illuminate\Support\Str::random(2),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $config['user_id'], 'name' => 'Conc User',
        'email' => 'conc-user@test.local', 'password' => bcrypt('password'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $config['approver_id'], 'name' => 'Conc Approver',
        'email' => 'conc-approver@test.local', 'password' => bcrypt('password'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $config['receiver_id'], 'name' => 'Conc Receiver',
        'email' => 'conc-receiver@test.local', 'password' => bcrypt('password'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
        'name' => 'inventory.purchasing.goods-receipt.receive', 'guard_name' => 'web']);
    \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
        'name' => 'inventory.ledger.view', 'guard_name' => 'web']);
    \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
        'name' => 'inventory.reversal.request', 'guard_name' => 'web']);
    \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
        'name' => 'inventory.reversal.execute', 'guard_name' => 'web']);

    $receiver = \Modules\Foundation\User\Models\User::findOrFail($config['receiver_id']);
    $receiver->givePermissionTo('inventory.purchasing.goods-receipt.receive');

    $catId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_categories')->insert([
        'id' => $catId, 'property_id' => $propertyId, 'name' => 'Conc Cat',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_items')->insert([
        'id' => $itemId, 'property_id' => $propertyId, 'category_id' => $catId,
        'sku' => 'CONC-ITM-001', 'name' => 'Conc Item', 'inventory_type' => 'goods',
        'weighted_average_cost' => 0, 'is_active' => true, 'criticality' => 'low',
        'reorder_point' => 0, 'is_batch_tracked' => false, 'is_expiry_tracked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $locationId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_locations')->insert([
        'id' => $locationId, 'property_id' => $propertyId, 'name' => 'Conc Location', 'type' => 'internal',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $unitId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_units')->insert([
        'id' => $unitId, 'property_id' => $propertyId, 'code' => 'CONC-PCE', 'name' => 'Conc Unit',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $vcId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('vendor_categories')->insert([
        'id' => $vcId, 'property_id' => $propertyId, 'category_code' => 'VC-CONC', 'name' => 'Conc Vendor Cat',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $vendorId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('vendors')->insert([
        'id' => $vendorId, 'property_id' => $propertyId, 'vendor_code' => 'V-CONC', 'name' => 'Conc Vendor',
        'vendor_category_id' => $vcId, 'company_id' => $companyId, 'is_active' => true, 'is_approved' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $deptId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('departments')->insert([
        'id' => $deptId, 'property_id' => $propertyId, 'name' => 'Conc Dept', 'code' => 'CONC' . \Illuminate\Support\Str::random(4),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $prId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_requests')->insert([
        'id' => $prId, 'property_id' => $propertyId, 'request_no' => 'PR-CONC-' . \Illuminate\Support\Str::random(4),
        'department_id' => $deptId, 'requester_id' => $config['user_id'], 'status' => 'APPROVED',
        'estimated_total' => 100.00, 'required_date' => now()->addDays(7),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('purchase_request_lines')->insert([
        'id' => (string) \Illuminate\Support\Str::ulid(), 'purchase_request_id' => $prId,
        'inventory_item_id' => $itemId, 'description' => 'Conc Line', 'quantity' => 10.000,
        'unit_id' => $unitId, 'estimated_unit_cost' => 10.00, 'estimated_total_cost' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $poId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_orders')->insert([
        'id' => $poId, 'property_id' => $propertyId, 'po_no' => 'PO-CONC-' . \Illuminate\Support\Str::random(8),
        'purchase_request_id' => $prId, 'vendor_id' => $vendorId, 'issue_date' => now(),
        'expected_delivery_date' => now()->addDays(14), 'status' => 'APPROVED',
        'created_by' => $config['user_id'], 'approved_by' => $config['approver_id'],
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $poLineId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('purchase_order_lines')->insert([
        'id' => $poLineId, 'purchase_order_id' => $poId, 'inventory_item_id' => $itemId,
        'description' => 'Conc PO Line', 'unit_id' => $unitId, 'ordered_quantity' => 10.000,
        'received_quantity' => 0, 'unit_cost' => 10.00, 'line_total' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fixture = [
        'property_id' => $propertyId, 'company_id' => $companyId,
        'purchase_order_id' => $poId, 'purchase_order_line_id' => $poLineId,
        'inventory_item_id' => $itemId, 'inventory_location_id' => $locationId,
        'inventory_unit_id' => $unitId, 'actor_id' => $config['receiver_id'],
        'approver_id' => $config['approver_id'],
    ];

    $result['over_receipt'] = runTest(
        $config, $fixture, $barrierDir, $dbName,
        'over-receipt', 6.000, 6.000,
        (string) \Illuminate\Support\Str::ulid(), (string) \Illuminate\Support\Str::ulid()
    );

    \Illuminate\Support\Facades\DB::table('purchase_order_lines')->where('id', $poLineId)->update(['received_quantity' => 0]);
    \Illuminate\Support\Facades\DB::table('purchase_orders')->where('id', $poId)->update(['status' => 'APPROVED']);
    \Illuminate\Support\Facades\DB::table('goods_receipt_lines')->truncate();
    \Illuminate\Support\Facades\DB::table('goods_receipts')->truncate();
    \Illuminate\Support\Facades\DB::table('inventory_stock_movements')->truncate();

    $sharedIdemKey = (string) \Illuminate\Support\Str::ulid();
    $result['duplicate'] = runTest(
        $config, $fixture, $barrierDir, $dbName,
        'duplicate', 3.000, 3.000,
        $sharedIdemKey, $sharedIdemKey
    );

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . "\n" . $e->getTraceAsString();
}

$resultFile = $config['result_file'] ?? null;
if ($resultFile) {
    file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
}

try {
    \Illuminate\Support\Facades\DB::disconnect();
    \Illuminate\Support\Facades\DB::purge('pgsql');

    $adminDsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres',
        $config['db_host'] ?? '127.0.0.1', $config['db_port'] ?? '5432');
    $adminDb2 = new \PDO($adminDsn, $dbUsername, $dbPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $adminDb2->exec('SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = \'' . str_replace("'", "''", $dbName) . '\' AND pid <> pg_backend_pid()');
    $adminDb2->exec('DROP DATABASE IF EXISTS "' . str_replace('"', '""', $dbName) . '"');
    $adminDb2 = null;
    $result['db_dropped'] = true;
} catch (\Throwable $e) {
    $result['drop_exception'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
exit(0);

// ── Internal helpers ────────────────────────────────────────────────────────

function runTest(
    array $config, array $fixture, string $barrierDir, string $dbName,
    string $tag, float $qtyA, float $qtyB, string $idemKeyA, string $idemKeyB
): array {
    $poLineId = $fixture['purchase_order_line_id'];

    $requestA = buildRequest($fixture, $qtyA, $idemKeyA);
    $requestB = buildRequest($fixture, $qtyB, $idemKeyB);

    $configA = [
        'worker_id' => 'A', 'barrier_dir' => $barrierDir,
        'result_file' => $barrierDir . DIRECTORY_SEPARATOR . 'result-A.json',
        'request' => $requestA, 'db_name' => $dbName,
        'db_username' => $config['db_user'], 'db_password' => $config['db_pass'],
        'db_host' => $config['db_host'], 'db_port' => $config['db_port'],
        'base_path' => $config['base_path'],
    ];
    $configB = [
        'worker_id' => 'B', 'barrier_dir' => $barrierDir,
        'result_file' => $barrierDir . DIRECTORY_SEPARATOR . 'result-B.json',
        'request' => $requestB, 'db_name' => $dbName,
        'db_username' => $config['db_user'], 'db_password' => $config['db_pass'],
        'db_host' => $config['db_host'], 'db_port' => $config['db_port'],
        'base_path' => $config['base_path'],
    ];

    $cfgAFile = $barrierDir . DIRECTORY_SEPARATOR . 'cfg-A.json';
    $cfgBFile = $barrierDir . DIRECTORY_SEPARATOR . 'cfg-B.json';
    file_put_contents($cfgAFile, json_encode($configA));
    file_put_contents($cfgBFile, json_encode($configB));

    $workerScript = $config['base_path'] . '/tests/Postgres/Operations/Inventory/Support/ControlledGoodsReceiptConcurrencyWorker.php';

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];

    $procA = @proc_open(
        PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($cfgAFile),
        $descriptors, $pipesA, $config['base_path']
    );
    $procB = @proc_open(
        PHP_BINARY . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($cfgBFile),
        $descriptors, $pipesB, $config['base_path']
    );

    if (!is_resource($procA) || !is_resource($procB)) {
        return ['outcome' => 'PROC_OPEN_FAILED', 'tag' => $tag];
    }
    @fclose($pipesA[0]); @fclose($pipesA[1]);
    @fclose($pipesB[0]); @fclose($pipesB[1]);

    waitForFile($barrierDir, 'ready-A', 60);
    waitForFile($barrierDir, 'ready-B', 60);
    clearFiles($barrierDir, ['ready-A', 'ready-B']);

    $db = new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$dbName}",
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $db->beginTransaction();
    $db->exec("SELECT id FROM purchase_order_lines WHERE id = '{$poLineId}' FOR UPDATE");

    touch($barrierDir . DIRECTORY_SEPARATOR . 'start.signal');

    waitForFile($barrierDir, 'posting-A', 60);
    waitForFile($barrierDir, 'posting-B', 60);

    usleep(300000);
    $db->commit();
    $db = null;

    $exitA = proc_close($procA);
    $exitB = proc_close($procB);

    clearFiles($barrierDir, ['start.signal', 'posting-A', 'posting-B']);

    $resultA = readWorkerResult($barrierDir, 'A');
    $resultB = readWorkerResult($barrierDir, 'B');

    clearFiles($barrierDir, ['cfg-A.json', 'cfg-B.json', 'result-A.json', 'result-B.json']);

    return [
        'tag' => $tag,
        'worker_a' => $resultA,
        'worker_b' => $resultB,
        'pid_different' => ($resultA['pid'] ?? 0) !== ($resultB['pid'] ?? -1),
        'pg_different' => ($resultA['pg_backend_pid'] ?? 0) !== ($resultB['pg_backend_pid'] ?? -1),
    ];
}

function buildRequest(array $f, float $qty, string $idemKey): array {
    return [
        'purchase_order_id' => $f['purchase_order_id'],
        'lines' => [[
            'purchase_order_line_id' => $f['purchase_order_line_id'],
            'inventory_location_id' => $f['inventory_location_id'],
            'inventory_unit_id' => $f['inventory_unit_id'],
            'received_quantity' => $qty,
            'idempotency_key' => $idemKey,
        ]],
        'actor_id' => $f['actor_id'],
        'property_id' => $f['property_id'],
        'company_id' => $f['company_id'],
    ];
}

function waitForFile(string $dir, string $name, int $timeoutSec): void {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    $end = time() + $timeoutSec;
    while (time() < $end) { if (file_exists($path)) return; usleep(10000); }
    throw new \RuntimeException("Timeout: {$name}");
}

function clearFiles(string $dir, array $names): void {
    foreach ($names as $n) { $p = $dir . DIRECTORY_SEPARATOR . $n; if (file_exists($p)) @unlink($p); }
}

function readWorkerResult(string $dir, string $id): array {
    $p = $dir . DIRECTORY_SEPARATOR . 'result-' . $id . '.json';
    if (!file_exists($p)) return ['outcome' => 'NO_RESULT', 'pid' => -1, 'pg_backend_pid' => -1];
    return json_decode(file_get_contents($p), true) ?: ['outcome' => 'PARSE_ERROR'];
}
