<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
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
if (! is_array($config) || ! preg_match('/^ivorq_concurrency_p01e_[a-z0-9_]+$/', $config['db_name'] ?? '')) {
    exit(2);
}

function p01eAdmin(array $config): PDO
{
    return new PDO(
        "pgsql:host={$config['db_host']};port={$config['db_port']};dbname=postgres",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p01eWait(string $path, int $seconds = 90): void
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

function p01eFixture(
    string $tag,
    Property $property,
    User $actor,
    FinancialPeriod $period,
    string $businessDate,
): array {
    $category = InventoryCategory::firstOrCreate([
        'property_id' => $property->id,
        'name' => 'CC-P01E Concurrency',
    ]);
    $item = InventoryItem::create([
        'property_id' => $property->id,
        'category_id' => $category->id,
        'sku' => 'P01E-'.strtoupper($tag).'-'.Str::random(6),
        'name' => 'CC-P01E '.$tag,
        'inventory_type' => 'goods',
        'weighted_average_cost' => 777,
        'is_active' => true,
    ]);
    $sourceLocation = InventoryLocation::create([
        'property_id' => $property->id,
        'name' => 'CC-P01E '.$tag.' Source',
        'type' => 'internal',
    ]);
    $destinationLocation = InventoryLocation::create([
        'property_id' => $property->id,
        'name' => 'CC-P01E '.$tag.' Destination',
        'type' => 'internal',
    ]);
    $sourceScope = "property:{$property->id}:location:{$sourceLocation->id}:item:{$item->id}";
    $destinationScope = "property:{$property->id}:location:{$destinationLocation->id}:item:{$item->id}";
    $repository = app(CostAuthorityEnrollmentRepository::class);
    $group = $repository->createDraft(
        ['property_id' => $property->id, 'item_id' => $item->id],
        [
            [
                'location_id' => $sourceLocation->id,
                'valuation_scope' => $sourceScope,
                'opening_quantity' => '10.0000',
                'opening_carrying_value' => '75.0000',
                'currency_code' => 'USD',
                'business_date' => $businessDate,
                'financial_period_id' => $period->id,
                'source_reference' => 'CC-P01E-CONCURRENCY-SOURCE',
                'evidence_timestamp' => now(),
            ],
            [
                'location_id' => $destinationLocation->id,
                'valuation_scope' => $destinationScope,
                'opening_quantity' => '0.0000',
                'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD',
                'business_date' => $businessDate,
                'financial_period_id' => $period->id,
                'source_reference' => 'CC-P01E-CONCURRENCY-DESTINATION',
                'evidence_timestamp' => now(),
            ],
        ],
    );
    DB::transaction(fn () => $repository->approve($group->id, $actor->id, now()));
    app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $actor->id);
    $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $actor->id);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    if (! DB::table('cost_delivery_pilot_properties')->exists()) {
        DB::table('cost_delivery_pilot_properties')->insert([
            'id' => (string) Str::ulid(),
            'pilot_slot' => 1,
            'property_id' => $property->id,
            'owner_approval_reference' => 'CC-P01E-CONCURRENCY',
            'authorized_by' => $actor->id,
            'authorized_at' => now(),
            'created_at' => now(),
        ]);
    }
    $cutoverId = (string) Str::ulid();
    $snapshots = DB::table('cost_authority_enrollment_scope_snapshots')
        ->where('enrollment_group_id', $group->id)->orderBy('valuation_scope')->get();
    DB::transaction(function () use ($property, $actor, $period, $businessDate, $item, $group, $ownership, $cutoverId, $snapshots): void {
        DB::table('cost_delivery_cutovers')->insert([
            'id' => $cutoverId,
            'ownership_id' => $ownership->id,
            'enrollment_group_id' => $group->id,
            'property_id' => $property->id,
            'item_id' => $item->id,
            'financial_period_id' => $period->id,
            'boundary_business_date' => $businessDate,
            'owner_approval_reference' => 'CC-P01E-CONCURRENCY',
            'requested_by' => $actor->id,
            'requested_at' => now()->subMinutes(2),
            'approved_by' => $actor->id,
            'approved_at' => now()->subMinute(),
            'activated_by' => $actor->id,
            'activated_at' => now(),
            'created_at' => now(),
        ]);
        foreach ($snapshots as $snapshot) {
            DB::table('cost_delivery_cutover_scopes')->insert([
                'id' => (string) Str::ulid(),
                'cutover_id' => $cutoverId,
                'enrollment_scope_snapshot_id' => $snapshot->id,
                'property_id' => $property->id,
                'location_id' => $snapshot->location_id,
                'item_id' => $item->id,
                'valuation_scope' => $snapshot->valuation_scope,
                'inventory_sequence_source' => 'ALLOCATOR_ABSENT',
                'inventory_valuation_sequence_id' => null,
                'inventory_allocator_last_sequence' => 0,
                'cost_avco_last_valuation_sequence' => null,
                'sequence_state_classification' => 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => 0,
                'first_deferred_owned_sequence' => 1,
                'created_at' => now(),
            ]);
        }
        DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)->update([
            'delivery_mode' => 'DEFERRED',
            'ownership_version' => 2,
            'activated_cutover_id' => $cutoverId,
            'changed_by' => $actor->id,
            'changed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    });

    return [
        'item' => $item,
        'source_location' => $sourceLocation,
        'destination_location' => $destinationLocation,
        'source_scope' => $sourceScope,
        'destination_scope' => $destinationScope,
        'ownership_id' => $ownership->id,
        'cutover_id' => $cutoverId,
    ];
}

function p01eSource(array $fixture, Property $property, User $actor, FinancialPeriod $period, string $businessDate, TransactionTypeEnum $type, int $sequence, array $overrides = []): InventoryTransaction
{
    $id = (string) Str::ulid();

    return InventoryTransaction::create(array_merge([
        'id' => $id,
        'property_id' => $property->id,
        'item_id' => $fixture['item']->id,
        'location_id' => $fixture['source_location']->id,
        'currency_code' => 'USD',
        'financial_period_id' => $period->id,
        'valuation_scope' => $fixture['source_scope'],
        'valuation_sequence' => $sequence,
        'valuation_approval_status' => 'approved',
        'valuation_approval_reference' => 'CC-P01E-CONCURRENCY',
        'cost_delivery_mode' => 'DEFERRED',
        'cost_delivery_ownership_id' => $fixture['ownership_id'],
        'cost_delivery_ownership_version' => 2,
        'cost_delivery_cutover_id' => $fixture['cutover_id'],
        'business_date' => $businessDate,
        'occurred_at' => $businessDate.' 10:00:00',
        'source_document_type' => 'cc_p01e_concurrency',
        'source_document_id' => (string) Str::ulid(),
        'source_line_type' => 'cc_p01e_concurrency_line',
        'source_line_id' => (string) Str::ulid(),
        'movement_role' => $type->value,
        'idempotency_key' => 'ccp01e-concurrency-'.$id,
        'transaction_type' => $type,
        'quantity_before' => '10.0000',
        'quantity_change' => '2.0000',
        'quantity_after' => '12.0000',
        'unit_cost' => '7.5000',
        'total_cost' => '15.0000',
        'posted_by' => $actor->id,
        'posted_at' => $businessDate.' 10:00:00',
        'created_at' => now(),
    ], $overrides))->fresh();
}

function p01eOutbox(InventoryTransaction $source): string
{
    return app(OutboxRepository::class)->createPending([
        'topic' => 'inventory.transaction.posted',
        'source_inventory_transaction_id' => $source->id,
        'payload' => ['transactionId' => $source->id],
        'idempotency_key' => "inventory_transaction:{$source->id}:cost_ledger",
    ])->id;
}

function p01eRace(array $config, string $ownershipId, array $outboxIds, string $tag): array
{
    $worker = $config['base_path'].'/tests/Postgres/Finance/CostControl/Support/DeferredCostDeliveryConcurrencyWorker.php';
    $processes = [];
    foreach ($outboxIds as $workerId => $outboxId) {
        $workerConfig = array_merge($config, [
            'worker_id' => $workerId,
            'outbox_id' => $outboxId,
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

    DB::beginTransaction();
    DB::table('cost_delivery_mode_ownerships')->where('id', $ownershipId)->lockForUpdate()->first();
    foreach (array_keys($outboxIds) as $workerId) {
        p01eWait($config['barrier_dir'].'/ready-'.$workerId);
    }
    touch($config['barrier_dir'].'/start.signal');
    foreach (array_keys($outboxIds) as $workerId) {
        p01eWait($config['barrier_dir'].'/calling-'.$workerId);
    }
    usleep(300000);
    DB::commit();

    $results = [];
    foreach (array_keys($outboxIds) as $workerId) {
        p01eWait($config['barrier_dir'].'/done-'.$workerId);
        [$process, $pipes] = $processes[$workerId];
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $path = $config['barrier_dir'].'/result-'.$tag.'-'.$workerId.'.json';
        $results[$workerId] = json_decode((string) file_get_contents($path), true) ?: ['error' => $stderr];
    }
    foreach (['ready-', 'calling-', 'done-'] as $prefix) {
        foreach (array_keys($outboxIds) as $workerId) {
            @unlink($config['barrier_dir'].'/'.$prefix.$workerId);
        }
    }
    @unlink($config['barrier_dir'].'/start.signal');

    return $results;
}

$result = ['db_created' => false, 'migrations_ok' => false, 'db_dropped' => false, 'error' => null];
try {
    $admin = p01eAdmin($config);
    $quoted = '"'.$config['db_name'].'"';
    $admin->exec('DROP DATABASE IF EXISTS '.$quoted);
    $admin->exec('CREATE DATABASE '.$quoted);
    $admin = null;
    $result['db_created'] = true;

    require $config['base_path'].'/vendor/autoload.php';
    $app = require $config['base_path'].'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => $config['db_name']]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);
    $result['migrations_ok'] = true;

    $property = Property::where('currency', 'USD')->firstOrFail();
    $actor = User::firstOrFail();
    $businessDate = '2026-08-25';
    PropertyBusinessDate::updateOrCreate(
        ['property_id' => $property->id, 'business_date' => $businessDate],
        ['timezone_snapshot' => $property->timezone, 'status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true, 'opened_by' => $actor->id, 'opened_at' => now()],
    );
    $period = FinancialPeriod::updateOrCreate(
        ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 8],
        ['status' => FinancialPeriodStatusEnum::Open],
    );

    $same = p01eFixture('same', $property, $actor, $period, $businessDate);
    $sameSource = p01eSource($same, $property, $actor, $period, $businessDate, TransactionTypeEnum::PurchaseReceipt, 1);
    $sameOutbox = p01eOutbox($sameSource);
    $result['same_message']['workers'] = p01eRace($config, $same['ownership_id'], ['same-a' => $sameOutbox, 'same-b' => $sameOutbox], 'same');
    $result['same_message']['ledger'] = DB::table('cost_ledger_entries')->where('source_inventory_transaction_id', $sameSource->id)->count();
    $result['same_message']['attempts'] = DB::table('cost_delivery_outbox_dispositions')->where('outbox_message_id', $sameOutbox)->value('attempt_count');

    $transfer = p01eFixture('transfer', $property, $actor, $period, $businessDate);
    $documentId = (string) Str::ulid();
    $lineId = (string) Str::ulid();
    $occurredAt = $businessDate.' 11:00:00';
    $outbound = p01eSource($transfer, $property, $actor, $period, $businessDate, TransactionTypeEnum::TransferOut, 1, [
        'source_document_id' => $documentId, 'source_line_id' => $lineId, 'occurred_at' => $occurredAt,
        'quantity_change' => '-2.0000', 'quantity_after' => '8.0000', 'total_cost' => '-15.0000',
    ]);
    $inbound = p01eSource($transfer, $property, $actor, $period, $businessDate, TransactionTypeEnum::TransferIn, 1, [
        'location_id' => $transfer['destination_location']->id, 'valuation_scope' => $transfer['destination_scope'],
        'source_document_id' => $documentId, 'source_line_id' => $lineId, 'occurred_at' => $occurredAt,
        'quantity_before' => '0.0000', 'quantity_change' => '2.0000', 'quantity_after' => '2.0000', 'total_cost' => '15.0000',
    ]);
    $outboundOutbox = p01eOutbox($outbound);
    $inboundOutbox = p01eOutbox($inbound);
    $result['opposite_legs']['workers'] = p01eRace($config, $transfer['ownership_id'], ['pair-a' => $outboundOutbox, 'pair-b' => $inboundOutbox], 'pair');
    $result['opposite_legs']['ledger'] = DB::table('cost_ledger_entries')->whereIn('source_inventory_transaction_id', [$outbound->id, $inbound->id])->count();
    $result['opposite_legs']['delivered_dispositions'] = DB::table('cost_delivery_outbox_dispositions')->whereIn('outbox_message_id', [$outboundOutbox, $inboundOutbox])->where('processing_state', 'DELIVERED')->count();

    $scope = p01eFixture('scope', $property, $actor, $period, $businessDate);
    $n1 = p01eSource($scope, $property, $actor, $period, $businessDate, TransactionTypeEnum::PurchaseReceipt, 1);
    $n2 = p01eSource($scope, $property, $actor, $period, $businessDate, TransactionTypeEnum::PurchaseReceipt, 2);
    $n1Outbox = p01eOutbox($n1);
    $n2Outbox = p01eOutbox($n2);
    $result['same_scope']['workers'] = p01eRace($config, $scope['ownership_id'], ['scope-n2' => $n2Outbox, 'scope-n1' => $n1Outbox], 'scope');
    $result['same_scope']['ledger'] = DB::table('cost_ledger_entries')->whereIn('source_inventory_transaction_id', [$n1->id, $n2->id])->count();
    $result['same_scope']['last_sequence'] = DB::table('cost_avco_states')->where('property_id', $property->id)->where('location_id', $scope['source_location']->id)->where('item_id', $scope['item']->id)->value('last_valuation_sequence');
    $result['same_scope']['states'] = DB::table('cost_delivery_outbox_dispositions')->whereIn('outbox_message_id', [$n1Outbox, $n2Outbox])->orderBy('valuation_sequence')->pluck('processing_state', 'valuation_sequence')->all();
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.$exception->getMessage();
}

try {
    DB::disconnect('pgsql');
    DB::purge('pgsql');
} catch (Throwable) {
}
try {
    $admin = p01eAdmin($config);
    $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()');
    $terminate->execute(['database' => $config['db_name']]);
    $admin->exec('DROP DATABASE IF EXISTS "'.$config['db_name'].'"');
    $result['db_dropped'] = true;
} catch (Throwable $exception) {
    $result['drop_error'] = $exception->getMessage();
}

file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
