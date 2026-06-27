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

class PairedTransferValuationTest extends PostgresTestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private PairedTransferValuationService $service;

    private string $propertyId;
    private string $itemId;
    private string $locationAId;
    private string $locationBId;
    private string $sourceDocId;
    private string $sourceLineId;
    private string $financialPeriodId;
    private string $sourceScope;
    private string $destScope;
    private string $businessDate;
    private string $occurredAt;
    private string $sourceTxId;
    private string $destTxId;
    private string $sourceOutboxId;
    private string $destOutboxId;

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

        // Create a real property (required by property_business_dates FK).
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $this->propertyId = $property->id;

        $this->itemId           = (string) Str::ulid();
        $this->locationAId      = (string) Str::ulid();
        $this->locationBId      = (string) Str::ulid();
        $this->sourceDocId      = (string) Str::ulid();
        $this->sourceLineId     = (string) Str::ulid();
        $this->financialPeriodId = (string) Str::ulid();
        $this->sourceTxId       = (string) Str::ulid();
        $this->destTxId         = (string) Str::ulid();
        $this->sourceOutboxId   = (string) Str::ulid();
        $this->destOutboxId     = (string) Str::ulid();

        $this->sourceScope  = "property:{$this->propertyId}:location:{$this->locationAId}:item:{$this->itemId}";
        $this->destScope    = "property:{$this->propertyId}:location:{$this->locationBId}:item:{$this->itemId}";
        $this->businessDate = '2026-06-27';
        $this->occurredAt   = '2026-06-27 08:00:00';

        // Open PropertyBusinessDate
        DB::table('property_business_dates')->insert([
            'id'            => (string) Str::ulid(),
            'property_id'   => $this->propertyId,
            'business_date' => $this->businessDate,
            'status'        => 'Open',
            'is_open'       => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Open FinancialPeriod (no FK on property_id — any ULID is fine)
        DB::table('gl_financial_periods')->insert([
            'id'          => $this->financialPeriodId,
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month'=> 6,
            'status'      => 'Open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->insertTransferPair();
        $this->insertOutboxPair();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertTransferPair(
        ?string $sourceTxId   = null,
        ?string $destTxId     = null,
        ?string $sourceDocId  = null,
        ?string $sourceLineId = null
    ): void {
        $srcId  = $sourceTxId  ?? $this->sourceTxId;
        $dstId  = $destTxId    ?? $this->destTxId;
        $docId  = $sourceDocId  ?? $this->sourceDocId;
        $lineId = $sourceLineId ?? $this->sourceLineId;

        $base = [
            'property_id'                   => $this->propertyId,
            'item_id'                        => $this->itemId,
            'unit_cost'                      => '50.00',
            'source_document_type'           => 'transfer',
            'source_document_id'             => $docId,
            'source_line_type'               => 'transfer_line',
            'source_line_id'                 => $lineId,
            'movement_role'                  => 'transfer',
            'business_date'                  => $this->businessDate,
            'occurred_at'                    => $this->occurredAt,
            'currency_code'                  => 'IDR',
            'financial_period_id'            => $this->financialPeriodId,
            'valuation_approval_status'      => 'approved',
            'valuation_approval_reference'   => 'EVD-001',
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ];

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $srcId,
            'location_id'         => $this->locationAId,
            'transaction_type'    => 'transfer_out',
            'quantity_change'     => '-10.0000',
            'idempotency_key'     => "transfer:out:{$srcId}",
            'valuation_scope'     => $this->sourceScope,
            'valuation_sequence'  => 1,
        ]));

        DB::table('inventory_transactions')->insert(array_merge($base, [
            'id'                  => $dstId,
            'location_id'         => $this->locationBId,
            'transaction_type'    => 'transfer_in',
            'quantity_change'     => '10.0000',
            'idempotency_key'     => "transfer:in:{$dstId}",
            'valuation_scope'     => $this->destScope,
            'valuation_sequence'  => 1,
        ]));
    }

    private function insertOutboxPair(
        ?string $sourceOutboxId = null,
        ?string $destOutboxId   = null,
        ?string $sourceTxId     = null,
        ?string $destTxId       = null
    ): void {
        $srcOutId = $sourceOutboxId ?? $this->sourceOutboxId;
        $dstOutId = $destOutboxId   ?? $this->destOutboxId;
        $srcTxId  = $sourceTxId     ?? $this->sourceTxId;
        $dstTxId  = $destTxId       ?? $this->destTxId;

        DB::table('outbox_messages')->insert([
            'id'                               => $srcOutId,
            'topic'                            => 'inventory.transaction.posted',
            'source_inventory_transaction_id'  => $srcTxId,
            'payload'                          => json_encode(['event' => 'transfer_out']),
            'idempotency_key'                  => "outbox:transfer_out:{$srcTxId}",
            'status'                           => OutboxStatusEnum::Pending->value,
            'attempts'                         => 0,
            'created_at'                       => now(),
            'updated_at'                       => now(),
        ]);

        DB::table('outbox_messages')->insert([
            'id'                               => $dstOutId,
            'topic'                            => 'inventory.transaction.posted',
            'source_inventory_transaction_id'  => $dstTxId,
            'payload'                          => json_encode(['event' => 'transfer_in']),
            'idempotency_key'                  => "outbox:transfer_in:{$dstTxId}",
            'status'                           => OutboxStatusEnum::Pending->value,
            'attempts'                         => 0,
            'created_at'                       => now(),
            'updated_at'                       => now(),
        ]);
    }

    private function seedSourceAvcoState(
        string $onHandQty   = '20.0000',
        string $wauc        = '50.0000',
        string $carryingVal = '1000.0000'
    ): void {
        DB::table('cost_avco_states')->insertOrIgnore([
            'id'                              => (string) Str::ulid(),
            'property_id'                     => $this->propertyId,
            'location_id'                     => $this->locationAId,
            'item_id'                         => $this->itemId,
            'valuation_scope'                 => $this->sourceScope,
            'on_hand_quantity'                => $onHandQty,
            'carrying_value'                  => $carryingVal,
            'weighted_average_unit_cost'      => $wauc,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence'         => null,
            'last_valuation_business_date'    => null,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path — triggered from TransferOut leg
    // -------------------------------------------------------------------------

    public function test_happy_path_triggered_from_transfer_out_delivers_both_legs(): void
    {
        $this->seedSourceAvcoState();

        $this->service->processOutboxMessage($this->sourceOutboxId);

        // Source AVCO: qty 20 − 10 = 10, WAUC unchanged at 50
        $srcAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->sourceScope)
            ->first();
        $this->assertNotNull($srcAvco);
        $this->assertEqualsWithDelta(10.0, (float) $srcAvco->on_hand_quantity, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $srcAvco->weighted_average_unit_cost, 0.0001);

        // Destination AVCO: qty 0 + 10 = 10, WAUC = 50 (from frozen source cost)
        $dstAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->destScope)
            ->first();
        $this->assertNotNull($dstAvco);
        $this->assertEqualsWithDelta(10.0, (float) $dstAvco->on_hand_quantity, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $dstAvco->weighted_average_unit_cost, 0.0001);

        // Two cost ledger entries created
        $ledgerEntries = DB::table('cost_ledger_entries')
            ->where('property_id', $this->propertyId)
            ->get();
        $this->assertCount(2, $ledgerEntries);

        $entryTypes = $ledgerEntries->pluck('entry_type')->all();
        $this->assertContains('transfer', $entryTypes);

        // Pair-resolution delivered
        $pair = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $this->sourceTxId)
            ->first();
        $this->assertNotNull($pair);
        $this->assertEquals('delivered', $pair->lifecycle_status);
        $this->assertEqualsWithDelta(50.0, (float) $pair->frozen_source_unit_cost, 0.0001);

        // Both outbox messages delivered
        $srcMsg = DB::table('outbox_messages')->where('id', $this->sourceOutboxId)->first();
        $dstMsg = DB::table('outbox_messages')->where('id', $this->destOutboxId)->first();
        $this->assertEquals(OutboxStatusEnum::Delivered->value, $srcMsg->status);
        $this->assertEquals(OutboxStatusEnum::Delivered->value, $dstMsg->status);
    }

    // -------------------------------------------------------------------------
    // Happy path — triggered from TransferIn leg (symmetric)
    // -------------------------------------------------------------------------

    public function test_happy_path_triggered_from_transfer_in_delivers_both_legs(): void
    {
        $this->seedSourceAvcoState();

        // Trigger from the destination (TransferIn) outbox message instead.
        $this->service->processOutboxMessage($this->destOutboxId);

        $srcAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->sourceScope)
            ->first();
        $this->assertEqualsWithDelta(10.0, (float) $srcAvco->on_hand_quantity, 0.0001);

        $dstAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->destScope)
            ->first();
        $this->assertEqualsWithDelta(10.0, (float) $dstAvco->on_hand_quantity, 0.0001);

        $pair = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $this->sourceTxId)
            ->first();
        $this->assertEquals('delivered', $pair->lifecycle_status);

        $srcMsg = DB::table('outbox_messages')->where('id', $this->sourceOutboxId)->first();
        $dstMsg = DB::table('outbox_messages')->where('id', $this->destOutboxId)->first();
        $this->assertEquals(OutboxStatusEnum::Delivered->value, $srcMsg->status);
        $this->assertEquals(OutboxStatusEnum::Delivered->value, $dstMsg->status);
    }

    // -------------------------------------------------------------------------
    // Idempotency — both outbox messages already delivered
    // -------------------------------------------------------------------------

    public function test_already_delivered_pair_is_noop(): void
    {
        $this->seedSourceAvcoState();

        // Deliver once legitimately.
        $this->service->processOutboxMessage($this->sourceOutboxId);

        $entriesAfterFirst = DB::table('cost_ledger_entries')
            ->where('property_id', $this->propertyId)
            ->count();
        $this->assertEquals(2, $entriesAfterFirst);

        // Second call must be a no-op — no duplicate ledger entries.
        $this->service->processOutboxMessage($this->sourceOutboxId);

        $entriesAfterSecond = DB::table('cost_ledger_entries')
            ->where('property_id', $this->propertyId)
            ->count();
        $this->assertEquals(2, $entriesAfterSecond, 'Second call must not append duplicate entries.');
    }

    // -------------------------------------------------------------------------
    // Partner not yet posted — early return, no pair-resolution row created
    // -------------------------------------------------------------------------

    public function test_returns_early_when_partner_transaction_not_yet_posted(): void
    {
        // Insert ONLY the TransferOut transaction and its outbox message;
        // the TransferIn partner is missing.
        $soloTxId     = (string) Str::ulid();
        $soloOutboxId = (string) Str::ulid();
        $soloDocId    = (string) Str::ulid();
        $soloLineId   = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id'                              => $soloTxId,
            'property_id'                     => $this->propertyId,
            'location_id'                     => $this->locationAId,
            'item_id'                         => $this->itemId,
            'transaction_type'                => 'transfer_out',
            'quantity_change'                 => '-10.0000',
            'unit_cost'                       => '50.00',
            'source_document_type'            => 'transfer',
            'source_document_id'              => $soloDocId,
            'source_line_type'                => 'transfer_line',
            'source_line_id'                  => $soloLineId,
            'movement_role'                   => 'transfer',
            'business_date'                   => $this->businessDate,
            'occurred_at'                     => $this->occurredAt,
            'currency_code'                   => 'IDR',
            'financial_period_id'             => $this->financialPeriodId,
            'valuation_scope'                 => $this->sourceScope,
            'valuation_sequence'              => 2,
            'valuation_approval_status'       => 'approved',
            'valuation_approval_reference'    => 'EVD-002',
            'idempotency_key'                 => "transfer:out:{$soloTxId}",
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        DB::table('outbox_messages')->insert([
            'id'                              => $soloOutboxId,
            'topic'                           => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $soloTxId,
            'payload'                         => json_encode(['event' => 'transfer_out']),
            'idempotency_key'                 => "outbox:transfer_out:{$soloTxId}",
            'status'                          => OutboxStatusEnum::Pending->value,
            'attempts'                        => 0,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        // Must return early without error or any pair-resolution row.
        $this->service->processOutboxMessage($soloOutboxId);

        $pair = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $soloTxId)
            ->first();
        $this->assertNull($pair, 'No pair-resolution row should be created when partner is missing.');

        $outbox = DB::table('outbox_messages')->where('id', $soloOutboxId)->first();
        $this->assertEquals(OutboxStatusEnum::Pending->value, $outbox->status);
    }

    // -------------------------------------------------------------------------
    // Blocking — source AVCO has null WAUC (no prior receipts in scope)
    // -------------------------------------------------------------------------

    public function test_blocking_when_source_wauc_is_null_records_reason_and_stays_pending(): void
    {
        // Seed source scope with qty=0, WAUC=null (bootstrap-only state).
        // Leave cost_avco_states empty — bootstrapAndLock will create it with WAUC=null.

        $this->service->processOutboxMessage($this->sourceOutboxId);

        $pair = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $this->sourceTxId)
            ->first();
        $this->assertNotNull($pair);
        $this->assertEquals('pending', $pair->lifecycle_status,
            'Pair must remain pending when source WAUC is null.');
        $this->assertEquals('MISSING_PREVAILING_CARRYING_COST', $pair->blocking_reason_code);

        // No cost ledger entries
        $this->assertDatabaseCount('cost_ledger_entries', 0);

        // Both outbox messages still pending
        $srcMsg = DB::table('outbox_messages')->where('id', $this->sourceOutboxId)->first();
        $this->assertEquals(OutboxStatusEnum::Pending->value, $srcMsg->status);
    }

    // -------------------------------------------------------------------------
    // Frozen retry — stored cost is reused instead of re-reading from AVCO
    // -------------------------------------------------------------------------

    public function test_frozen_retry_uses_stored_frozen_cost_not_current_avco_wauc(): void
    {
        // Pre-create the pair-resolution row in 'frozen' state with frozen_source_unit_cost=75.
        // Then seed source AVCO with a different WAUC (50). The service must use 75, not 50.
        $this->seedSourceAvcoState('20.0000', '50.0000', '1000.0000');

        DB::table('cost_transfer_pair_resolutions')->insert([
            'id'                                   => (string) Str::ulid(),
            'property_id'                          => $this->propertyId,
            'source_document_id'                   => $this->sourceDocId,
            'source_line_id'                       => $this->sourceLineId,
            'source_inventory_transaction_id'      => $this->sourceTxId,
            'destination_inventory_transaction_id' => $this->destTxId,
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

        $this->service->processOutboxMessage($this->sourceOutboxId);

        // Destination AVCO WAUC must reflect 75 (frozen cost), not 50 (current source AVCO WAUC).
        $dstAvco = DB::table('cost_avco_states')
            ->where('valuation_scope', $this->destScope)
            ->first();
        $this->assertNotNull($dstAvco);
        $this->assertEqualsWithDelta(75.0, (float) $dstAvco->weighted_average_unit_cost, 0.0001,
            'Destination WAUC must equal the frozen source unit cost (75), not the current source AVCO WAUC (50).');

        $pair = DB::table('cost_transfer_pair_resolutions')
            ->where('source_inventory_transaction_id', $this->sourceTxId)
            ->first();
        $this->assertEquals('delivered', $pair->lifecycle_status);
    }
}
