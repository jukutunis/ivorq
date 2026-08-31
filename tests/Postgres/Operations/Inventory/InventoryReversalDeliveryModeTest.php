<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Exceptions\InventoryReversalCandidateRejectedException;
use Modules\Operations\Inventory\Exceptions\InventoryReversalPostingRejectedException;
use Modules\Operations\Inventory\Services\InventoryReversalPostingService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;

class InventoryReversalDeliveryModeTest extends InventoryReversalPostingServiceTest
{
    public function test_not_enrolled_reversal_fails_before_sequence_or_business_effects(): void
    {
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);

        try {
            $this->service->post($this->intent($original->id, 'p01d-not-enrolled'));
            $this->fail('Expected a not-enrolled reversal to fail closed.');
        } catch (InventoryReversalPostingRejectedException $exception) {
            $this->assertSame('not_enrolled', $exception->getReason());
        }

        $this->assertSame(1, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertSame('10.0000', (string) DB::table('inventory_stocks')->value('physical_quantity'));
    }

    public function test_enrolled_scope_missing_ownership_fails_closed_before_effects(): void
    {
        $groupId = $this->seedGroup();
        $this->seedSnapshot($groupId);
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);

        try {
            $this->service->post($this->intent($original->id, 'p01d-missing-ownership'));
            $this->fail('Expected enrolled ownership invariant failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ENROLLED_DELIVERY_OWNERSHIP_MISSING', $exception->getMessage());
        }

        $this->assertSame(1, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_synchronous_reversal_is_stamped_and_exact_retry_survives_later_cutover(): void
    {
        [$groupId, $snapshotId, $ownershipId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $intent = $this->intent($original->id, 'p01d-sync-retry');

        $first = $this->service->post($intent);
        $stockAfterFirst = (string) DB::table('inventory_stocks')->value('physical_quantity');
        $auditCountAfterFirst = DB::table('audit_logs')
            ->where('auditable_id', $first->reversalTransaction->id)
            ->count();
        $this->assertSame('SYNCHRONOUS', $first->reversalTransaction->cost_delivery_mode);
        $this->assertSame($ownershipId, $first->reversalTransaction->cost_delivery_ownership_id);
        $this->assertSame(1, $first->reversalTransaction->cost_delivery_ownership_version);
        $this->assertNull($first->reversalTransaction->cost_delivery_cutover_id);
        $this->assertNotNull($first->costLedgerEntryId);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame('5.0000', (string) DB::table('cost_avco_states')->value('on_hand_quantity'));
        $this->assertSame(2, (int) DB::table('cost_avco_states')->value('last_valuation_sequence'));

        $this->activateDeferred($groupId, $snapshotId, $ownershipId, 2);
        $retry = $this->service->post($intent);

        $this->assertTrue($retry->replayed);
        $this->assertSame($first->reversalTransaction->id, $retry->reversalTransaction->id);
        $this->assertSame('SYNCHRONOUS', $retry->reversalTransaction->cost_delivery_mode);
        $this->assertNull($retry->reversalTransaction->cost_delivery_cutover_id);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(2, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        $this->assertSame($stockAfterFirst, (string) DB::table('inventory_stocks')->value('physical_quantity'));
        $this->assertSame(2, DB::table('inventory_transactions')->count());
        $this->assertSame($auditCountAfterFirst, DB::table('audit_logs')
            ->where('auditable_id', $first->reversalTransaction->id)
            ->count());
    }

    public function test_deferred_reversal_creates_one_exact_pending_outbox_without_cost_effect(): void
    {
        [$groupId, $snapshotId, $ownershipId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $cutoverId = $this->activateDeferred($groupId, $snapshotId, $ownershipId, 1);

        $probe = new class implements SynchronousCostValuationPort
        {
            public int $calls = 0;

            public function applyReversal(
                string $reversalInventoryTransactionId,
                string $originalInventoryTransactionId,
                string $reversalReason,
                string $approvalReference,
            ): string {
                $this->calls++;

                return 'unexpected';
            }
        };
        app()->instance(SynchronousCostValuationPort::class, $probe);
        $service = app(InventoryReversalPostingService::class);

        $result = $service->post($this->intent($original->id, 'p01d-deferred'));
        $reversal = $result->reversalTransaction;

        $this->assertSame('DEFERRED', $reversal->cost_delivery_mode);
        $this->assertSame($ownershipId, $reversal->cost_delivery_ownership_id);
        $this->assertSame(2, $reversal->cost_delivery_ownership_version);
        $this->assertSame($cutoverId, $reversal->cost_delivery_cutover_id);
        $this->assertSame(2, $reversal->valuation_sequence);
        $this->assertNull($result->costLedgerEntryId);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('cost_delivery_outbox_dispositions', 0);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $reversal->id,
            'idempotency_key' => "inventory_transaction:{$reversal->id}:cost_ledger",
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $payload = json_decode((string) DB::table('outbox_messages')->value('payload'), true);
        $this->assertSame(['transactionId' => $reversal->id], $payload);
        $this->assertSame('5.0000', (string) DB::table('inventory_stocks')->value('physical_quantity'));
        $this->assertSame('10.0000', (string) DB::table('cost_avco_states')->value('on_hand_quantity'));
        $this->assertSame(1, (int) DB::table('cost_avco_states')->value('last_valuation_sequence'));
        $this->assertSame(0, $probe->calls);
    }

    public function test_second_reversal_with_different_idempotency_key_is_rejected_without_new_effect(): void
    {
        [$groupId, $snapshotId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $this->service->post($this->intent($original->id, 'p01d-first'));

        $this->expectException(InventoryReversalCandidateRejectedException::class);

        try {
            $this->service->post($this->intent($original->id, 'p01d-second'));
        } finally {
            $this->assertSame(2, DB::table('inventory_transactions')->count());
            $this->assertDatabaseCount('cost_ledger_entries', 1);
            $this->assertDatabaseCount('outbox_messages', 0);
            $this->assertSame(2, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        }
    }

    public function test_same_idempotency_with_conflicting_reversal_evidence_fails_closed(): void
    {
        [$groupId, $snapshotId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $first = $this->intent($original->id, 'p01d-conflicting-evidence');
        $this->service->post($first);
        $conflict = new InventoryReversalPostingIntent(
            originalTransactionId: $original->id,
            idempotencyKey: $first->idempotencyKey,
            actorId: $this->user->id,
            approvalReference: 'OWNER-CC-P01D-CONFLICT',
            reversalReason: $first->reversalReason,
        );

        try {
            $this->service->post($conflict);
            $this->fail('Expected conflicting immutable reversal evidence to fail closed.');
        } catch (InventoryReversalPostingRejectedException $exception) {
            $this->assertSame('CC_P01D_EXISTING_REVERSAL_SOURCE_CONFLICT', $exception->getReason());
        }

        $this->assertSame(2, DB::table('inventory_transactions')->count());
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertSame(2, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        $this->assertSame('5.0000', (string) DB::table('inventory_stocks')->value('physical_quantity'));
    }

    public function test_negative_physical_stock_reversal_rolls_back_sequence_and_all_effects(): void
    {
        [$groupId, $snapshotId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '2.0000', '20.0000', 1, now()->toDateString());
        $this->seedStock('2.0000');
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);

        try {
            $this->service->post($this->intent($original->id, 'p01d-negative-stock'));
            $this->fail('Expected negative stock guard to reject reversal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertSame(1, (int) DB::table('inventory_valuation_sequences')->value('last_sequence'));
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame('2.0000', (string) DB::table('inventory_stocks')->value('physical_quantity'));
    }

    public function test_synchronous_adapter_rejects_null_stamped_source(): void
    {
        [$groupId, $snapshotId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $adapter = app(SynchronousCostValuationPort::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CC_P01D_SYNCHRONOUS_SOURCE_STAMP_INVALID');
        DB::transaction(fn () => $adapter->applyReversal(
            $original->id,
            $original->id,
            'Invalid null stamp proof',
            'OWNER-CC-P01D-TEST',
        ));
    }

    public function test_synchronous_adapter_rejects_deferred_source(): void
    {
        [$groupId, $snapshotId, $ownershipId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $this->activateDeferred($groupId, $snapshotId, $ownershipId, 1);
        $reversal = $this->service->post($this->intent($original->id, 'p01d-deferred-adapter'))
            ->reversalTransaction;
        $adapter = app(SynchronousCostValuationPort::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CC_P01D_SYNCHRONOUS_SOURCE_STAMP_INVALID');
        DB::transaction(fn () => $adapter->applyReversal(
            $reversal->id,
            $original->id,
            'CC-P01D mode-safe reversal proof',
            'OWNER-CC-P01D-TEST',
        ));
    }

    public function test_synchronous_adapter_revalidates_current_ownership_and_version(): void
    {
        [$groupId, $snapshotId, $ownershipId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $reversal = $this->service->post($this->intent($original->id, 'p01d-sync-old-owner'))
            ->reversalTransaction;
        $this->activateDeferred($groupId, $snapshotId, $ownershipId, 2);
        $adapter = app(SynchronousCostValuationPort::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CC_P01D_SYNCHRONOUS_SOURCE_STAMP_INVALID');
        DB::transaction(fn () => $adapter->applyReversal(
            $reversal->id,
            $original->id,
            'CC-P01D mode-safe reversal proof',
            'OWNER-CC-P01D-TEST',
        ));
    }

    public function test_synchronous_adapter_reuses_exact_cost_ledger_effect_without_second_avco_transition(): void
    {
        [$groupId, $snapshotId] = $this->seedOwnedScope();
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock();
        $this->seedValuationSequence(1);
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);
        $intent = $this->intent($original->id, 'p01d-ledger-equivalence');
        $first = $this->service->post($intent);
        $adapter = app(SynchronousCostValuationPort::class);

        $entryId = DB::transaction(fn (): string => $adapter->applyReversal(
            $first->reversalTransaction->id,
            $original->id,
            $intent->reversalReason,
            $intent->approvalReference,
        ));

        $this->assertSame($first->costLedgerEntryId, $entryId);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertSame('5.0000', (string) DB::table('cost_avco_states')->value('on_hand_quantity'));
        $this->assertSame(2, (int) DB::table('cost_avco_states')->value('last_valuation_sequence'));
    }

    public function test_concurrent_same_reversal_and_posting_cutover_are_serialized(): void
    {
        $result = $this->runConcurrencyProof();
        $this->assertNull($result['error'] ?? null, json_encode($result));
        $this->assertTrue($result['db_created'] ?? false);
        $this->assertTrue($result['migrations_ok'] ?? false);
        $this->assertTrue($result['db_dropped'] ?? false, $result['drop_error'] ?? 'database not dropped');

        $same = $result['same_reversal'];
        $sameWorkers = array_values($same['workers']);
        $this->assertNotSame($sameWorkers[0]['pid'], $sameWorkers[1]['pid']);
        $this->assertNotSame($sameWorkers[0]['pg_pid'], $sameWorkers[1]['pg_pid']);
        $this->assertEqualsCanonicalizing(['POSTED', 'REPLAYED'], array_column($sameWorkers, 'outcome'));
        $this->assertCount(1, array_unique(array_column($sameWorkers, 'source_id')));
        $this->assertSame(1, $same['durable_sources']);
        $this->assertSame(1, $same['ledger_effects']);
        $this->assertSame(0, $same['outbox_effects']);
        $this->assertSame(2, $same['last_sequence']);
        $this->assertSame('5.0000', $same['physical_quantity']);

        $race = $result['posting_cutover'];
        $this->assertSame('POSTED', $race['workers']['race-post']['outcome'], json_encode($race));
        $this->assertSame('CUTOVER', $race['workers']['race-cutover']['outcome'], json_encode($race));
        $this->assertSame(1, $race['durable_sources']);
        $this->assertSame(2, $race['last_sequence']);
        $this->assertSame('5.0000', $race['physical_quantity']);
        $this->assertContains($race['mode'], ['SYNCHRONOUS', 'DEFERRED']);

        if ($race['mode'] === 'SYNCHRONOUS') {
            $this->assertSame(1, $race['ownership_version']);
            $this->assertNull($race['cutover_id']);
            $this->assertSame(1, $race['ledger_effects']);
            $this->assertSame(0, $race['outbox_effects']);
            $this->assertSame(2, $race['avco_sequence']);
        } else {
            $this->assertSame(2, $race['ownership_version']);
            $this->assertNotNull($race['cutover_id']);
            $this->assertSame(0, $race['ledger_effects']);
            $this->assertSame(1, $race['outbox_effects']);
            $this->assertSame(1, $race['avco_sequence']);
        }
    }

    /** @return array{string, string, string} */
    private function seedOwnedScope(): array
    {
        $groupId = $this->seedGroup();
        $snapshotId = $this->seedSnapshot($groupId);
        $ownershipId = $this->seedSynchronousOwnership($groupId);

        return [$groupId, $snapshotId, $ownershipId];
    }

    private function intent(string $originalId, string $key): InventoryReversalPostingIntent
    {
        return new InventoryReversalPostingIntent(
            originalTransactionId: $originalId,
            idempotencyKey: $key,
            actorId: $this->user->id,
            approvalReference: 'OWNER-CC-P01D-TEST',
            reversalReason: 'CC-P01D mode-safe reversal proof',
        );
    }

    private function activateDeferred(
        string $groupId,
        string $snapshotId,
        string $ownershipId,
        int $lastSynchronousSequence,
    ): string {
        $cutoverId = (string) Str::ulid();
        $allocatorId = (string) DB::table('inventory_valuation_sequences')->value('id');

        DB::transaction(function () use (
            $groupId,
            $snapshotId,
            $ownershipId,
            $lastSynchronousSequence,
            $allocatorId,
            $cutoverId,
        ): void {
            // Flush the enrollment's deferred initial-ownership proof while the
            // ownership is still the canonical SYNCHRONOUS version 1 row.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            DB::table('cost_delivery_pilot_properties')->insert([
                'id' => (string) Str::ulid(),
                'pilot_slot' => 1,
                'property_id' => $this->property->id,
                'owner_approval_reference' => 'OWNER-CC-P01D-TEST',
                'authorized_by' => $this->user->id,
                'authorized_at' => now(),
                'created_at' => now(),
            ]);
            DB::table('cost_delivery_cutovers')->insert([
                'id' => $cutoverId,
                'ownership_id' => $ownershipId,
                'enrollment_group_id' => $groupId,
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'financial_period_id' => $this->period->id,
                'boundary_business_date' => now()->toDateString(),
                'owner_approval_reference' => 'OWNER-CC-P01D-TEST',
                'requested_by' => $this->user->id,
                'requested_at' => now()->subMinutes(2),
                'approved_by' => $this->user->id,
                'approved_at' => now()->subMinute(),
                'activated_by' => $this->user->id,
                'activated_at' => now(),
                'created_at' => now(),
            ]);
            DB::table('cost_delivery_cutover_scopes')->insert([
                'id' => (string) Str::ulid(),
                'cutover_id' => $cutoverId,
                'enrollment_scope_snapshot_id' => $snapshotId,
                'property_id' => $this->property->id,
                'location_id' => $this->location->id,
                'item_id' => $this->item->id,
                'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
                'inventory_sequence_source' => 'ALLOCATOR_ROW',
                'inventory_valuation_sequence_id' => $allocatorId,
                'inventory_allocator_last_sequence' => $lastSynchronousSequence,
                'cost_avco_last_valuation_sequence' => $lastSynchronousSequence,
                'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => $lastSynchronousSequence,
                'first_deferred_owned_sequence' => $lastSynchronousSequence + 1,
                'created_at' => now(),
            ]);
            DB::table('cost_delivery_mode_ownerships')->where('id', $ownershipId)->update([
                'delivery_mode' => 'DEFERRED',
                'ownership_version' => 2,
                'activated_cutover_id' => $cutoverId,
                'changed_by' => $this->user->id,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });

        return $cutoverId;
    }

    private function runConcurrencyProof(): array
    {
        $runId = strtolower(Str::random(8));
        $database = 'ivorq_concurrency_p01d_'.$runId;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-p01d-'.$runId;
        mkdir($directory, 0700, true);

        try {
            $pgsql = config('database.connections.pgsql');
            $resultFile = $directory.DIRECTORY_SEPARATOR.'coordinator-result.json';
            $configFile = $directory.DIRECTORY_SEPARATOR.'coordinator-config.json';
            file_put_contents($configFile, json_encode([
                'db_name' => $database,
                'barrier_dir' => $directory,
                'base_path' => base_path(),
                'db_host' => $pgsql['host'] ?? '127.0.0.1',
                'db_port' => (string) ($pgsql['port'] ?? '5432'),
                'db_user' => $pgsql['username'],
                'db_pass' => $pgsql['password'],
                'result_file' => $resultFile,
            ], JSON_PRETTY_PRINT));

            $coordinator = base_path('tests/Postgres/Operations/Inventory/Support/InventoryReversalConcurrencyCoordinator.php');
            $process = proc_open(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($coordinator).' '.escapeshellarg($configFile),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            if (! is_resource($process)) {
                return ['error' => 'CC_P01D_CONCURRENCY_COORDINATOR_START_FAILED'];
            }
            fclose($pipes[0]);

            $until = time() + 300;
            while (time() < $until && ! is_file($resultFile)) {
                usleep(100000);
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (! is_file($resultFile)) {
                return ['error' => 'CC_P01D_CONCURRENCY_TIMEOUT '.$stdout.' '.$stderr];
            }

            return json_decode((string) file_get_contents($resultFile), true)
                ?: ['error' => 'CC_P01D_CONCURRENCY_RESULT_INVALID'];
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($directory);
        }
    }
}
