<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\Services\CostLedgerPostingGuard;
use Modules\Finance\CostControl\Services\CostLedgerPostingPlanner;
use Modules\Finance\CostControl\Services\PairedTransferValuationService;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\Repositories\CostTransferPairResolutionRepository;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;

/**
 * Integration proof for Owner-approved Option B paired cross-scope transfer valuation.
 *
 * Proofs covered:
 *   P1 — Value balance: source relieved value equals destination received value (zero net P&L).
 *   P2 — Strict sequence barrier: a sequence gap on either leg prevents ALL mutations.
 *   P3 — Full source depletion: source AVCO resets to null WAUC; destination receives exact value.
 *   P4 — Frozen cost isolation: subsequent source AVCO changes do not corrupt destination cost.
 */
class PairedTransferValuationIntegrationTest extends PostgresTestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private PairedTransferValuationService $service;

    private string $propertyId;
    private string $itemId;
    private string $locationAId;
    private string $locationBId;
    private string $financialPeriodId;
    private string $sourceScope;
    private string $destScope;
    private string $businessDate;
    private string $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PairedTransferValuationService(
            new CostAvcoStateRepository(),
            new CostLedgerRepository(),
            new CostTransferPairResolutionRepository(),
            new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine()),
            new OutboxRepository(),
        );

        $company          = $this->createCompany();
        $property         = $this->createProperty($company);
        $this->propertyId = $property->id;

        $this->itemId            = (string) Str::ulid();
        $this->locationAId       = (string) Str::ulid();
        $this->locationBId       = (string) Str::ulid();
        $this->financialPeriodId = (string) Str::ulid();
        $this->businessDate      = '2026-06-27';
        $this->occurredAt        = '2026-06-27 09:00:00';

        $this->sourceScope = "property:{$this->propertyId}:location:{$this->locationAId}:item:{$this->itemId}";
        $this->destScope   = "property:{$this->propertyId}:location:{$this->locationBId}:item:{$this->itemId}";

        DB::table('property_business_dates')->insert([
            'id'            => (string) Str::ulid(),
            'property_id'   => $this->propertyId,
            'business_date' => $this->businessDate,
            'status'        => 'Open',
            'is_open'       => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('gl_financial_periods')->insert([
            'id'           => $this->financialPeriodId,
            'property_id'  => $this->propertyId,
            'period_year'  => 2026,
            'period_month' => 6,
            'status'       => 'Open',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPair(
        string $sourceSeq = '1',
        string $destSeq   = '1',
        string $qty       = '10',
    ): array {
        $docId        = (string) Str::ulid();
        $lineId       = (string) Str::ulid();
        $srcTxId      = (string) Str::ulid();
        $dstTxId      = (string) Str::ulid();
        $srcOutboxId  = (string) Str::ulid();
        $dstOutboxId  = (string) Str::ulid();

        $base = [
            'property_id'                 => $this->propertyId,
            'item_id'                     => $this->itemId,
            'unit_cost'                   => '50.00',
            'source_document_type'        => 'transfer',
            'source_document_id'          => $docId,
            'source_line_type'            => 'transfer_line',
            'source_line_id'              => $lineId,
            'movement_role'               => 'transfer',
            'business_date'               => $this->businessDate,
            'occurred_at'                 => $this->occurredAt,
            'currency_code'               => 'IDR',
            'financial_period_id'         => $this->financialPeriodId,
            'valuation_approval_status'   => 'approved',
            'valuation_approval_reference'=> 'EVD-INT-001',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ];

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $srcTxId,
            'location_id'         => $this->locationAId,
            'transaction_type'    => 'transfer_out',
            'quantity_change'     => "-{$qty}.0000",
            'idempotency_key'     => "transfer:out:{$srcTxId}",
            'valuation_scope'     => $this->sourceScope,
            'valuation_sequence'  => $sourceSeq,
        ]));

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $dstTxId,
            'location_id'         => $this->locationBId,
            'transaction_type'    => 'transfer_in',
            'quantity_change'     => "{$qty}.0000",
            'idempotency_key'     => "transfer:in:{$dstTxId}",
            'valuation_scope'     => $this->destScope,
            'valuation_sequence'  => $destSeq,
        ]));

        DB::table('outbox_messages')->insert([
            'id'                              => $srcOutboxId,
            'topic'                           => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $srcTxId,
            'payload'                         => json_encode(['event' => 'transfer_out']),
            'idempotency_key'                 => "outbox:transfer_out:{$srcTxId}",
            'status'                          => OutboxStatusEnum::Pending->value,
            'attempts'                        => 0,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        DB::table('outbox_messages')->insert([
            'id'                              => $dstOutboxId,
            'topic'                           => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $dstTxId,
            'payload'                         => json_encode(['event' => 'transfer_in']),
            'idempotency_key'                 => "outbox:transfer_in:{$dstTxId}",
            'status'                          => OutboxStatusEnum::Pending->value,
            'attempts'                        => 0,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        return compact('srcTxId', 'dstTxId', 'srcOutboxId', 'dstOutboxId', 'docId', 'lineId');
    }

    private function seedSourceAvco(
        string $qty      = '20.0000',
        string $wauc     = '50.0000',
        string $carrying = '1000.0000'
    ): void {
        DB::table('cost_avco_states')->insertOrIgnore([
            'id'                              => (string) Str::ulid(),
            'property_id'                     => $this->propertyId,
            'location_id'                     => $this->locationAId,
            'item_id'                         => $this->itemId,
            'valuation_scope'                 => $this->sourceScope,
            'on_hand_quantity'                => $qty,
            'carrying_value'                  => $carrying,
            'weighted_average_unit_cost'      => $wauc,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence'         => null,
            'last_valuation_business_date'    => null,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // P1 — Value balance
    // -------------------------------------------------------------------------

    /**
     * Source relieved value must exactly equal destination received value.
     * The paired transfer produces zero net accounting impact.
     */
    public function test_P1_source_relieved_value_equals_destination_received_value(): void
    {
        // Source: 20 units at WAUC 50 = carrying 1000
        // Transfer: 10 units
        // Expected source relief: -500 (10 × 50)
        // Expected destination receipt: +500 (10 × frozen source cost 50)
        $this->seedSourceAvco('20.0000', '50.0000', '1000.0000');

        $pair = $this->buildPair('1', '1', '10');

        $this->service->processOutboxMessage($pair['srcOutboxId']);

        $entries = DB::table('cost_ledger_entries')
            ->where('property_id', $this->propertyId)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $entries, 'Exactly two cost ledger entries must be created.');

        $valueDelta = $entries->sum(fn ($e) => (float) $e->value_delta);

        $this->assertEqualsWithDelta(0.0, $valueDelta, 0.0001,
            'Sum of source + destination value_delta must be zero (no net P&L).');

        $byScope = $entries->keyBy('source_inventory_transaction_id');
        $srcEntry = $byScope[$pair['srcTxId']] ?? null;
        $dstEntry = $byScope[$pair['dstTxId']] ?? null;

        $this->assertNotNull($srcEntry);
        $this->assertNotNull($dstEntry);
        $this->assertEqualsWithDelta(-500.0, (float) $srcEntry->value_delta, 0.0001,
            'Source ledger entry must relieve 500 (10 × 50).');
        $this->assertEqualsWithDelta(500.0, (float) $dstEntry->value_delta, 0.0001,
            'Destination ledger entry must receive 500 (10 × frozen source cost 50).');
    }

    // -------------------------------------------------------------------------
    // P2 — Strict sequence barrier
    // -------------------------------------------------------------------------

    /**
     * A sequence gap on the destination leg must prevent ALL AVCO state mutations
     * and cost ledger entries. The source AVCO must remain unchanged.
     */
    public function test_P2_strict_sequence_barrier_on_destination_gap_prevents_all_mutations(): void
    {
        // Source AVCO: fresh scope for this pair, sequence 1 is correct.
        // Destination TX: sequence 5 against an empty scope (expects 1) → gap → REJECTED.
        $this->seedSourceAvco('20.0000', '50.0000', '1000.0000');

        $pair = $this->buildPair('1', '5', '10');

        $this->service->processOutboxMessage($pair['srcOutboxId']);

        // Source AVCO must remain at qty=20, WAUC=50 — zero mutation.
        $srcAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->sourceScope)
            ->first();
        $this->assertNotNull($srcAvco);
        $this->assertEqualsWithDelta(20.0, (float) $srcAvco->on_hand_quantity, 0.0001,
            'Source AVCO on_hand_quantity must not change on a failed plan.');
        $this->assertEqualsWithDelta(50.0, (float) $srcAvco->weighted_average_unit_cost, 0.0001,
            'Source AVCO WAUC must not change on a failed plan.');

        // No cost ledger entries.
        $this->assertDatabaseCount('cost_ledger_entries', 0,
            'No cost ledger entries must be created when either leg fails.');

        // Pair-resolution records the blocking reason — in frozen state, not delivered.
        $resolution = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $pair['srcTxId'])
            ->first();
        $this->assertNotNull($resolution);
        $this->assertEquals('frozen', $resolution->lifecycle_status,
            'Pair must be in frozen state after source cost is captured but destination plan fails.');
        $this->assertEquals('SEQUENCE_GAP_DETECTED', $resolution->blocking_reason_code);

        // Source outbox message must remain pending.
        $srcMsg = DB::table('outbox_messages')->where('id', $pair['srcOutboxId'])->first();
        $this->assertEquals(OutboxStatusEnum::Pending->value, $srcMsg->status);
    }

    // -------------------------------------------------------------------------
    // P3 — Full source depletion
    // -------------------------------------------------------------------------

    /**
     * Transferring all units from the source scope resets the source AVCO to
     * null WAUC and zero carrying value. The destination receives the exact
     * carrying value that was in the source (no rounding drift).
     */
    public function test_P3_full_source_depletion_resets_source_and_transfers_exact_value(): void
    {
        // Source: exactly 10 units at WAUC 33.3333 = carrying 333.3330
        // Transfer ALL 10 units — full depletion path.
        $this->seedSourceAvco('10.0000', '33.3333', '333.3330');

        $pair = $this->buildPair('1', '1', '10');

        $this->service->processOutboxMessage($pair['srcOutboxId']);

        // Source AVCO: fully depleted
        $srcAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->sourceScope)
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $srcAvco->on_hand_quantity, 0.0001);
        $this->assertNull($srcAvco->weighted_average_unit_cost,
            'Source WAUC must be null after full depletion.');
        $this->assertEqualsWithDelta(0.0, (float) $srcAvco->carrying_value, 0.0001);

        // Destination AVCO: receives 10 units at the frozen source WAUC (33.3333)
        $dstAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->destScope)
            ->first();
        $this->assertEqualsWithDelta(10.0, (float) $dstAvco->on_hand_quantity, 0.0001);
        $this->assertEqualsWithDelta(33.3333, (float) $dstAvco->weighted_average_unit_cost, 0.001);

        // Cost ledger entries balance: relieved full carrying value = received value.
        $entries  = DB::table('cost_ledger_entries')
            ->where('property_id', $this->propertyId)
            ->get();
        $netDelta = $entries->sum(fn ($e) => (float) $e->value_delta);
        $this->assertEqualsWithDelta(0.0, $netDelta, 0.01,
            'Net value_delta must be zero even after full depletion.');

        // Pair delivered.
        $resolution = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $pair['srcTxId'])
            ->first();
        $this->assertEquals('delivered', $resolution->lifecycle_status);
    }

    // -------------------------------------------------------------------------
    // P4 — Frozen cost isolation
    // -------------------------------------------------------------------------

    /**
     * Once the source unit cost is frozen in the pair-resolution row, any
     * subsequent change to the source AVCO state does not affect what the
     * destination scope receives. The frozen cost is the durable transfer price.
     */
    public function test_P4_frozen_cost_is_isolated_from_subsequent_source_avco_changes(): void
    {
        // Seed source AVCO with WAUC=50 initially.
        $this->seedSourceAvco('20.0000', '50.0000', '1000.0000');

        $docId   = (string) Str::ulid();
        $lineId  = (string) Str::ulid();
        $srcTxId = (string) Str::ulid();
        $dstTxId = (string) Str::ulid();
        $srcOId  = (string) Str::ulid();
        $dstOId  = (string) Str::ulid();

        // Build the pair transactions and outbox messages.
        $base = [
            'property_id'                 => $this->propertyId,
            'item_id'                     => $this->itemId,
            'unit_cost'                   => '50.00',
            'source_document_type'        => 'transfer',
            'source_document_id'          => $docId,
            'source_line_type'            => 'transfer_line',
            'source_line_id'              => $lineId,
            'movement_role'               => 'transfer',
            'business_date'               => $this->businessDate,
            'occurred_at'                 => $this->occurredAt,
            'currency_code'               => 'IDR',
            'financial_period_id'         => $this->financialPeriodId,
            'valuation_approval_status'   => 'approved',
            'valuation_approval_reference'=> 'EVD-INT-004',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ];

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $srcTxId,
            'location_id'         => $this->locationAId,
            'transaction_type'    => 'transfer_out',
            'quantity_change'     => '-10.0000',
            'idempotency_key'     => "transfer:out:{$srcTxId}",
            'valuation_scope'     => $this->sourceScope,
            'valuation_sequence'  => 1,
        ]));

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $dstTxId,
            'location_id'         => $this->locationBId,
            'transaction_type'    => 'transfer_in',
            'quantity_change'     => '10.0000',
            'idempotency_key'     => "transfer:in:{$dstTxId}",
            'valuation_scope'     => $this->destScope,
            'valuation_sequence'  => 1,
        ]));

        DB::table('outbox_messages')->insert([
            'id'                              => $srcOId,
            'topic'                           => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $srcTxId,
            'payload'                         => json_encode(['event' => 'transfer_out']),
            'idempotency_key'                 => "outbox:transfer_out:{$srcTxId}",
            'status'                          => OutboxStatusEnum::Pending->value,
            'attempts'                        => 0,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        DB::table('outbox_messages')->insert([
            'id'                              => $dstOId,
            'topic'                           => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $dstTxId,
            'payload'                         => json_encode(['event' => 'transfer_in']),
            'idempotency_key'                 => "outbox:transfer_in:{$dstTxId}",
            'status'                          => OutboxStatusEnum::Pending->value,
            'attempts'                        => 0,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        // Pre-insert the pair-resolution row in 'frozen' state with frozen cost=75,
        // simulating a prior attempt that successfully captured source cost=75
        // before any subsequent AVCO mutation changed the source WAUC to 50.
        DB::table('cost_transfer_pair_resolutions')->insert([
            'id'                                   => (string) Str::ulid(),
            'property_id'                          => $this->propertyId,
            'source_document_id'                   => $docId,
            'source_line_id'                       => $lineId,
            'source_inventory_transaction_id'      => $srcTxId,
            'destination_inventory_transaction_id' => $dstTxId,
            'source_valuation_scope'               => $this->sourceScope,
            'destination_valuation_scope'          => $this->destScope,
            'source_valuation_sequence'            => 1,
            'destination_valuation_sequence'       => 1,
            'lifecycle_status'                     => 'frozen',
            'frozen_source_unit_cost'              => '75.0000',
            'blocking_reason_code'                 => null,
            'created_at'                           => now(),
            'updated_at'                           => now(),
        ]);

        // Current source AVCO has WAUC=50 — different from the frozen cost 75.
        // The service must use 75 (frozen), not 50 (current AVCO WAUC).
        $this->service->processOutboxMessage($srcOId);

        // Destination AVCO WAUC must equal the frozen cost (75), not current source WAUC (50).
        $dstAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->destScope)
            ->first();
        $this->assertNotNull($dstAvco);
        $this->assertEqualsWithDelta(75.0, (float) $dstAvco->weighted_average_unit_cost, 0.0001,
            'Destination WAUC must equal the frozen source unit cost (75), not current source AVCO WAUC (50).');
        $this->assertEqualsWithDelta(10.0, (float) $dstAvco->on_hand_quantity, 0.0001);

        // Pair must be delivered.
        $resolution = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $srcTxId)
            ->first();
        $this->assertEquals('delivered', $resolution->lifecycle_status);

        // Source AVCO: relieved at WAUC=50 (current source state), not frozen 75.
        // The source leg uses its own prior WAUC for the out-side, not the frozen cost.
        $srcAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->sourceScope)
            ->first();
        $this->assertEqualsWithDelta(10.0, (float) $srcAvco->on_hand_quantity, 0.0001,
            'Source AVCO must be reduced by 10 (the transfer quantity).');
    }
}
