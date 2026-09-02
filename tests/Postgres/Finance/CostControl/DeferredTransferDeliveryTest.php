<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Services\DeferredCostDeliveryConsumer;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryResult;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Tests\Postgres\Finance\CostControl\Support\DeferredCostDeliveryFixture;
use Tests\PostgresTestCase;

class DeferredTransferDeliveryTest extends PostgresTestCase
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

    public function test_transfer_pair_is_classified_and_applied_as_one_atomic_unit(): void
    {
        $pair = $this->makeTransferPair();

        $first = $this->consumer->consume($pair['inbound_outbox']->id);

        $this->assertSame(DeferredCostDeliveryResult::DELIVERED, $first->status, $first->code);
        $this->assertSame(2, CostLedgerEntry::whereIn('source_inventory_transaction_id', [
            $pair['outbound']->id,
            $pair['inbound']->id,
        ])->count());
        $this->assertSame('8.0000', (string) $this->state($this->location)->on_hand_quantity);
        $this->assertSame('60.0000', (string) $this->state($this->location)->carrying_value);
        $this->assertSame('2.0000', (string) $this->state($this->partnerLocation)->on_hand_quantity);
        $this->assertSame('15.0000', (string) $this->state($this->partnerLocation)->carrying_value);
        $this->assertSame('7.5000', (string) $this->state($this->partnerLocation)->weighted_average_unit_cost);
        $this->assertSame(1, $this->state($this->location)->last_valuation_sequence);
        $this->assertSame(1, $this->state($this->partnerLocation)->last_valuation_sequence);

        $dispositions = CostDeliveryOutboxDisposition::whereIn('outbox_message_id', [
            $pair['outbound_outbox']->id,
            $pair['inbound_outbox']->id,
        ])->get();
        $this->assertCount(2, $dispositions);
        foreach ($dispositions as $disposition) {
            $this->assertSame(CostDeliveryProcessingState::Delivered, $disposition->processing_state);
            $this->assertSame(1, $disposition->attempt_count);
        }
        $this->assertSame(OutboxStatusEnum::Delivered, $pair['outbound_outbox']->fresh()->status);
        $this->assertSame(OutboxStatusEnum::Delivered, $pair['inbound_outbox']->fresh()->status);

        $retry = $this->consumer->consume($pair['outbound_outbox']->id);
        $this->assertSame(DeferredCostDeliveryResult::ALREADY_DELIVERED, $retry->status);
        $this->assertSame(2, CostLedgerEntry::whereIn('source_inventory_transaction_id', [
            $pair['outbound']->id,
            $pair['inbound']->id,
        ])->count());
    }

    public function test_transfer_with_one_sequence_gap_blocks_both_legs_without_monetary_mutation(): void
    {
        $pair = $this->makeTransferPair(1, 2);

        $result = $this->consumer->consume($pair['outbound_outbox']->id);

        $this->assertSame('BLOCKED_SEQUENCE', $result->code);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertNull($this->state($this->partnerLocation)->last_valuation_sequence);
        $dispositions = CostDeliveryOutboxDisposition::whereIn('outbox_message_id', [
            $pair['outbound_outbox']->id,
            $pair['inbound_outbox']->id,
        ])->get();
        $this->assertCount(2, $dispositions);
        foreach ($dispositions as $disposition) {
            $this->assertSame(CostDeliveryProcessingState::BlockedSequence, $disposition->processing_state);
            $this->assertSame(1, $disposition->expected_sequence);
        }
        $this->assertSame(OutboxStatusEnum::Pending, $pair['outbound_outbox']->fresh()->status);
        $this->assertSame(OutboxStatusEnum::Pending, $pair['inbound_outbox']->fresh()->status);
    }

    public function test_missing_transfer_partner_is_a_durable_outbox_failure_without_fabricated_disposition(): void
    {
        $source = $this->makeDeferredSource(TransactionTypeEnum::TransferOut, [
            'source_document_type' => 'inventory_transfer',
            'source_line_type' => 'inventory_transfer_line',
        ]);
        $outbox = $this->makeOutbox($source);

        $result = $this->consumer->consume($outbox->id);

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertSame('TRANSFER_PAIR_EVIDENCE_INCOMPLETE', $result->code);
        $this->assertSame(OutboxStatusEnum::Failed, $outbox->fresh()->status);
        $this->assertSame('TRANSFER_PAIR_EVIDENCE_INCOMPLETE', $outbox->fresh()->last_error);
        $this->assertFalse(CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)->exists());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
    }

    public function test_conflicting_transfer_pair_evidence_writes_no_partial_disposition(): void
    {
        $pair = $this->makeTransferPair();
        $this->rawUpdate('inventory_transactions', $pair['inbound']->id, [
            'total_cost' => '16.0000',
        ]);

        $result = $this->consumer->consume($pair['outbound_outbox']->id);

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertSame('TRANSFER_PAIR_EVIDENCE_CONFLICT', $result->code);
        $this->assertFalse(CostDeliveryOutboxDisposition::whereIn('outbox_message_id', [
            $pair['outbound_outbox']->id,
            $pair['inbound_outbox']->id,
        ])->exists());
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertNull($this->state($this->partnerLocation)->last_valuation_sequence);
        $this->assertSame(OutboxStatusEnum::Failed, $pair['outbound_outbox']->fresh()->status);
        $this->assertSame(OutboxStatusEnum::Pending, $pair['inbound_outbox']->fresh()->status);
    }

    public function test_one_preexisting_transfer_ledger_leg_fails_closed_without_repair(): void
    {
        $pair = $this->makeTransferPair();
        $this->makeExactTransferLedger($pair['outbound']);
        $state = $this->state($this->location);
        $state->last_valuation_sequence = 1;
        $state->last_valuation_business_date = '2026-08-25';
        $state->save();

        $result = $this->consumer->consume($pair['inbound_outbox']->id);

        $this->assertSame(DeferredCostDeliveryResult::REJECTED, $result->status);
        $this->assertSame('TRANSFER_PARTIAL_MONETARY_EFFECT_CONTRADICTION', $result->code);
        $this->assertSame(1, CostLedgerEntry::whereIn('source_inventory_transaction_id', [
            $pair['outbound']->id,
            $pair['inbound']->id,
        ])->count());
        $this->assertNull($this->state($this->partnerLocation)->last_valuation_sequence);
        $this->assertSame(OutboxStatusEnum::Pending, $pair['outbound_outbox']->fresh()->status);
        $this->assertSame(OutboxStatusEnum::Pending, $pair['inbound_outbox']->fresh()->status);
    }

    public function test_transfer_crash_after_first_ledger_leg_rolls_back_both_legs_and_states(): void
    {
        $pair = $this->makeTransferPair();
        $inboundId = $pair['inbound']->id;
        DB::unprepared("CREATE OR REPLACE FUNCTION cc_p01e_transfer_crash() RETURNS trigger AS $$
            BEGIN
                IF NEW.source_inventory_transaction_id = '{$inboundId}' THEN
                    RAISE EXCEPTION 'CC_P01E_TEST_TRANSFER_CRASH';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER cc_p01e_transfer_crash BEFORE INSERT ON cost_ledger_entries
            FOR EACH ROW EXECUTE FUNCTION cc_p01e_transfer_crash();");

        try {
            $result = $this->consumer->consume($pair['outbound_outbox']->id);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS cc_p01e_transfer_crash ON cost_ledger_entries;
                DROP FUNCTION IF EXISTS cc_p01e_transfer_crash();');
        }

        $this->assertSame(DeferredCostDeliveryResult::FAILED, $result->status);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertNull($this->state($this->location)->last_valuation_sequence);
        $this->assertNull($this->state($this->partnerLocation)->last_valuation_sequence);
        $dispositions = CostDeliveryOutboxDisposition::whereIn('outbox_message_id', [
            $pair['outbound_outbox']->id,
            $pair['inbound_outbox']->id,
        ])->get();
        foreach ($dispositions as $disposition) {
            $this->assertSame(CostDeliveryProcessingState::Failed, $disposition->processing_state);
        }
        $this->assertSame(OutboxStatusEnum::Failed, $pair['outbound_outbox']->fresh()->status);
        $this->assertSame(OutboxStatusEnum::Failed, $pair['inbound_outbox']->fresh()->status);
    }

    private function makeExactTransferLedger(InventoryTransaction $source): CostLedgerEntry
    {
        return CostLedgerEntry::create([
            'property_id' => $source->property_id,
            'source_inventory_transaction_id' => $source->id,
            'prior_cost_ledger_entry_id' => null,
            'entry_type' => 'transfer',
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
}
