<?php

namespace {
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\DB;
    use Modules\Finance\CostControl\Services\CostDeliveryHistoricalDispositionService;

    if (($argv[1] ?? null) === '--cc-p01b-worker') {
        $configPath = $argv[2] ?? '';
        $config = is_file($configPath)
            ? json_decode((string) file_get_contents($configPath), true)
            : null;

        if (! is_array($config)) {
            exit(2);
        }

        require $config['base_path'].'/vendor/autoload.php';
        $app = require $config['base_path'].'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        config(['database.connections.pgsql.database' => $config['database']]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $result = [
            'pid' => getmypid(),
            'pg_backend_pid' => null,
            'outcome' => 'ERROR',
            'disposition_id' => null,
            'message' => null,
        ];

        try {
            $result['pg_backend_pid'] = (int) DB::scalar('SELECT pg_backend_pid()');
            touch($config['barrier_dir'].'/ready-'.$config['worker']);

            $deadline = microtime(true) + 30;
            while (! is_file($config['barrier_dir'].'/start') && microtime(true) < $deadline) {
                usleep(10_000);
            }

            touch($config['barrier_dir'].'/classifying-'.$config['worker']);
            $disposition = app(
                CostDeliveryHistoricalDispositionService::class
            )->classify($config['outbox_id'], $config['actor_id']);

            $result['outcome'] = 'SUCCESS';
            $result['disposition_id'] = $disposition->id;
        } catch (Throwable $exception) {
            $result['message'] = $exception::class.': '.$exception->getMessage();
        }

        file_put_contents(
            $config['barrier_dir'].'/result-'.$config['worker'].'.json',
            json_encode($result, JSON_PRETTY_PRINT),
            LOCK_EX,
        );

        DB::disconnect('pgsql');
        exit($result['outcome'] === 'SUCCESS' ? 0 : 1);
    }
}

namespace Tests\Postgres\Finance\CostControl {

    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;
    use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
    use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
    use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
    use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
    use Modules\Finance\CostControl\Models\CostLedgerEntry;
    use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
    use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
    use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
    use Modules\Finance\CostControl\Services\CostDeliveryHistoricalDispositionService;
    use Modules\Finance\CostControl\Services\CostDeliveryObservabilityProjection;
    use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
    use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
    use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
    use Modules\Foundation\Outbox\Models\OutboxMessage;
    use Modules\Foundation\Outbox\Repositories\OutboxRepository;
    use Modules\Foundation\Property\Models\Property;
    use Modules\Foundation\User\Models\User;
    use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
    use Modules\Operations\Inventory\Models\InventoryCategory;
    use Modules\Operations\Inventory\Models\InventoryItem;
    use Modules\Operations\Inventory\Models\InventoryLocation;
    use Modules\Operations\Inventory\Models\InventoryTransaction;
    use Modules\Operations\Inventory\Models\InventoryUnit;
    use PDO;
    use RuntimeException;
    use Symfony\Component\Process\Process;
    use Tests\PostgresTestCase;

    class CostDeliveryHistoricalDispositionTest extends PostgresTestCase
    {
        use RefreshDatabase;

        protected $seed = true;

        private Property $property;

        private User $actor;

        private InventoryItem $item;

        private InventoryLocation $location;

        private InventoryUnit $unit;

        private CostDeliveryHistoricalDispositionService $service;

        protected function setUp(): void
        {
            parent::setUp();

            $this->property = Property::where('currency', 'USD')->firstOrFail();
            $this->actor = User::firstOrFail();
            $category = InventoryCategory::firstOrCreate([
                'property_id' => $this->property->id,
                'name' => 'CC-P01B Historical Disposition',
            ]);
            $this->item = InventoryItem::create([
                'property_id' => $this->property->id,
                'category_id' => $category->id,
                'sku' => 'CCP01B-'.Str::upper(Str::random(10)),
                'name' => 'CC-P01B Item',
                'inventory_type' => 'goods',
                'weighted_average_cost' => 0,
                'is_active' => true,
            ]);
            $this->location = InventoryLocation::create([
                'property_id' => $this->property->id,
                'name' => 'CC-P01B Location '.Str::random(8),
                'type' => 'internal',
            ]);
            $this->unit = InventoryUnit::create([
                'property_id' => $this->property->id,
                'code' => 'B'.Str::upper(Str::random(6)),
                'name' => 'CC-P01B Unit',
            ]);
            $this->service = app(CostDeliveryHistoricalDispositionService::class);
        }

        public function test_migration_creates_exact_control_constraints_and_indexes(): void
        {
            $this->assertTrue(Schema::hasTable('cost_delivery_outbox_dispositions'));
            $this->assertTrue(Schema::hasColumns('cost_delivery_outbox_dispositions', [
                'outbox_message_id',
                'source_inventory_transaction_id',
                'property_id',
                'location_id',
                'item_id',
                'valuation_scope',
                'valuation_sequence',
                'classification',
                'processing_state',
                'cost_delivery_ownership_id',
                'cost_delivery_ownership_version',
                'cost_delivery_cutover_id',
                'equivalent_cost_ledger_entry_id',
                'classified_by',
                'classification_provenance',
                'classified_at',
                'attempt_count',
                'last_attempted_at',
                'last_failure_code',
                'is_recoverable',
                'expected_sequence',
                'historical_excluded_at',
                'delivered_at',
            ]));

            $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'cost_delivery_outbox_dispositions'"))
                ->pluck('indexname');
            $this->assertContains('uk_cdod_outbox_message', $indexes);
            $this->assertContains('uk_cdod_source_transaction', $indexes);
            $this->assertContains('idx_cdod_property_item_state', $indexes);
            $this->assertContains('idx_cdod_property_scope_sequence', $indexes);
            $this->assertContains('idx_cdod_active_future_work', $indexes);

            $foreignTargets = collect(DB::select(<<<'SQL'
                SELECT confrelid::regclass::text AS target
                FROM pg_constraint
                WHERE conrelid = 'cost_delivery_outbox_dispositions'::regclass
                  AND contype = 'f'
                SQL))->pluck('target');
            $this->assertNotContains('outbox_messages', $foreignTargets);
            $this->assertNotContains('inventory_transactions', $foreignTargets);
            $this->assertContains('cost_ledger_entries', $foreignTargets);
            $this->assertSame([
                'SYNCHRONOUSLY_SATISFIED_HISTORY',
                'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY',
                'DEFERRED_OWNED_AFTER_CUTOVER',
            ], array_column(CostDeliveryDispositionClass::cases(), 'value'));
            $this->assertSame([
                'HISTORICAL_EXCLUDED', 'PENDING', 'DELIVERED', 'FAILED', 'BLOCKED_SEQUENCE',
            ], array_column(CostDeliveryProcessingState::cases(), 'value'));
        }

        public function test_database_enforces_one_disposition_per_outbox(): void
        {
            $first = $this->rawHistoricalAttributes();
            DB::table('cost_delivery_outbox_dispositions')->insert($first);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->insert($this->rawHistoricalAttributes([
                'outbox_message_id' => $first['outbox_message_id'],
            ]));
        }

        public function test_database_enforces_one_disposition_per_inventory_source(): void
        {
            $first = $this->rawHistoricalAttributes();
            DB::table('cost_delivery_outbox_dispositions')->insert($first);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->insert($this->rawHistoricalAttributes([
                'source_inventory_transaction_id' => $first['source_inventory_transaction_id'],
            ]));
        }

        public function test_exact_cost_ledger_evidence_classifies_synchronously_satisfied_history(): void
        {
            [$source, $outbox] = $this->makeExactSynchronousFixture();
            $entry = CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->firstOrFail();
            $this->assertSame($source->property_id, $entry->property_id);
            $this->assertSame($source->id, $entry->source_inventory_transaction_id);
            $this->assertSame('receipt', $entry->entry_type);
            $this->assertSame($source->idempotency_key, $entry->idempotency_key);
            $this->assertSame($source->valuation_sequence, $entry->entry_sequence);
            $this->assertSame($source->currency_code, $entry->currency_code);
            $this->assertSame(0, bccomp((string) $entry->quantity_delta, (string) $source->quantity_change, 4));
            $this->assertSame(0, bccomp((string) $entry->unit_cost, (string) $source->unit_cost, 4));
            $this->assertSame(0, bccomp((string) $entry->value_delta, (string) $source->total_cost, 4));
            $this->assertSame($source->business_date->format('Y-m-d'), $entry->business_date->format('Y-m-d'));
            $this->assertSame(
                $source->occurred_at->format('Y-m-d H:i:s'),
                $entry->occurred_at->format('Y-m-d H:i:s'),
            );
            $this->assertNull($entry->prior_cost_ledger_entry_id);
            $this->assertNull($source->corrects_inventory_transaction_id);
            $this->assertNull($source->reverses_inventory_transaction_id);
            $this->assertNull($entry->original_business_date);

            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->assertSame(CostDeliveryDispositionClass::SynchronouslySatisfiedHistory, $disposition->classification);
            $this->assertSame(CostDeliveryProcessingState::HistoricalExcluded, $disposition->processing_state);
            $this->assertSame($source->id, $disposition->source_inventory_transaction_id);
            $this->assertNotNull($disposition->equivalent_cost_ledger_entry_id);
            $this->assertNotNull($disposition->historical_excluded_at);
        }

        public function test_pending_foundation_outbox_remains_pending_after_classification(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $beforeAttempts = $outbox->attempts;
            $beforeLastError = $outbox->last_error;
            $beforePayload = $outbox->getRawOriginal('payload');

            $this->service->classify($outbox->id, $this->actor->id);

            $fresh = $outbox->fresh();
            $this->assertSame(OutboxStatusEnum::Pending, $fresh->status);
            $this->assertSame($beforeAttempts, $fresh->attempts);
            $this->assertSame($beforeLastError, $fresh->last_error);
            $this->assertNull($fresh->delivered_at);
            $this->assertSame($beforePayload, $fresh->getRawOriginal('payload'));
        }

        public function test_failed_foundation_outbox_remains_failed_after_classification(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            app(OutboxRepository::class)->markFailed($outbox->id, 'historical transport failure');

            $this->service->classify($outbox->id, $this->actor->id);

            $fresh = $outbox->fresh();
            $this->assertSame(OutboxStatusEnum::Failed, $fresh->status);
            $this->assertSame('historical transport failure', $fresh->last_error);
            $this->assertSame(0, $fresh->attempts);
            $this->assertNull($fresh->delivered_at);
        }

        public function test_historical_classification_never_fabricates_foundation_delivery_history(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();

            $this->service->classify($outbox->id, $this->actor->id);

            $this->assertDatabaseMissing('outbox_messages', [
                'id' => $outbox->id,
                'status' => OutboxStatusEnum::Delivered->value,
            ]);
            $this->assertNull($outbox->fresh()->delivered_at);
        }

        public function test_exact_repeated_classification_returns_existing_disposition(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();

            $first = $this->service->classify($outbox->id, $this->actor->id);
            $second = $this->service->classify($outbox->id, $this->actor->id);

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->count());
            $this->assertSame($first->classified_at->getTimestamp(), $second->classified_at->getTimestamp());
        }

        public function test_repeated_classification_with_conflicting_provenance_fails_closed(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $this->service->classify($outbox->id, $this->actor->id);
            $otherActor = User::create([
                'name' => 'CC-P01B Other Classifier',
                'email' => 'cc-p01b-'.Str::lower(Str::random(10)).'@example.test',
                'password' => 'password',
                'is_active' => true,
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('CC_P01B_EXISTING_DISPOSITION_CONFLICT');
            $this->service->classify($outbox->id, $otherActor->id);
        }

        public function test_unknown_classification_actor_fails_closed_without_disposition(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();

            try {
                $this->service->classify($outbox->id, (string) Str::ulid());
                $this->fail('An unknown classification actor must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('CC_P01B_CLASSIFICATION_ACTOR_NOT_FOUND', $exception->getMessage());
            }

            $this->assertFalse(CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->exists());
        }

        public function test_synchronous_stamped_source_with_missing_ledger_remains_unclassified(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::PurchaseReceipt, [
                'cost_delivery_mode' => 'SYNCHRONOUS',
                'cost_delivery_ownership_id' => (string) Str::ulid(),
                'cost_delivery_ownership_version' => 1,
            ]);
            $outbox = $this->makeOutbox($source);

            $this->assertClassificationFailure($outbox, 'CC_P01B_AMBIGUOUS_HISTORICAL_ELIGIBILITY');
        }

        public function test_eligible_source_with_mismatched_cost_ledger_remains_unclassified(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::Issue);
            $outbox = $this->makeOutbox($source);
            $this->makeLedger($source, ['value_delta' => '-999.0000']);

            $this->assertClassificationFailure($outbox, 'CC_P01B_AMBIGUOUS_COST_LEDGER_EQUIVALENCE');
        }

        public function test_source_proven_noneligible_transaction_classifies_historical_excluded(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::OpeningBalance);
            $outbox = $this->makeOutbox($source);

            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->assertSame(
                CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory,
                $disposition->classification,
            );
            $this->assertSame(CostDeliveryProcessingState::HistoricalExcluded, $disposition->processing_state);
            $this->assertNull($disposition->equivalent_cost_ledger_entry_id);
        }

        public function test_supported_null_stamped_source_without_ledger_is_not_guessed_unenrolled(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::PurchaseReceipt);
            $outbox = $this->makeOutbox($source);

            $this->assertClassificationFailure($outbox, 'CC_P01B_AMBIGUOUS_HISTORICAL_ELIGIBILITY');
        }

        public function test_wrong_outbox_topic_fails_closed(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::OpeningBalance);
            $outbox = $this->makeOutbox($source, ['topic' => 'inventory.transaction.adjusted']);

            $this->assertClassificationFailure($outbox, 'CC_P01B_OUTBOX_TOPIC_INVALID');
        }

        public function test_missing_inventory_source_fails_closed(): void
        {
            $sourceId = (string) Str::ulid();
            $outbox = $this->makeOutboxForSourceId($sourceId);

            $this->assertClassificationFailure($outbox, 'CC_P01B_INVENTORY_SOURCE_NOT_FOUND');
        }

        public function test_outbox_source_identity_convention_mismatch_fails_closed(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::OpeningBalance);
            $outbox = $this->makeOutbox($source, ['idempotency_key' => 'wrong-source-identity']);

            $this->assertClassificationFailure($outbox, 'CC_P01B_OUTBOX_SOURCE_IDENTITY_MISMATCH');
        }

        public function test_payload_source_identity_mismatch_fails_closed(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::OpeningBalance);
            $outbox = $this->makeOutbox($source, [
                'payload' => ['transactionId' => (string) Str::ulid()],
            ]);

            $this->assertClassificationFailure($outbox, 'CC_P01B_OUTBOX_PAYLOAD_SOURCE_MISMATCH');
        }

        public function test_cross_property_item_and_location_evidence_mismatch_fails_closed(): void
        {
            $otherProperty = Property::create([
                'company_id' => $this->property->company_id,
                'name' => 'CC-P01B Other Property',
                'slug' => 'cc-p01b-other-'.Str::lower(Str::random(8)),
                'code' => 'B'.Str::upper(Str::random(8)),
                'currency' => 'USD',
                'timezone' => 'UTC',
                'is_active' => true,
            ]);
            $source = $this->makeSource(TransactionTypeEnum::OpeningBalance, [
                'property_id' => $otherProperty->id,
                'valuation_scope' => "property:{$otherProperty->id}:location:{$this->location->id}:item:{$this->item->id}",
            ]);
            $outbox = $this->makeOutbox($source);

            $this->assertClassificationFailure($outbox, 'CC_P01B_INVENTORY_SOURCE_PROPERTY_MISMATCH');
        }

        public function test_redundant_property_item_and_location_facts_are_immutable(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->update([
                'property_id' => (string) Str::ulid(),
                'item_id' => (string) Str::ulid(),
                'location_id' => (string) Str::ulid(),
                'updated_at' => now()->addSecond(),
            ]);
        }

        public function test_valuation_scope_and_sequence_facts_are_immutable(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->update([
                'valuation_scope' => 'property:wrong:location:wrong:item:wrong',
                'valuation_sequence' => 99,
                'updated_at' => now()->addSecond(),
            ]);
        }

        public function test_classification_is_immutable(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->update([
                'classification' => CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory->value,
                'updated_at' => now()->addSecond(),
            ]);
        }

        public function test_classification_actor_and_provenance_are_immutable(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->update([
                'classified_by' => (string) Str::ulid(),
                'classification_provenance' => 'REWRITTEN',
                'updated_at' => now()->addSecond(),
            ]);
        }

        public function test_historical_excluded_is_terminal(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->update([
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'historical_excluded_at' => null,
                'updated_at' => now()->addSecond(),
            ]);
        }

        public function test_delivered_is_terminal_at_persistence_contract_level(): void
        {
            $evidence = $this->makeDeferredEvidence();
            $id = $this->insertDeferredPendingDisposition($evidence);
            $attemptedAt = now()->addSecond();
            DB::table('cost_delivery_outbox_dispositions')->where('id', $id)->update([
                'processing_state' => CostDeliveryProcessingState::Delivered->value,
                'attempt_count' => 1,
                'last_attempted_at' => $attemptedAt,
                'delivered_at' => $attemptedAt,
                'updated_at' => $attemptedAt,
            ]);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $id)->update([
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'delivered_at' => null,
                'updated_at' => now()->addSeconds(2),
            ]);
        }

        public function test_disposition_delete_is_prohibited(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->where('id', $disposition->id)->delete();
        }

        public function test_deferred_classification_requires_ownership_and_cutover_evidence(): void
        {
            $attributes = $this->rawHistoricalAttributes([
                'classification' => CostDeliveryDispositionClass::DeferredOwnedAfterCutover->value,
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'historical_excluded_at' => null,
            ]);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->insert($attributes);
        }

        public function test_historical_classification_cannot_begin_in_pending_state(): void
        {
            $attributes = $this->rawHistoricalAttributes([
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'historical_excluded_at' => null,
            ]);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->insert($attributes);
        }

        public function test_processing_lifecycle_constraints_reject_invalid_failure_shape(): void
        {
            $attributes = $this->rawHistoricalAttributes([
                'attempt_count' => 1,
                'last_attempted_at' => now(),
                'last_failure_code' => 'FABRICATED_FAILURE',
                'is_recoverable' => true,
            ]);

            $this->expectException(QueryException::class);
            DB::table('cost_delivery_outbox_dispositions')->insert($attributes);
        }

        public function test_observability_projection_returns_safe_read_only_arrays_without_mutation(): void
        {
            [, $outbox] = $this->makeExactSynchronousFixture();
            $disposition = $this->service->classify($outbox->id, $this->actor->id);
            $beforeDisposition = $disposition->fresh()->getRawOriginal();
            $beforeOutbox = $outbox->fresh()->getRawOriginal();

            $projection = app(CostDeliveryObservabilityProjection::class);
            $rows = $projection->forProperty($this->property->id, $this->item->id);
            $counts = $projection->countsByProcessingState($this->property->id);

            $this->assertCount(1, $rows);
            $this->assertIsArray($rows->first());
            $this->assertSame($disposition->id, $rows->first()['id']);
            $this->assertSame(1, $counts[CostDeliveryProcessingState::HistoricalExcluded->value]);
            $this->assertSame($beforeDisposition, $disposition->fresh()->getRawOriginal());
            $this->assertSame($beforeOutbox, $outbox->fresh()->getRawOriginal());
            $this->assertDatabaseCount('cost_ledger_entries', 1);
        }

        public function test_inventory_stock_movement_is_never_resolved_as_classification_source(): void
        {
            $movementId = (string) Str::ulid();
            DB::table('inventory_stock_movements')->insert([
                'id' => $movementId,
                'property_id' => $this->property->id,
                'inventory_item_id' => $this->item->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'movement_type' => 'GOODS_RECEIPT',
                'direction' => 'IN',
                'source_leg' => 'PRIMARY',
                'quantity' => '1.000',
                'source_domain' => 'inventory',
                'source_type' => 'InventoryStockMovementTest',
                'source_id' => (string) Str::ulid(),
                'correlation_id' => (string) Str::ulid(),
                'idempotency_key' => (string) Str::ulid(),
                'occurred_at' => now(),
                'created_by' => $this->actor->id,
                'created_at' => now(),
            ]);
            $outbox = $this->makeOutboxForSourceId($movementId);

            $this->assertClassificationFailure($outbox, 'CC_P01B_INVENTORY_SOURCE_NOT_FOUND');
        }

        public function test_historical_classifier_never_creates_deferred_class(): void
        {
            $source = $this->makeSource(TransactionTypeEnum::PurchaseReceipt, [
                'cost_delivery_mode' => 'DEFERRED',
                'cost_delivery_ownership_id' => (string) Str::ulid(),
                'cost_delivery_ownership_version' => 2,
                'cost_delivery_cutover_id' => (string) Str::ulid(),
            ]);
            $outbox = $this->makeOutbox($source);

            $this->assertClassificationFailure($outbox, 'CC_P01B_HISTORICAL_CLASSIFIER_DEFERRED_PROHIBITED');
            $this->assertDatabaseMissing('cost_delivery_outbox_dispositions', [
                'classification' => CostDeliveryDispositionClass::DeferredOwnedAfterCutover->value,
            ]);
        }

        public function test_two_postgresql_contexts_create_one_durable_exact_disposition(): void
        {
            $database = 'ivorq_concurrency_ccp01b_'.Str::lower(Str::random(8));
            $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
            mkdir($barrier, 0700, true);

            $connection = config('database.connections.pgsql');
            $host = (string) ($connection['host'] ?? '127.0.0.1');
            $port = (string) ($connection['port'] ?? '5432');
            $username = (string) ($connection['username'] ?? '');
            $password = (string) ($connection['password'] ?? '');
            $admin = new PDO(
                "pgsql:host={$host};port={$port};dbname=postgres",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $quotedDatabase = '"'.str_replace('"', '""', $database).'"';
            $processes = [];

            try {
                $admin->exec("CREATE DATABASE {$quotedDatabase}");

                $migration = new Process(
                    [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
                    base_path(),
                    ['APP_ENV' => 'testing', 'DB_DATABASE' => $database],
                );
                $migration->setTimeout(180);
                $migration->mustRun();

                $pdo = new PDO(
                    "pgsql:host={$host};port={$port};dbname={$database}",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $fixture = $this->seedConcurrencyFixture($pdo);

                foreach (['A', 'B'] as $worker) {
                    $configPath = $barrier.DIRECTORY_SEPARATOR."worker-{$worker}.json";
                    file_put_contents($configPath, json_encode([
                        'base_path' => base_path(),
                        'database' => $database,
                        'barrier_dir' => $barrier,
                        'worker' => $worker,
                        'outbox_id' => $fixture['outbox_id'],
                        'actor_id' => $fixture['actor_id'],
                    ], JSON_PRETTY_PRINT));
                    $processes[$worker] = new Process([
                        PHP_BINARY,
                        __FILE__,
                        '--cc-p01b-worker',
                        $configPath,
                    ], base_path());
                    $processes[$worker]->setTimeout(60);
                    $processes[$worker]->start();
                }

                $this->waitForBarrier($barrier, 'ready-A');
                $this->waitForBarrier($barrier, 'ready-B');

                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT id FROM outbox_messages WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $fixture['outbox_id']]);
                touch($barrier.DIRECTORY_SEPARATOR.'start');
                $this->waitForBarrier($barrier, 'classifying-A');
                $this->waitForBarrier($barrier, 'classifying-B');
                usleep(200_000);
                $pdo->commit();

                foreach ($processes as $process) {
                    $process->wait();
                }

                $resultA = json_decode((string) file_get_contents($barrier.'/result-A.json'), true);
                $resultB = json_decode((string) file_get_contents($barrier.'/result-B.json'), true);
                $this->assertSame('SUCCESS', $resultA['outcome'], $resultA['message'] ?? 'worker A failed');
                $this->assertSame('SUCCESS', $resultB['outcome'], $resultB['message'] ?? 'worker B failed');
                $this->assertNotSame($resultA['pid'], $resultB['pid']);
                $this->assertNotSame($resultA['pg_backend_pid'], $resultB['pg_backend_pid']);
                $this->assertSame($resultA['disposition_id'], $resultB['disposition_id']);
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cost_delivery_outbox_dispositions')->fetchColumn());
                $outbox = $pdo->query('SELECT status, attempts, delivered_at, last_error FROM outbox_messages')->fetch(PDO::FETCH_ASSOC);
                $this->assertSame('pending', $outbox['status']);
                $this->assertSame(0, (int) $outbox['attempts']);
                $this->assertNull($outbox['delivered_at']);
                $this->assertNull($outbox['last_error']);
                $pdo = null;
            } finally {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop(1);
                    }
                }
                $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()');
                $terminate->execute(['database' => $database]);
                $admin->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
                $admin = null;
                $this->removeTemporaryDirectory($barrier);
            }
        }

        private function makeExactSynchronousFixture(): array
        {
            $source = $this->makeSource(TransactionTypeEnum::PurchaseReceipt);
            $outbox = $this->makeOutbox($source);
            $this->makeLedger($source);

            return [$source, $outbox];
        }

        private function makeSource(
            TransactionTypeEnum $type,
            array $overrides = [],
        ): InventoryTransaction {
            $id = (string) Str::ulid();
            $quantityChange = match ($type) {
                TransactionTypeEnum::Issue,
                TransactionTypeEnum::TransferOut,
                TransactionTypeEnum::AdjustmentOut => '-2.0000',
                default => '2.0000',
            };
            $entryType = match ($type) {
                TransactionTypeEnum::PurchaseReceipt => ['inventory_receipt', 'inventory_receipt_line'],
                TransactionTypeEnum::Issue => ['inventory_issue', 'inventory_issue_line'],
                TransactionTypeEnum::TransferIn, TransactionTypeEnum::TransferOut => ['inventory_transfer', 'inventory_transfer_line'],
                TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => ['inventory_adjustment', 'inventory_adjustment_line'],
                default => ['inventory_opening_balance', 'inventory_opening_balance_line'],
            };
            $scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
            $occurredAt = now()->startOfSecond();

            $source = InventoryTransaction::create(array_merge([
                'id' => $id,
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'location_id' => $this->location->id,
                'currency_code' => 'USD',
                'valuation_scope' => $scope,
                'valuation_sequence' => 1,
                'valuation_approval_status' => 'approved',
                'valuation_approval_reference' => 'CC-P01B-APPROVED',
                'business_date' => '2026-08-20',
                'occurred_at' => $occurredAt,
                'source_document_type' => $entryType[0],
                'source_document_id' => (string) Str::ulid(),
                'source_line_type' => $entryType[1],
                'source_line_id' => (string) Str::ulid(),
                'movement_role' => $type->value,
                'idempotency_key' => 'ccp01b-'.$id,
                'transaction_type' => $type,
                'quantity_before' => '10.0000',
                'quantity_change' => $quantityChange,
                'quantity_after' => bcadd('10.0000', $quantityChange, 4),
                'unit_cost' => '7.5000',
                'total_cost' => bcmul($quantityChange, '7.5000', 4),
                'posted_by' => $this->actor->id,
                'posted_at' => $occurredAt,
            ], $overrides));

            return $source->fresh();
        }

        private function makeOutbox(
            InventoryTransaction $source,
            array $overrides = [],
        ): OutboxMessage {
            return app(OutboxRepository::class)->createPending(array_merge([
                'topic' => 'inventory.transaction.posted',
                'source_inventory_transaction_id' => $source->id,
                'payload' => ['transactionId' => $source->id],
                'idempotency_key' => "inventory_transaction:{$source->id}:cost_ledger",
            ], $overrides));
        }

        private function makeOutboxForSourceId(string $sourceId): OutboxMessage
        {
            return app(OutboxRepository::class)->createPending([
                'topic' => 'inventory.transaction.posted',
                'source_inventory_transaction_id' => $sourceId,
                'payload' => ['transactionId' => $sourceId],
                'idempotency_key' => "inventory_transaction:{$sourceId}:cost_ledger",
            ]);
        }

        private function makeLedger(
            InventoryTransaction $source,
            array $overrides = [],
        ): CostLedgerEntry {
            $entryType = match ($source->transaction_type) {
                TransactionTypeEnum::PurchaseReceipt => 'receipt',
                TransactionTypeEnum::Issue => 'issue',
                TransactionTypeEnum::TransferIn, TransactionTypeEnum::TransferOut => 'transfer',
                TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
                TransactionTypeEnum::Reversal => 'reversal',
                default => 'receipt',
            };

            return CostLedgerEntry::create(array_merge([
                'property_id' => $source->property_id,
                'source_inventory_transaction_id' => $source->id,
                'prior_cost_ledger_entry_id' => null,
                'entry_type' => $entryType,
                'idempotency_key' => $source->idempotency_key,
                'entry_sequence' => $source->valuation_sequence,
                'currency_code' => $source->currency_code,
                'quantity_delta' => $source->quantity_change,
                'unit_cost' => $source->unit_cost,
                'value_delta' => $source->total_cost,
                'business_date' => $source->business_date,
                'occurred_at' => $source->occurred_at->format('Y-m-d H:i:s'),
            ], $overrides));
        }

        private function assertClassificationFailure(OutboxMessage $outbox, string $code): void
        {
            try {
                $this->service->classify($outbox->id, $this->actor->id);
                $this->fail("Expected controlled classification failure {$code}.");
            } catch (RuntimeException $exception) {
                $this->assertSame($code, $exception->getMessage());
            }

            $this->assertFalse(CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->exists());
        }

        private function rawHistoricalAttributes(array $overrides = []): array
        {
            $timestamp = now();

            return array_merge([
                'id' => (string) Str::ulid(),
                'outbox_message_id' => (string) Str::ulid(),
                'source_inventory_transaction_id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'location_id' => $this->location->id,
                'item_id' => $this->item->id,
                'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
                'valuation_sequence' => 1,
                'classification' => CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory->value,
                'processing_state' => CostDeliveryProcessingState::HistoricalExcluded->value,
                'classified_by' => $this->actor->id,
                'classification_provenance' => 'SOURCE_TRANSACTION_TYPE_NOT_COSTCONTROL_ELIGIBLE',
                'classified_at' => $timestamp,
                'attempt_count' => 0,
                'historical_excluded_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $overrides);
        }

        private function makeDeferredEvidence(): array
        {
            $period = FinancialPeriod::updateOrCreate(
                ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 8],
                ['status' => FinancialPeriodStatusEnum::Open],
            );
            $scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
            $repository = app(CostAuthorityEnrollmentRepository::class);
            $group = $repository->createDraft(
                ['property_id' => $this->property->id, 'item_id' => $this->item->id],
                [[
                    'location_id' => $this->location->id,
                    'valuation_scope' => $scope,
                    'opening_quantity' => '0.0000',
                    'opening_carrying_value' => '0.0000',
                    'currency_code' => 'USD',
                    'business_date' => '2026-08-01',
                    'financial_period_id' => $period->id,
                    'source_reference' => 'CC-P01B-TEST-ONLY',
                    'evidence_timestamp' => now(),
                ]],
            );
            DB::transaction(fn () => $repository->approve($group->id, $this->actor->id, now()));
            app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $this->actor->id);
            $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $this->actor->id);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            CostDeliveryPilotProperty::create([
                'pilot_slot' => 1,
                'property_id' => $this->property->id,
                'owner_approval_reference' => 'CC-P01B-TEST-ONLY',
                'authorized_by' => $this->actor->id,
                'authorized_at' => now(),
            ]);
            $snapshot = DB::table('cost_authority_enrollment_scope_snapshots')
                ->where('enrollment_group_id', $group->id)->first();
            $cutoverId = (string) Str::ulid();

            DB::transaction(function () use ($ownership, $group, $period, $snapshot, $cutoverId): void {
                DB::table('cost_delivery_cutovers')->insert([
                    'id' => $cutoverId,
                    'ownership_id' => $ownership->id,
                    'enrollment_group_id' => $group->id,
                    'property_id' => $this->property->id,
                    'item_id' => $this->item->id,
                    'financial_period_id' => $period->id,
                    'boundary_business_date' => '2026-08-31',
                    'owner_approval_reference' => 'CC-P01B-TEST-ONLY',
                    'requested_by' => $this->actor->id,
                    'requested_at' => now()->subMinutes(2),
                    'approved_by' => $this->actor->id,
                    'approved_at' => now()->subMinute(),
                    'activated_by' => $this->actor->id,
                    'activated_at' => now(),
                    'created_at' => now(),
                ]);
                DB::table('cost_delivery_cutover_scopes')->insert([
                    'id' => (string) Str::ulid(),
                    'cutover_id' => $cutoverId,
                    'enrollment_scope_snapshot_id' => $snapshot->id,
                    'property_id' => $this->property->id,
                    'location_id' => $this->location->id,
                    'item_id' => $this->item->id,
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
                DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)->update([
                    'delivery_mode' => 'DEFERRED',
                    'ownership_version' => 2,
                    'activated_cutover_id' => $cutoverId,
                    'changed_by' => $this->actor->id,
                    'changed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            });

            return [
                'ownership_id' => $ownership->id,
                'ownership_version' => 2,
                'cutover_id' => $cutoverId,
            ];
        }

        private function insertDeferredPendingDisposition(array $evidence): string
        {
            $id = (string) Str::ulid();
            $timestamp = now();
            DB::table('cost_delivery_outbox_dispositions')->insert([
                'id' => $id,
                'outbox_message_id' => (string) Str::ulid(),
                'source_inventory_transaction_id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'location_id' => $this->location->id,
                'item_id' => $this->item->id,
                'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
                'valuation_sequence' => 1,
                'classification' => CostDeliveryDispositionClass::DeferredOwnedAfterCutover->value,
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'cost_delivery_ownership_id' => $evidence['ownership_id'],
                'cost_delivery_ownership_version' => $evidence['ownership_version'],
                'cost_delivery_cutover_id' => $evidence['cutover_id'],
                'classified_by' => $this->actor->id,
                'classification_provenance' => 'DEFERRED_SOURCE_CUTOVER_WATERMARK',
                'classified_at' => $timestamp,
                'attempt_count' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return $id;
        }

        private function seedConcurrencyFixture(PDO $pdo): array
        {
            $now = '2026-08-25 00:00:00';
            $companyId = (string) Str::ulid();
            $propertyId = (string) Str::ulid();
            $categoryId = (string) Str::ulid();
            $itemId = (string) Str::ulid();
            $locationId = (string) Str::ulid();
            $sourceId = (string) Str::ulid();
            $outboxId = (string) Str::ulid();
            $ledgerId = (string) Str::ulid();
            $actorId = (string) Str::ulid();

            $this->pdoInsert($pdo, 'companies', [
                'id' => $companyId, 'name' => 'CC-P01B Concurrency', 'slug' => 'ccp01b-concurrency',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'properties', [
                'id' => $propertyId, 'company_id' => $companyId, 'name' => 'CC-P01B Property',
                'slug' => 'ccp01b-property', 'code' => 'CCP01B', 'timezone' => 'UTC',
                'currency' => 'USD', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_categories', [
                'id' => $categoryId, 'property_id' => $propertyId, 'name' => 'CC-P01B Category',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_items', [
                'id' => $itemId, 'property_id' => $propertyId, 'category_id' => $categoryId,
                'sku' => 'CCP01B-ITEM', 'name' => 'CC-P01B Item', 'inventory_type' => 'goods',
                'criticality' => 'low', 'is_batch_tracked' => 0, 'is_expiry_tracked' => 0,
                'weighted_average_cost' => '0.00', 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'inventory_locations', [
                'id' => $locationId, 'property_id' => $propertyId, 'name' => 'CC-P01B Location',
                'type' => 'internal', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'users', [
                'id' => $actorId, 'is_system_admin' => 0, 'name' => 'CC-P01B Classifier',
                'email' => 'ccp01b-classifier@example.test', 'password' => 'not-used',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $scope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
            $this->pdoInsert($pdo, 'inventory_transactions', [
                'id' => $sourceId, 'property_id' => $propertyId, 'item_id' => $itemId,
                'location_id' => $locationId, 'transaction_type' => 'purchase_receipt',
                'quantity_before' => '0.0000', 'quantity_change' => '2.0000', 'quantity_after' => '2.0000',
                'unit_cost' => '7.50', 'total_cost' => '15.00', 'posted_at' => $now,
                'business_date' => '2026-08-25', 'occurred_at' => $now,
                'source_document_type' => 'inventory_receipt', 'source_document_id' => (string) Str::ulid(),
                'source_line_type' => 'inventory_receipt_line', 'source_line_id' => (string) Str::ulid(),
                'movement_role' => 'purchase_receipt', 'idempotency_key' => 'ccp01b-'.$sourceId,
                'currency_code' => 'USD', 'valuation_scope' => $scope, 'valuation_sequence' => 1,
                'valuation_approval_status' => 'approved',
                'valuation_approval_reference' => 'CC-P01B-CONCURRENCY', 'created_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'outbox_messages', [
                'id' => $outboxId, 'topic' => 'inventory.transaction.posted',
                'source_inventory_transaction_id' => $sourceId,
                'payload' => json_encode(['transactionId' => $sourceId]),
                'idempotency_key' => "inventory_transaction:{$sourceId}:cost_ledger",
                'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->pdoInsert($pdo, 'cost_ledger_entries', [
                'id' => $ledgerId, 'property_id' => $propertyId,
                'source_inventory_transaction_id' => $sourceId, 'entry_type' => 'receipt',
                'idempotency_key' => 'ccp01b-'.$sourceId, 'entry_sequence' => 1,
                'currency_code' => 'USD', 'quantity_delta' => '2.0000', 'unit_cost' => '7.5000',
                'value_delta' => '15.0000', 'business_date' => '2026-08-25',
                'occurred_at' => '2026-08-25 00:00:00', 'created_at' => $now,
            ]);

            return ['outbox_id' => $outboxId, 'actor_id' => $actorId];
        }

        private function pdoInsert(PDO $pdo, string $table, array $attributes): void
        {
            $columns = array_keys($attributes);
            $sql = "INSERT INTO {$table} (".implode(', ', $columns).') VALUES ('.
                implode(', ', array_map(fn (string $column): string => ':'.$column, $columns)).')';
            $statement = $pdo->prepare($sql);
            $statement->execute($attributes);
        }

        private function waitForBarrier(string $directory, string $file): void
        {
            $deadline = microtime(true) + 30;
            while (! is_file($directory.DIRECTORY_SEPARATOR.$file) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                throw new RuntimeException("Concurrency barrier timed out: {$file}");
            }
        }

        private function removeTemporaryDirectory(string $directory): void
        {
            $resolved = realpath($directory);
            $temporaryRoot = realpath(sys_get_temp_dir());
            if ($resolved === false
                || $temporaryRoot === false
                || ! str_starts_with($resolved, $temporaryRoot.DIRECTORY_SEPARATOR.'ivorq_concurrency_ccp01b_')) {
                return;
            }

            foreach (glob($resolved.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($resolved);
        }
    }
}
