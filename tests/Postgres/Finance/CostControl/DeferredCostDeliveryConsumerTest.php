<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Services\DeferredCostDeliveryConsumer;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryResult;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\ValueObjects\InventoryAdjustmentIdempotencyKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Postgres\Finance\CostControl\Support\DeferredCostDeliveryFixture;
use Tests\PostgresTestCase;

class DeferredCostDeliveryConsumerTest extends PostgresTestCase
{
    use DeferredCostDeliveryFixture;
    use RefreshDatabase;

    protected $seed = true;

    private DeferredCostDeliveryConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeferredFixture('10.0000', '75.0000');
        $this->consumer = app(DeferredCostDeliveryConsumer::class);
    }

    public function test_pending_outbox_alone_does_not_classify_a_null_stamped_source(): void
    {
        $source = $this->makeDeferredSource(overrides: [
            'cost_delivery_mode' => null,
            'cost_delivery_ownership_id' => null,
            'cost_delivery_ownership_version' => null,
            'cost_delivery_cutover_id' => null,
        ]);
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::REJECTED, $result->status);
        $this->assertFalse(CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->exists());
        $this->assertSame(OutboxStatusEnum::Pending, $outbox->fresh()->status);
    }

    public function test_synchronous_source_cannot_get_a_deferred_disposition(): void
    {
        $source = $this->makeDeferredSource(overrides: [
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_cutover_id' => null,
        ]);
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::REJECTED, $result->status);
        $this->assertFalse(CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->exists());
    }

    public function test_receipt_is_classified_and_applied_atomically_then_replay_is_a_no_op(): void
    {
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);

        $first = $this->consumer->consume($outbox->id);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $first->status);
        $this->assertSame(CostDeliveryProcessingState::Delivered, $disposition->processing_state);
        $this->assertSame('DEFERRED_SOURCE_STAMP_AND_CUTOVER_PROOF', $disposition->classification_provenance);
        $this->assertSame($this->actor->id, $disposition->classified_by);
        $this->assertSame(1, $disposition->attempt_count);
        $this->assertSame(OutboxStatusEnum::Delivered, $outbox->fresh()->status);
        $this->assertSame(1, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
        $this->assertSame(1, $this->state($this->location)->last_valuation_sequence);
        $this->assertSame('12.0000', (string) $this->state($this->location)->on_hand_quantity);
        $this->assertSame('90.0000', (string) $this->state($this->location)->carrying_value);

        $second = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::ALREADY_DELIVERED, $second->status);
        $this->assertSame(1, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
        $this->assertSame(1, $disposition->fresh()->attempt_count);
    }

    #[DataProvider('deferredAdjustmentProvider')]
    public function test_adjustment_producer_and_deferred_consumer_share_the_exact_bounded_key(
        string $actualQuantity,
        string $variance,
        TransactionTypeEnum $transactionType,
        string $expectedQuantity,
        string $expectedValue,
    ): void {
        FinancialPeriod::updateOrCreate(
            [
                'property_id' => $this->property->id,
                'period_year' => now()->year,
                'period_month' => now()->month,
            ],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => ItemStatusEnum::InStock,
        ]);
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'CCP01F-ADJ-'.Str::random(8),
            'status' => AdjustmentStatusEnum::Submitted,
        ]);
        $line = InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => $actualQuantity,
            'quantity_variance' => $variance,
            'unit_cost' => '7.5000',
        ]);

        app(AdjustmentService::class)->approve($adjustment->id, $this->actor->id);

        $source = InventoryTransaction::query()
            ->where('source_document_id', $adjustment->id)
            ->firstOrFail();
        $outbox = OutboxMessage::query()
            ->where('source_inventory_transaction_id', $source->id)
            ->firstOrFail();
        $expectedKey = InventoryAdjustmentIdempotencyKey::approval($adjustment->id, $line->id);

        $this->assertSame('DEFERRED', $source->cost_delivery_mode);
        $this->assertSame($transactionType, $source->transaction_type);
        $this->assertSame($expectedKey, $source->idempotency_key);
        $this->assertSame(60, strlen($source->idempotency_key));
        $this->assertSame(0, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
        $this->assertSame('10.0000', (string) $this->state($this->location)->on_hand_quantity);
        $this->assertSame('75.0000', (string) $this->state($this->location)->carrying_value);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);

        $first = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $first->status, $first->code);
        $this->assertSame(
            $expectedKey,
            CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->value('idempotency_key')
        );
        $this->assertSame($expectedQuantity, (string) $this->state($this->location)->on_hand_quantity);
        $this->assertSame($expectedValue, (string) $this->state($this->location)->carrying_value);

        $second = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::ALREADY_DELIVERED, $second->status);
        $this->assertSame(1, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
    }

    public static function deferredAdjustmentProvider(): array
    {
        return [
            'AdjustmentIn' => ['12.0000', '2.0000', TransactionTypeEnum::AdjustmentIn, '12.0000', '90.0000'],
            'AdjustmentOut' => ['8.0000', '-2.0000', TransactionTypeEnum::AdjustmentOut, '8.0000', '60.0000'],
        ];
    }

    #[DataProvider('singleMovementProvider')]
    public function test_issue_and_adjustments_reuse_canonical_planners(
        string $type,
        string $quantity,
        string $total,
        string $expectedQuantity,
        string $expectedValue,
    ): void {
        $source = $this->makeDeferredSource(TransactionTypeEnum::from($type), [
            'quantity_change' => $quantity,
            'total_cost' => $total,
        ]);
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $result->status, $result->code);
        $this->assertSame($expectedQuantity, (string) $this->state($this->location)->on_hand_quantity);
        $this->assertSame($expectedValue, (string) $this->state($this->location)->carrying_value);
        $this->assertSame(1, $this->state($this->location)->last_valuation_sequence);
        $this->assertSame(1, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
    }

    public static function singleMovementProvider(): array
    {
        return [
            'issue' => ['issue', '-2.0000', '-15.0000', '8.0000', '60.0000'],
            'adjustment in' => ['adjustment_in', '2.0000', '15.0000', '12.0000', '90.0000'],
            'adjustment out' => ['adjustment_out', '-2.0000', '-15.0000', '8.0000', '60.0000'],
        ];
    }

    #[DataProvider('unsupportedMovementProvider')]
    public function test_return_and_opening_balance_are_durable_unsupported_failures(string $type): void
    {
        $source = $this->makeDeferredSource(TransactionTypeEnum::from($type));
        $outbox = $this->makeOutbox($source);

        $first = $this->consumer->consume($outbox->id);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $first->status);
        $this->assertSame(CostDeliveryProcessingState::Failed, $disposition->processing_state);
        $this->assertSame(OutboxStatusEnum::Failed, $outbox->fresh()->status);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);

        $second = $this->consumer->consume($outbox->id);
        $this->assertSame(DeferredCostDeliveryResult::RECOVERY_REQUIRED, $second->status);
        $this->assertSame(1, $disposition->fresh()->attempt_count);
    }

    public static function unsupportedMovementProvider(): array
    {
        return [
            'return' => ['return'],
            'opening balance' => ['opening_balance'],
        ];
    }

    public function test_sequence_gap_blocks_without_effect_then_explicit_recovery_applies_after_missing_sequence(): void
    {
        $source = $this->makeDeferredSource(overrides: ['valuation_sequence' => 2]);
        $outbox = $this->makeOutbox($source);

        $first = $this->consumer->consume($outbox->id);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $first->status);
        $this->assertSame('BLOCKED_SEQUENCE', $first->code);
        $this->assertSame(CostDeliveryProcessingState::BlockedSequence, $disposition->processing_state);
        $this->assertSame(1, $disposition->expected_sequence);
        $this->assertTrue($disposition->is_recoverable);
        $this->assertSame(OutboxStatusEnum::Pending, $outbox->fresh()->status);
        $this->assertDatabaseCount('cost_ledger_entries', 0);

        $second = $this->consumer->consume($outbox->id);
        $this->assertSame(DeferredCostDeliveryResult::RECOVERY_REQUIRED, $second->status);
        $this->assertSame(1, $disposition->fresh()->attempt_count);

        $sequenceOne = $this->makeDeferredSource();
        $sequenceOneOutbox = $this->makeOutbox($sequenceOne);
        $sequenceOneResult = $this->consumer->consume($sequenceOneOutbox->id);

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $sequenceOneResult->status, $sequenceOneResult->code);
        $this->assertSame(1, $this->state($this->location)->last_valuation_sequence);
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        DB::table('cost_delivery_outbox_dispositions')
            ->where('id', $disposition->id)
            ->update([
                'processing_state' => CostDeliveryProcessingState::Pending->value,
                'last_failure_code' => null,
                'is_recoverable' => null,
                'expected_sequence' => null,
                'updated_at' => now(),
            ]);
        $this->travel(1)->seconds();

        $sequenceTwoResult = $this->consumer->consume($outbox->id);
        $recoveredDisposition = $disposition->fresh();

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $sequenceTwoResult->status, $sequenceTwoResult->code);
        $this->assertSame(CostDeliveryProcessingState::Delivered, $recoveredDisposition->processing_state);
        $this->assertSame(2, $recoveredDisposition->attempt_count);
        $this->assertSame(OutboxStatusEnum::Delivered, $outbox->fresh()->status);
        $this->assertSame(2, $this->state($this->location)->last_valuation_sequence);
        $this->assertDatabaseCount('cost_ledger_entries', 2);
        $this->assertSame(1, CostLedgerEntry::where('source_inventory_transaction_id', $source->id)->count());
    }

    public function test_closed_business_date_and_closed_period_fail_without_monetary_effect(): void
    {
        $this->businessDate->update([
            'status' => PropertyBusinessDateStatusEnum::Closed,
            'is_open' => null,
            'closed_by' => $this->actor->id,
            'closed_at' => now(),
        ]);
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame('BUSINESS_DATE_CLOSED', $result->code);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);

        $this->businessDate->update([
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'closed_by' => null,
            'closed_at' => null,
        ]);
        $this->period->update([
            'status' => FinancialPeriodStatusEnum::Closed,
            'closed_by' => $this->actor->id,
            'closed_at' => now(),
        ]);
        $source2 = $this->makeDeferredSource(overrides: [
            'location_id' => $this->partnerLocation->id,
            'valuation_scope' => $this->scope($this->partnerLocation),
            'idempotency_key' => 'ccp01e-period-closed',
        ]);
        $outbox2 = $this->makeOutbox($source2);
        $result2 = $this->consumer->consume($outbox2->id);

        $this->assertSame('FINANCIAL_PERIOD_STATE_INELIGIBLE', $result2->code);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
    }

    public function test_conflicting_existing_disposition_fails_closed_without_monetary_effect(): void
    {
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);
        $disposition = $this->classifyManually($source, $outbox);
        $this->rawUpdate('cost_delivery_outbox_dispositions', $disposition->id, [
            'valuation_sequence' => 2,
        ]);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::REJECTED, $result->status);
        $this->assertSame('CC_P01E_EXISTING_DEFERRED_DISPOSITION_CONFLICT', $result->code);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertSame(OutboxStatusEnum::Pending, $outbox->fresh()->status);
    }

    public function test_reopened_period_remains_accepted(): void
    {
        $this->period->update(['status' => FinancialPeriodStatusEnum::Reopened]);
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $result->status, $result->code);
    }

    public function test_exact_ledger_with_unadvanced_avco_fails_closed_without_repair(): void
    {
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);
        $this->makeExactLedger($source);

        $result = $this->consumer->consume($outbox->id);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertSame('COST_LEDGER_AVCO_STATE_DIVERGENCE', $result->code);
        $this->assertSame(CostDeliveryProcessingState::Failed, $disposition->processing_state);
        $this->assertFalse($disposition->is_recoverable);
        $this->assertSame(1, $disposition->attempt_count);
        $this->assertSame('COST_LEDGER_AVCO_STATE_DIVERGENCE', $disposition->last_failure_code);
        $this->assertNull($disposition->expected_sequence);
        $this->assertNull($disposition->delivered_at);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertSame(OutboxStatusEnum::Failed, $outbox->fresh()->status);
        $this->assertSame('COST_LEDGER_AVCO_STATE_DIVERGENCE', $outbox->fresh()->last_error);

        $second = $this->consumer->consume($outbox->id);
        $this->assertSame(DeferredCostDeliveryResult::RECOVERY_REQUIRED, $second->status);
        $this->assertSame(1, $disposition->fresh()->attempt_count);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
    }

    public function test_exact_ledger_avco_divergence_precedes_sequence_blocking(): void
    {
        $source = $this->makeDeferredSource(overrides: ['valuation_sequence' => 2]);
        $outbox = $this->makeOutbox($source);
        $this->makeExactLedger($source);

        $result = $this->consumer->consume($outbox->id);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertSame('COST_LEDGER_AVCO_STATE_DIVERGENCE', $result->code);
        $this->assertNotSame('BLOCKED_SEQUENCE', $result->code);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertSame(CostDeliveryProcessingState::Failed, $disposition->processing_state);
        $this->assertFalse($disposition->is_recoverable);
        $this->assertSame(1, $disposition->attempt_count);
        $this->assertSame('COST_LEDGER_AVCO_STATE_DIVERGENCE', $disposition->last_failure_code);
        $this->assertSame(OutboxStatusEnum::Failed, $outbox->fresh()->status);

        $second = $this->consumer->consume($outbox->id);
        $this->assertSame(DeferredCostDeliveryResult::RECOVERY_REQUIRED, $second->status);
        $this->assertSame(1, $disposition->fresh()->attempt_count);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
    }

    public function test_deferred_reversal_uses_original_and_audit_provenance(): void
    {
        $original = $this->makeDeferredSource(overrides: [
            'valuation_sequence' => 99,
            'idempotency_key' => 'ccp01e-original',
        ]);
        $reversal = $this->makeDeferredSource(TransactionTypeEnum::Reversal, [
            'valuation_sequence' => 1,
            'reverses_inventory_transaction_id' => $original->id,
            'source_document_id' => $original->source_document_id,
            'source_line_id' => $original->source_line_id,
            'quantity_change' => '-2.0000',
            'unit_cost' => '7.5000',
            'total_cost' => '-15.0000',
            'valuation_approval_reference' => 'CC-P01E-REVERSAL-APPROVAL',
        ]);
        app(AuditService::class)->log('reversal', $reversal, [], [
            'original_transaction_id' => $original->id,
            'reversal_reason' => 'CC-P01E deferred reversal proof',
            'approval_reference' => 'CC-P01E-REVERSAL-APPROVAL',
            'actor_id' => $this->actor->id,
        ], ['reversal']);
        $outbox = $this->makeOutbox($reversal);

        $result = $this->consumer->consume($outbox->id);
        $entry = CostLedgerEntry::where('source_inventory_transaction_id', $reversal->id)->firstOrFail();

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $result->status, $result->code);
        $this->assertSame('reversal', $entry->entry_type);
        $this->assertSame($original->id, $entry->metadata['original_transaction_id']);
        $this->assertSame('CC-P01E deferred reversal proof', $entry->metadata['reversal_reason']);
        $this->assertSame($original->business_date->format('Y-m-d'), $entry->original_business_date->format('Y-m-d'));
    }

    #[DataProvider('rollbackStageProvider')]
    public function test_injected_crash_rolls_back_ledger_avco_and_delivery(string $stage): void
    {
        $source = $this->makeDeferredSource();
        $outbox = $this->makeOutbox($source);
        $this->installRollbackTrigger($stage);

        try {
            $result = $this->consumer->consume($outbox->id);
        } finally {
            $this->removeRollbackTrigger($stage);
        }

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $disposition = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->firstOrFail();
        $this->assertSame(CostDeliveryProcessingState::Failed, $disposition->processing_state);
        $this->assertSame(OutboxStatusEnum::Failed, $outbox->fresh()->status);
    }

    public static function rollbackStageProvider(): array
    {
        return [
            'after ledger append' => ['avco'],
            'after AVCO transition' => ['disposition'],
            'after monetary state before Outbox' => ['outbox'],
        ];
    }

    private function makeExactLedger(InventoryTransaction $source): CostLedgerEntry
    {
        return CostLedgerEntry::create([
            'property_id' => $source->property_id,
            'source_inventory_transaction_id' => $source->id,
            'prior_cost_ledger_entry_id' => null,
            'entry_type' => 'receipt',
            'idempotency_key' => $source->idempotency_key,
            'entry_sequence' => $source->valuation_sequence,
            'currency_code' => $source->currency_code,
            'quantity_delta' => $source->quantity_change,
            'unit_cost' => $source->unit_cost,
            'value_delta' => $source->total_cost,
            'business_date' => $source->business_date,
            'occurred_at' => $source->occurred_at,
        ]);
    }

    private function installRollbackTrigger(string $stage): void
    {
        $table = match ($stage) {
            'avco' => 'cost_avco_states',
            'disposition' => 'cost_delivery_outbox_dispositions',
            'outbox' => 'outbox_messages',
        };
        $condition = match ($stage) {
            'avco' => 'NEW.last_valuation_sequence IS NOT NULL',
            'disposition' => "NEW.processing_state = 'DELIVERED'",
            'outbox' => "NEW.status = 'delivered'",
        };
        DB::unprepared("CREATE OR REPLACE FUNCTION cc_p01e_crash_{$stage}() RETURNS trigger AS $$
            BEGIN
                IF {$condition} THEN RAISE EXCEPTION 'CC_P01E_TEST_CRASH'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER cc_p01e_crash_{$stage} BEFORE UPDATE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION cc_p01e_crash_{$stage}();");
    }

    private function removeRollbackTrigger(string $stage): void
    {
        $table = match ($stage) {
            'avco' => 'cost_avco_states',
            'disposition' => 'cost_delivery_outbox_dispositions',
            'outbox' => 'outbox_messages',
        };
        DB::unprepared("DROP TRIGGER IF EXISTS cc_p01e_crash_{$stage} ON {$table};
            DROP FUNCTION IF EXISTS cc_p01e_crash_{$stage}();");
    }
}
