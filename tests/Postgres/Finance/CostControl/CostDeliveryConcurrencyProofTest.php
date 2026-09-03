<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Services\CostDeliveryCutoverService;
use Modules\Finance\CostControl\Services\CostDeliveryHistoricalDispositionService;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\InventoryDocumentMutationGate;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Tests\Postgres\Finance\CostControl\Support\CostDeliveryCutoverFixture;
use Tests\PostgresTestCase;

class CostDeliveryConcurrencyProofTest extends PostgresTestCase
{
    use CostDeliveryCutoverFixture;

    public function test_same_message_same_scope_and_opposite_transfer_leg_concurrency(): void
    {
        $suffix = strtolower(Str::random(10));
        $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-p01e-'.$suffix;
        mkdir($barrierDir, 0777, true);
        $resultFile = $barrierDir.DIRECTORY_SEPARATOR.'result.json';
        $config = [
            'base_path' => base_path(),
            'db_name' => 'ivorq_concurrency_p01e_'.$suffix,
            'db_host' => config('database.connections.pgsql.host'),
            'db_port' => config('database.connections.pgsql.port'),
            'db_user' => config('database.connections.pgsql.username'),
            'db_pass' => config('database.connections.pgsql.password'),
            'barrier_dir' => $barrierDir,
            'result_file' => $resultFile,
        ];
        $configFile = $barrierDir.DIRECTORY_SEPARATOR.'config.json';
        file_put_contents($configFile, json_encode($config));
        $coordinator = base_path('tests/Postgres/Finance/CostControl/Support/DeferredCostDeliveryConcurrencyCoordinator.php');
        $process = proc_open(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($coordinator).' '.escapeshellarg($configFile),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
        foreach (glob($barrierDir.DIRECTORY_SEPARATOR.'*') ?: [] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        rmdir($barrierDir);

        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertIsArray($result, $stdout."\n".$stderr);
        $this->assertNull($result['error'], json_encode($result));
        $this->assertTrue($result['db_created']);
        $this->assertTrue($result['migrations_ok']);
        $this->assertTrue($result['db_dropped']);

        $this->assertSame(1, $result['same_message']['ledger']);
        $this->assertSame(1, $result['same_message']['attempts']);
        $this->assertEqualsCanonicalizing(
            ['DELIVERED', 'ALREADY_DELIVERED'],
            array_column($result['same_message']['workers'], 'status'),
        );

        $this->assertSame(2, $result['opposite_legs']['ledger'], json_encode($result['opposite_legs']));
        $this->assertSame(2, $result['opposite_legs']['delivered_dispositions']);
        $this->assertEqualsCanonicalizing(
            ['DELIVERED', 'ALREADY_DELIVERED'],
            array_column($result['opposite_legs']['workers'], 'status'),
        );

        $this->assertContains($result['same_scope']['ledger'], [1, 2]);
        $this->assertSame($result['same_scope']['ledger'], $result['same_scope']['last_sequence']);
        $this->assertSame('DELIVERED', $result['same_scope']['states']['1']);
        if ($result['same_scope']['ledger'] === 1) {
            $this->assertSame('BLOCKED_SEQUENCE', $result['same_scope']['states']['2']);
        } else {
            $this->assertSame('DELIVERED', $result['same_scope']['states']['2']);
        }
    }

    public function test_document_mutation_and_cutover_share_the_ownership_latch_without_a_quiescence_gap(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--force' => true]);
        [$request, , $actorId] = $this->makeCutoverFixture();
        $locationId = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $request->enrollmentGroupId)->value('location_id');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-p01f-'.strtolower(Str::random(10));
        mkdir($directory, 0777, true);
        $config = [
            'base_path' => base_path(),
            'db_name' => 'ivorq_testing',
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'location_id' => $locationId,
            'actor_id' => $actorId,
            'ready_file' => $directory.DIRECTORY_SEPARATOR.'ready',
            'start_file' => $directory.DIRECTORY_SEPARATOR.'start',
            'locked_file' => $directory.DIRECTORY_SEPARATOR.'locked',
            'result_file' => $directory.DIRECTORY_SEPARATOR.'result.json',
        ];
        $configFile = $directory.DIRECTORY_SEPARATOR.'config.json';
        file_put_contents($configFile, json_encode($config));
        $worker = base_path('tests/Postgres/Finance/CostControl/Support/CostDeliveryDocumentMutationConcurrencyWorker.php');
        $process = proc_open(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg($configFile),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        fclose($pipes[0]);
        $this->waitForFile($config['ready_file']);
        touch($config['start_file']);
        $this->waitForFile($config['locked_file']);

        $started = microtime(true);
        try {
            app(CostDeliveryCutoverService::class)->activateGroup($request);
            $this->fail('The committed draft must block cutover after ownership serialization.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CUTOVER_BLOCKED_IN_FLIGHT_DOCUMENT', $exception->getMessage());
        }
        $this->assertGreaterThanOrEqual(0.45, microtime(true) - $started);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);
        $workerResult = json_decode((string) file_get_contents($config['result_file']), true);
        $this->assertNull($workerResult['error'], json_encode($workerResult));
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseHas('cost_delivery_cutover_attempts', [
            'request_id' => $request->requestId,
            'outcome' => 'CUTOVER_BLOCKED',
            'reason_code' => 'CUTOVER_BLOCKED_IN_FLIGHT_DOCUMENT',
        ]);
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 1,
        ]);

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }

    public function test_posting_first_completes_synchronously_before_cutover_observes_the_source(): void
    {
        $this->resetDatabase();
        [$request, , $actorId] = $this->makeCutoverFixture();
        $locationId = $this->fixtureLocationId($request->enrollmentGroupId);
        $this->ensureStock($request->propertyId, $request->itemId, $locationId);
        $intent = $this->receiptIntent($request->propertyId, $request->itemId, $locationId);
        [$process, $pipes, $config] = $this->startOwnershipWorker('post_synchronous_and_hold', [
            'actor_id' => $actorId,
            'intent' => $this->intentPayload($intent),
        ]);

        $started = microtime(true);
        try {
            app(CostDeliveryCutoverService::class)->activateGroup($request);
            $this->fail('The committed synchronous source must block this boundary cutover.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CUTOVER_BLOCKED_TARGET_PERIOD_SOURCE_EXISTS', $exception->getMessage());
        }
        $this->assertGreaterThanOrEqual(0.45, microtime(true) - $started);
        $worker = $this->finishOwnershipWorker($process, $pipes, $config);

        $this->assertNull($worker['error'], json_encode($worker));
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $worker['transaction_id'],
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_cutover_id' => null,
            'valuation_sequence' => 1,
        ]);
        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $worker['ledger_id'],
            'source_inventory_transaction_id' => $worker['transaction_id'],
            'entry_sequence' => 1,
        ]);
        $this->assertDatabaseHas('cost_avco_states', [
            'property_id' => $request->propertyId,
            'location_id' => $locationId,
            'item_id' => $request->itemId,
            'last_valuation_sequence' => 1,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'source_inventory_transaction_id' => $worker['transaction_id'],
            'topic' => 'inventory.transaction.posted',
        ]);
    }

    public function test_cutover_first_makes_waiting_post_deferred_and_rejects_synchronous_adapter(): void
    {
        $this->resetDatabase();
        [$request, , $actorId] = $this->makeCutoverFixture();
        $locationId = $this->fixtureLocationId($request->enrollmentGroupId);
        $this->ensureStock($request->propertyId, $request->itemId, $locationId);
        $intent = $this->receiptIntent($request->propertyId, $request->itemId, $locationId);
        [$process, $pipes, $config] = $this->startOwnershipWorker('activate_and_hold', [
            'request' => $this->requestPayload($request),
        ]);

        $started = microtime(true);
        $source = DB::transaction(function () use ($intent, $actorId): InventoryTransaction {
            $source = app(InventoryPostingControlCoordinator::class)->post($intent, $actorId);
            try {
                app(SynchronousCostValuationPort::class)->applyReceipt($source->id);
                $this->fail('A deferred source must never enter synchronous valuation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('CC_P01F_SYNCHRONOUS_SOURCE_STAMP_INVALID', $exception->getMessage());
            }

            return $source;
        });
        $this->assertGreaterThanOrEqual(0.45, microtime(true) - $started);
        $worker = $this->finishOwnershipWorker($process, $pipes, $config);

        $this->assertNull($worker['error'], json_encode($worker));
        $this->assertSame($worker['cutover_id'], $source->cost_delivery_cutover_id);
        $this->assertSame('DEFERRED', $source->cost_delivery_mode);
        $this->assertSame(2, (int) $source->cost_delivery_ownership_version);
        $this->assertSame(1, (int) $source->valuation_sequence);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull(CostAvcoState::where('property_id', $request->propertyId)
            ->where('location_id', $locationId)->where('item_id', $request->itemId)
            ->firstOrFail()->last_valuation_sequence);
        $this->assertDatabaseHas('outbox_messages', [
            'source_inventory_transaction_id' => $source->id,
            'topic' => 'inventory.transaction.posted',
        ]);
    }

    public function test_existing_synchronous_retry_does_not_wait_for_or_duplicate_concurrent_cutover(): void
    {
        $this->resetDatabase();
        [$request, $ownershipId, $actorId] = $this->makeCutoverFixture();
        $locationId = $this->fixtureLocationId($request->enrollmentGroupId);
        [$source, $intent, $outboxId] = $this->seedSynchronousHistoricalSource(
            $request,
            $ownershipId,
            $actorId,
            $locationId,
        );
        DB::transaction(fn () => app(SynchronousCostValuationPort::class)->applyReceipt($source->id));
        app(CostDeliveryHistoricalDispositionService::class)->classify($outboxId, $actorId);
        $counts = $this->effectCounts();
        [$process, $pipes, $config] = $this->startOwnershipWorker('activate_and_hold', [
            'request' => $this->requestPayload($request),
        ]);

        $retry = app(InventoryPostingControlCoordinator::class)->post($intent, $actorId);
        $worker = $this->finishOwnershipWorker($process, $pipes, $config);

        $this->assertNull($worker['error'], json_encode($worker));
        $this->assertSame($source->id, $retry->id);
        $this->assertSame($counts, $this->effectCounts());
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'id' => $ownershipId,
            'delivery_mode' => 'DEFERRED',
            'ownership_version' => 2,
            'activated_cutover_id' => $worker['cutover_id'],
        ]);
        $this->assertDatabaseHas('cost_delivery_cutover_scopes', [
            'cutover_id' => $worker['cutover_id'],
            'inventory_allocator_last_sequence' => 1,
            'cost_avco_last_valuation_sequence' => 1,
            'last_synchronously_owned_sequence' => 1,
            'first_deferred_owned_sequence' => 2,
            'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
        ]);
    }

    public function test_synchronous_adapter_first_holds_ownership_until_apply_finishes(): void
    {
        $this->resetDatabase();
        [$request, $ownershipId, $actorId] = $this->makeCutoverFixture();
        $locationId = $this->fixtureLocationId($request->enrollmentGroupId);
        [$source] = $this->seedSynchronousHistoricalSource($request, $ownershipId, $actorId, $locationId);
        [$process, $pipes, $config] = $this->startOwnershipWorker('apply_synchronous_and_hold', [
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'source_inventory_transaction_id' => $source->id,
        ]);

        $started = microtime(true);
        try {
            app(CostDeliveryCutoverService::class)->activateGroup($request);
            $this->fail('Unclassified historical evidence must block cutover after synchronous apply.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CUTOVER_BLOCKED_HISTORICAL_EVIDENCE_UNCLASSIFIED', $exception->getMessage());
        }
        $this->assertGreaterThanOrEqual(0.45, microtime(true) - $started);
        $worker = $this->finishOwnershipWorker($process, $pipes, $config);

        $this->assertNull($worker['error'], json_encode($worker));
        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $worker['ledger_id'],
            'source_inventory_transaction_id' => $source->id,
        ]);
        $this->assertSame(1, (int) CostAvcoState::where('property_id', $request->propertyId)
            ->where('location_id', $locationId)->where('item_id', $request->itemId)
            ->firstOrFail()->last_valuation_sequence);
    }

    public function test_cutover_first_serializes_later_document_mutation_under_deferred_ownership(): void
    {
        $this->resetDatabase();
        [$request, , $actorId] = $this->makeCutoverFixture();
        $locationId = $this->fixtureLocationId($request->enrollmentGroupId);
        [$process, $pipes, $config] = $this->startOwnershipWorker('activate_and_hold', [
            'request' => $this->requestPayload($request),
        ]);

        $started = microtime(true);
        DB::transaction(function () use ($request, $actorId, $locationId): void {
            app(InventoryDocumentMutationGate::class)->lock($request->propertyId, [$request->itemId]);
            $receiptId = (string) Str::ulid();
            DB::table('inventory_receipts')->insert([
                'id' => $receiptId,
                'property_id' => $request->propertyId,
                'receipt_number' => 'P01F-DEFERRED-'.Str::random(8),
                'status' => 'draft',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('inventory_receipt_lines')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $request->propertyId,
                'receipt_id' => $receiptId,
                'item_id' => $request->itemId,
                'location_id' => $locationId,
                'quantity' => '1.0000',
                'unit_cost' => '1.00',
                'line_total' => '1.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertGreaterThanOrEqual(0.45, microtime(true) - $started);
        $worker = $this->finishOwnershipWorker($process, $pipes, $config);

        $this->assertNull($worker['error'], json_encode($worker));
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'delivery_mode' => 'DEFERRED',
            'activated_cutover_id' => $worker['cutover_id'],
        ]);
        $this->assertDatabaseHas('inventory_receipt_lines', ['item_id' => $request->itemId]);
    }

    private function waitForFile(string $path): void
    {
        for ($attempt = 0; $attempt < 9000; $attempt++) {
            if (is_file($path)) {
                return;
            }
            usleep(10000);
        }
        throw new RuntimeException('P01F_CONCURRENCY_BARRIER_TIMEOUT');
    }

    private function resetDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--force' => true]);
    }

    private function fixtureLocationId(string $groupId): string
    {
        return (string) DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $groupId)->value('location_id');
    }

    private function ensureStock(string $propertyId, string $itemId, string $locationId): void
    {
        InventoryStock::firstOrCreate(
            ['property_id' => $propertyId, 'item_id' => $itemId, 'location_id' => $locationId],
            ['physical_quantity' => '0.0000', 'status' => ItemStatusEnum::OutOfStock],
        );
    }

    private function receiptIntent(string $propertyId, string $itemId, string $locationId): InventoryLedgerPostingIntent
    {
        return new InventoryLedgerPostingIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            locationId: $locationId,
            businessDate: '2026-09-01',
            occurredAt: Carbon::parse('2026-09-01 10:00:00', 'UTC'),
            sourceDocumentType: 'inventory_receipt',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'inventory_receipt_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: TransactionTypeEnum::PurchaseReceipt->value,
            idempotencyKey: 'p01f-race-'.Str::random(12),
            transactionType: TransactionTypeEnum::PurchaseReceipt,
            quantityChange: '1.0000',
            unitCost: '10.0000',
            totalCost: '10.0000',
            notes: 'P01F concurrency proof',
        );
    }

    private function intentPayload(InventoryLedgerPostingIntent $intent): array
    {
        return [
            'propertyId' => $intent->propertyId,
            'itemId' => $intent->itemId,
            'locationId' => $intent->locationId,
            'businessDate' => $intent->businessDate,
            'occurredAt' => $intent->occurredAt->toIso8601String(),
            'sourceDocumentType' => $intent->sourceDocumentType,
            'sourceDocumentId' => $intent->sourceDocumentId,
            'sourceLineType' => $intent->sourceLineType,
            'sourceLineId' => $intent->sourceLineId,
            'movementRole' => $intent->movementRole,
            'idempotencyKey' => $intent->idempotencyKey,
            'transactionType' => $intent->transactionType->value,
            'quantityChange' => $intent->quantityChange,
            'unitCost' => $intent->unitCost,
            'totalCost' => $intent->totalCost,
            'notes' => $intent->notes,
        ];
    }

    private function requestPayload($request): array
    {
        return [
            'requestId' => $request->requestId,
            'propertyId' => $request->propertyId,
            'itemId' => $request->itemId,
            'enrollmentGroupId' => $request->enrollmentGroupId,
            'targetFinancialPeriodId' => $request->targetFinancialPeriodId,
            'boundaryBusinessDate' => $request->boundaryBusinessDate,
            'requestedBy' => $request->requestedBy,
            'approvedBy' => $request->approvedBy,
            'ownerApprovalReference' => $request->ownerApprovalReference,
        ];
    }

    private function startOwnershipWorker(string $action, array $extra): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-p01f-'.strtolower(Str::random(10));
        mkdir($directory, 0777, true);
        $config = array_merge([
            'action' => $action,
            'base_path' => base_path(),
            'db_name' => 'ivorq_testing',
            'ready_file' => $directory.DIRECTORY_SEPARATOR.'ready',
            'start_file' => $directory.DIRECTORY_SEPARATOR.'start',
            'locked_file' => $directory.DIRECTORY_SEPARATOR.'locked',
            'result_file' => $directory.DIRECTORY_SEPARATOR.'result.json',
            'config_file' => $directory.DIRECTORY_SEPARATOR.'config.json',
            'directory' => $directory,
        ], $extra);
        file_put_contents($config['config_file'], json_encode($config));
        $worker = base_path('tests/Postgres/Finance/CostControl/Support/CostDeliveryOwnershipConcurrencyWorker.php');
        $process = proc_open(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg($config['config_file']),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        fclose($pipes[0]);
        $this->waitForFile($config['ready_file']);
        touch($config['start_file']);
        $this->waitForFile($config['locked_file']);

        return [$process, $pipes, $config];
    }

    private function finishOwnershipWorker($process, array $pipes, array $config): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = is_file($config['result_file'])
            ? json_decode((string) file_get_contents($config['result_file']), true)
            : null;
        foreach (glob($config['directory'].DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($config['directory']);
        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertIsArray($result, $stdout."\n".$stderr);

        return $result;
    }

    private function seedSynchronousHistoricalSource(
        $request,
        string $ownershipId,
        string $actorId,
        string $locationId,
    ): array {
        $intent = new InventoryLedgerPostingIntent(
            propertyId: $request->propertyId,
            itemId: $request->itemId,
            locationId: $locationId,
            businessDate: '2026-08-31',
            occurredAt: Carbon::parse('2026-08-31 10:00:00', 'UTC'),
            sourceDocumentType: 'inventory_receipt',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'inventory_receipt_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: TransactionTypeEnum::PurchaseReceipt->value,
            idempotencyKey: 'p01f-history-'.Str::random(12),
            transactionType: TransactionTypeEnum::PurchaseReceipt,
            quantityChange: '1.0000',
            unitCost: '10.0000',
            totalCost: '10.0000',
            notes: 'P01F historical race proof',
        );
        $periodId = FinancialPeriod::where('property_id', $request->propertyId)
            ->where('period_year', 2026)->where('period_month', 8)->value('id');
        $source = InventoryTransaction::create([
            'property_id' => $intent->propertyId,
            'item_id' => $intent->itemId,
            'location_id' => $intent->locationId,
            'currency_code' => 'USD',
            'financial_period_id' => $periodId,
            'valuation_scope' => "property:{$intent->propertyId}:location:{$intent->locationId}:item:{$intent->itemId}",
            'valuation_sequence' => 1,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => "inventory_receipt:{$intent->sourceDocumentId}:posted",
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_id' => $ownershipId,
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
            'business_date' => $intent->businessDate,
            'occurred_at' => $intent->occurredAt,
            'source_document_type' => $intent->sourceDocumentType,
            'source_document_id' => $intent->sourceDocumentId,
            'source_line_type' => $intent->sourceLineType,
            'source_line_id' => $intent->sourceLineId,
            'movement_role' => $intent->movementRole,
            'idempotency_key' => $intent->idempotencyKey,
            'transaction_type' => $intent->transactionType,
            'quantity_before' => '0.0000',
            'quantity_change' => $intent->quantityChange,
            'quantity_after' => '1.0000',
            'unit_cost' => $intent->unitCost,
            'total_cost' => $intent->totalCost,
            'notes' => $intent->notes,
            'posted_by' => $actorId,
            'posted_at' => $intent->occurredAt,
        ]);
        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $request->propertyId,
            'location_id' => $locationId,
            'item_id' => $request->itemId,
            'last_sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outbox = app(OutboxRepository::class)->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $source->id,
            'payload' => ['transactionId' => $source->id],
            'idempotency_key' => "inventory_transaction:{$source->id}:cost_ledger",
        ]);

        return [$source, $intent, $outbox->id];
    }

    private function effectCounts(): array
    {
        return [
            'sources' => DB::table('inventory_transactions')->count(),
            'sequences' => DB::table('inventory_valuation_sequences')->count(),
            'outbox' => DB::table('outbox_messages')->count(),
            'ledger' => DB::table('cost_ledger_entries')->count(),
            'avco_updated_at' => DB::table('cost_avco_states')->value('updated_at'),
        ];
    }
}
