<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;

$configPath = $argv[1] ?? '';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($config) || ! preg_match('/^ivorq_concurrency_p01d_[a-z0-9_]+$/', $config['db_name'] ?? '')) {
    exit(2);
}

function p01dAdmin(array $config): PDO
{
    return new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname=postgres",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p01dWait(string $path, int $seconds = 90): void
{
    $until = time() + $seconds;
    while (time() < $until) {
        if (is_file($path)) {
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('BARRIER_TIMEOUT:'.basename($path));
}

function p01dFixture(string $tag, string $propertyId, string $actorId, string $periodId, string $businessDate): array
{
    $now = now();
    $category = InventoryCategory::firstOrCreate([
        'property_id' => $propertyId,
        'name' => 'CC-P01D Concurrency '.$tag,
    ]);
    $item = InventoryItem::create([
        'property_id' => $propertyId,
        'category_id' => $category->id,
        'sku' => 'P01D-'.strtoupper($tag).'-'.Str::random(6),
        'name' => 'CC-P01D '.$tag,
        'inventory_type' => 'goods',
        'weighted_average_cost' => 10,
        'is_active' => true,
    ]);
    $location = InventoryLocation::create([
        'property_id' => $propertyId,
        'name' => 'CC-P01D '.$tag.' Location',
        'type' => 'internal',
    ]);
    $scope = "property:{$propertyId}:location:{$location->id}:item:{$item->id}";
    $groupId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $ownershipId = (string) Str::ulid();

    DB::table('cost_authority_enrollment_groups')->insert([
        'id' => $groupId,
        'property_id' => $propertyId,
        'item_id' => $item->id,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('cost_authority_enrollment_scope_snapshots')->insert([
        'id' => $snapshotId,
        'enrollment_group_id' => $groupId,
        'location_id' => $location->id,
        'valuation_scope' => $scope,
        'opening_quantity' => '10.0000',
        'opening_carrying_value' => '100.0000',
        'currency_code' => 'USD',
        'business_date' => $businessDate,
        'financial_period_id' => $periodId,
        'source_reference' => 'CC-P01D-CONCURRENCY',
        'evidence_timestamp' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('cost_avco_states')->insert([
        'id' => (string) Str::ulid(),
        'property_id' => $propertyId,
        'location_id' => $location->id,
        'item_id' => $item->id,
        'valuation_scope' => $scope,
        'on_hand_quantity' => '10.0000',
        'carrying_value' => '100.0000',
        'weighted_average_unit_cost' => '10.0000',
        'unresolved_provisional_quantity' => '0.0000',
        'last_valuation_sequence' => 1,
        'last_valuation_business_date' => $businessDate,
        'enrollment_group_id' => $groupId,
        'enrollment_scope_snapshot_id' => $snapshotId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::transaction(function () use ($groupId, $ownershipId, $propertyId, $item, $actorId, $now): void {
        DB::table('cost_authority_enrollment_groups')->where('id', $groupId)->update([
            'status' => 'approved',
            'approved_by' => $actorId,
            'approved_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('cost_authority_enrollment_groups')->where('id', $groupId)->update([
            'status' => 'enrolled',
            'enrolled_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('cost_delivery_mode_ownerships')->insert([
            'id' => $ownershipId,
            'property_id' => $propertyId,
            'item_id' => $item->id,
            'enrollment_group_id' => $groupId,
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 1,
            'established_by' => $actorId,
            'established_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });
    DB::table('inventory_stocks')->insert([
        'id' => (string) Str::ulid(),
        'property_id' => $propertyId,
        'item_id' => $item->id,
        'location_id' => $location->id,
        'physical_quantity' => '10.0000',
        'reserved_quantity' => '0.0000',
        'status' => 'in_stock',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('inventory_valuation_sequences')->insert([
        'id' => (string) Str::ulid(),
        'property_id' => $propertyId,
        'location_id' => $location->id,
        'item_id' => $item->id,
        'last_sequence' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $original = new InventoryTransaction;
    $original->id = (string) Str::ulid();
    $original->property_id = $propertyId;
    $original->item_id = $item->id;
    $original->location_id = $location->id;
    $original->transaction_type = TransactionTypeEnum::PurchaseReceipt;
    $original->quantity_before = '5.0000';
    $original->quantity_change = '5.0000';
    $original->quantity_after = '10.0000';
    $original->unit_cost = '10.0000';
    $original->total_cost = '50.0000';
    $original->posted_at = $now;
    $original->business_date = $businessDate;
    $original->occurred_at = $now;
    $original->source_document_type = 'concurrency_fixture';
    $original->source_document_id = (string) Str::ulid();
    $original->source_line_type = 'concurrency_fixture_line';
    $original->source_line_id = (string) Str::ulid();
    $original->movement_role = 'receive';
    $original->currency_code = 'USD';
    $original->financial_period_id = $periodId;
    $original->valuation_scope = $scope;
    $original->valuation_sequence = 1;
    $original->save();

    return [
        'property_id' => $propertyId,
        'actor_id' => $actorId,
        'period_id' => $periodId,
        'business_date' => $businessDate,
        'item_id' => $item->id,
        'location_id' => $location->id,
        'valuation_scope' => $scope,
        'group_id' => $groupId,
        'snapshot_id' => $snapshotId,
        'ownership_id' => $ownershipId,
        'original_id' => $original->id,
    ];
}

function p01dRace(array $config, array $fixture, array $actions, string $tag, string $lockTable, string $lockId): array
{
    $worker = $config['base_path'].'/tests/Postgres/Operations/Inventory/Support/InventoryReversalConcurrencyWorker.php';
    $processes = [];
    foreach ($actions as $workerId => $action) {
        $workerConfig = array_merge($config, $fixture, [
            'worker_id' => $workerId,
            'action' => $action,
            'idempotency_key' => 'p01d-concurrency-'.$tag,
            'result_file' => $config['barrier_dir'].'/result-'.$tag.'-'.$workerId.'.json',
        ]);
        $path = $config['barrier_dir'].'/config-'.$tag.'-'.$workerId.'.json';
        file_put_contents($path, json_encode($workerConfig));
        $process = proc_open(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg($path),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $config['base_path'],
        );
        fclose($pipes[0]);
        $processes[$workerId] = [$process, $pipes];
    }

    $pdo = new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->beginTransaction();
    $statement = $pdo->prepare("SELECT id FROM {$lockTable} WHERE id = :id FOR UPDATE");
    $statement->execute(['id' => $lockId]);

    foreach (array_keys($actions) as $workerId) {
        p01dWait($config['barrier_dir'].'/ready-'.$workerId);
    }
    touch($config['barrier_dir'].'/start.signal');
    foreach (array_keys($actions) as $workerId) {
        p01dWait($config['barrier_dir'].'/calling-'.$workerId);
    }
    usleep(300000);
    $pdo->commit();
    $pdo = null;

    $results = [];
    foreach (array_keys($actions) as $workerId) {
        p01dWait($config['barrier_dir'].'/done-'.$workerId);
        [$process, $pipes] = $processes[$workerId];
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $resultPath = $config['barrier_dir'].'/result-'.$tag.'-'.$workerId.'.json';
        $results[$workerId] = json_decode((string) file_get_contents($resultPath), true) ?: ['error' => $stderr];
    }

    foreach (['ready-', 'calling-', 'done-'] as $prefix) {
        foreach (array_keys($actions) as $workerId) {
            @unlink($config['barrier_dir'].'/'.$prefix.$workerId);
        }
    }
    @unlink($config['barrier_dir'].'/start.signal');

    return $results;
}

$result = ['db_created' => false, 'migrations_ok' => false, 'db_dropped' => false, 'error' => null];
try {
    $admin = p01dAdmin($config);
    $dbName = '"'.$config['db_name'].'"';
    $admin->exec('DROP DATABASE IF EXISTS '.$dbName);
    $admin->exec('CREATE DATABASE '.$dbName);
    $admin = null;
    $result['db_created'] = true;

    require $config['base_path'].'/vendor/autoload.php';
    $app = require $config['base_path'].'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => $config['db_name']]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    Artisan::call('migrate', ['--force' => true]);
    $result['migrations_ok'] = true;

    $now = now();
    $companyId = (string) Str::ulid();
    DB::table('companies')->insert([
        'id' => $companyId,
        'name' => 'CC-P01D Concurrency Company',
        'slug' => 'cc-p01d-concurrency',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $property = Property::create([
        'company_id' => $companyId,
        'name' => 'CC-P01D Concurrency Property',
        'slug' => 'cc-p01d-concurrency-property',
        'code' => 'P01D',
        'currency' => 'USD',
        'timezone' => 'Asia/Makassar',
        'is_active' => true,
    ]);
    $actor = User::create([
        'name' => 'CC-P01D Actor',
        'email' => 'cc-p01d-concurrency@example.test',
        'password' => bcrypt('password'),
    ]);
    $businessDate = now()->toDateString();
    PropertyBusinessDate::create([
        'property_id' => $property->id,
        'business_date' => $businessDate,
        'status' => PropertyBusinessDateStatusEnum::Open,
        'is_open' => true,
        'opened_at' => $now,
        'opened_by' => $actor->id,
    ]);
    $period = FinancialPeriod::create([
        'property_id' => $property->id,
        'period_year' => now()->year,
        'period_month' => now()->month,
        'status' => FinancialPeriodStatusEnum::Open,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
    ]);

    $same = p01dFixture('same', $property->id, $actor->id, $period->id, $businessDate);
    $sameResults = p01dRace(
        $config,
        $same,
        ['same-a' => 'post', 'same-b' => 'post'],
        'same',
        'inventory_transactions',
        $same['original_id'],
    );
    $sameReversals = DB::table('inventory_transactions')
        ->where('reverses_inventory_transaction_id', $same['original_id'])->get();
    $sameSourceId = $sameReversals->first()?->id;
    $result['same_reversal'] = [
        'workers' => $sameResults,
        'durable_sources' => $sameReversals->count(),
        'source_ids' => $sameReversals->pluck('id')->all(),
        'ledger_effects' => $sameSourceId === null ? 0 : DB::table('cost_ledger_entries')->where('source_inventory_transaction_id', $sameSourceId)->count(),
        'outbox_effects' => $sameSourceId === null ? 0 : DB::table('outbox_messages')->where('source_inventory_transaction_id', $sameSourceId)->count(),
        'last_sequence' => (int) DB::table('inventory_valuation_sequences')->where('item_id', $same['item_id'])->value('last_sequence'),
        'physical_quantity' => (string) DB::table('inventory_stocks')->where('item_id', $same['item_id'])->value('physical_quantity'),
    ];

    $race = p01dFixture('cutover', $property->id, $actor->id, $period->id, $businessDate);
    $raceResults = p01dRace(
        $config,
        $race,
        ['race-post' => 'post', 'race-cutover' => 'cutover'],
        'cutover',
        'cost_delivery_mode_ownerships',
        $race['ownership_id'],
    );
    $source = DB::table('inventory_transactions')
        ->where('reverses_inventory_transaction_id', $race['original_id'])->first();
    $result['posting_cutover'] = [
        'workers' => $raceResults,
        'durable_sources' => $source === null ? 0 : 1,
        'mode' => $source?->cost_delivery_mode,
        'ownership_id' => $source?->cost_delivery_ownership_id,
        'ownership_version' => $source?->cost_delivery_ownership_version,
        'cutover_id' => $source?->cost_delivery_cutover_id,
        'ledger_effects' => $source === null ? 0 : DB::table('cost_ledger_entries')->where('source_inventory_transaction_id', $source->id)->count(),
        'outbox_effects' => $source === null ? 0 : DB::table('outbox_messages')->where('source_inventory_transaction_id', $source->id)->count(),
        'avco_sequence' => (int) DB::table('cost_avco_states')->where('item_id', $race['item_id'])->value('last_valuation_sequence'),
        'last_sequence' => (int) DB::table('inventory_valuation_sequences')->where('item_id', $race['item_id'])->value('last_sequence'),
        'physical_quantity' => (string) DB::table('inventory_stocks')->where('item_id', $race['item_id'])->value('physical_quantity'),
    ];
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.$exception->getMessage();
}

try {
    DB::disconnect('pgsql');
    DB::purge('pgsql');
} catch (Throwable) {
}
try {
    $admin = p01dAdmin($config);
    $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()');
    $terminate->execute(['database' => $config['db_name']]);
    $admin->exec('DROP DATABASE IF EXISTS "'.$config['db_name'].'"');
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
