<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\PostgresTestCase;
use Modules\Finance\CostControl\Services\ControlledTransferValuationApplyCoordinator;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

class ControlledTransferValuationApplyCoordinatorTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ControlledTransferValuationApplyCoordinator $coordinator;
    private CostAvcoStateRepository $stateRepository;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $locationSrc;
    private InventoryLocation $locationDest;
    private string $businessDate;
    private string $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator = app(ControlledTransferValuationApplyCoordinator::class);
        $this->stateRepository = app(CostAvcoStateRepository::class);

        $this->property = Property::first();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Transfer Test Category',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'XFER-ITEM-001',
            'name'                  => 'Transfer Test Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        // Lexicographically: 'loc_src' sorts AFTER 'loc_dest', proving sorting role-mapping safety
        $this->locationDest = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'loc_dest'],
            ['type' => 'internal']
        );

        $this->locationSrc = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'loc_src'],
            ['type' => 'internal']
        );

        $this->businessDate = '2026-06-28';
        $this->occurredAt = '2026-06-28 12:00:00';

        // Seed property business date open
        DB::table('property_business_dates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => 'open',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed financial period open
        DB::table('financial_periods')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedGroup(string $itemId, string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedSnapshot(string $groupId, string $locationId, string $qty = '10.0000', string $value = '100.0000'): string
    {
        $snapshotId = (string) Str::ulid();
        $valuationScope = "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $snapshotId,
            'enrollment_group_id' => $groupId,
            'location_id' => $locationId,
            'valuation_scope' => $valuationScope,
            'opening_quantity' => $qty,
            'opening_carrying_value' => $value,
            'currency_code' => 'USD',
            'business_date' => $this->businessDate,
            'financial_period_id' => 'fp_1',
            'evidence_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $snapshotId;
    }

    private function seedState(
        string $groupId,
        string $snapshotId,
        string $locationId,
        ?int $seq,
        ?string $businessDate,
        string $qty = '10.0000',
        string $value = '100.0000',
        string $wauc = '10.0000'
    ): void {
        $valuationScope = "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $locationId,
            'item_id' => $this->item->id,
            'valuation_scope' => $valuationScope,
            'on_hand_quantity' => $qty,
            'carrying_value' => $value,
            'weighted_average_unit_cost' => $wauc,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => $seq,
            'last_valuation_business_date' => $businessDate,
            'enrollment_group_id' => $groupId,
            'enrollment_scope_snapshot_id' => $snapshotId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTransaction(
        string $txId,
        string $locationId,
        string $type,
        int $seq,
        string $qtyChange,
        string $unitCost,
        string $totalCost
    ): void {
        $valuationScope = "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
        DB::table('inventory_transactions')->insert([
            'id' => $txId,
            'property_id' => $this->property->id,
            'location_id' => $locationId,
            'item_id' => $this->item->id,
            'valuation_scope' => $valuationScope,
            'transaction_type' => $type,
            'valuation_sequence' => $seq,
            'quantity_change' => $qtyChange,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'business_date' => $this->businessDate,
            'occurred_at' => $this->occurredAt,
            'currency_code' => 'USD',
            'idempotency_key' => 'idem_' . $txId,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => 'ref_' . $txId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeLedgerIntent(
        string $txId,
        string $entryType,
        int $entrySequence,
        string $qtyDelta,
        string $unitCost,
        string $valueDelta
    ): ControlledValuationCostLedgerIntent {
        return new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: $entryType,
            idempotencyKey: 'idem_' . $txId,
            entrySequence: $entrySequence,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal($qtyDelta),
            unitCost: new AvcoDecimal($unitCost),
            valueDelta: new AvcoDecimal($valueDelta),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );
    }

    /**
     * 1. A valid all-enrolled paired transfer locks/applies scopes correctly.
     */
    public function test_valid_paired_transfer_applies_atomically(): void
    {
        $groupSrcId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupSrcId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupSrcId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupSrcId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupSrcId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $txSrcId = (string) Str::ulid();
        $this->seedTransaction($txSrcId, $this->locationSrc->id, 'transfer_out', 1, '-2.0000', '10.0000', '-20.0000');

        $txDestId = (string) Str::ulid();
        $this->seedTransaction($txDestId, $this->locationDest->id, 'transfer_in', 1, '2.0000', '10.0000', '20.0000');

        $outbound = $this->makeLedgerIntent($txSrcId, 'transfer', 1, '-2.0000', '10.0000', '-20.0000');
        $inbound = $this->makeLedgerIntent($txDestId, 'transfer', 1, '2.0000', '10.0000', '20.0000');

        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            sourceLocationId: $this->locationSrc->id,
            destinationLocationId: $this->locationDest->id,
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $plan = $this->coordinator->apply($requestedIntent);

        $this->assertEquals('8.0000', $plan->sourceQuantityAfter->getValue());
        $this->assertEquals('80.0000', $plan->sourceCarryingValueAfter->getValue());
        $this->assertEquals('7.0000', $plan->destinationQuantityAfter->getValue());
        $this->assertEquals('60.0000', $plan->destinationCarryingValueAfter->getValue());
        $this->assertEquals('8.5714', $plan->destinationWeightedAverageUnitCostAfter->getValue());

        $this->assertDatabaseCount('cost_ledger_entries', 2);
    }

    /**
     * 2. Caller snapshot tampering fails.
     */
    public function test_tampered_caller_snapshot_fails(): void
    {
        $groupSrcId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupSrcId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupSrcId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupSrcId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupSrcId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $txSrcId = (string) Str::ulid();
        $this->seedTransaction($txSrcId, $this->locationSrc->id, 'transfer_out', 1, '-2.0000', '10.0000', '-20.0000');

        $txDestId = (string) Str::ulid();
        $this->seedTransaction($txDestId, $this->locationDest->id, 'transfer_in', 1, '2.0000', '10.0000', '20.0000');

        $outbound = $this->makeLedgerIntent($txSrcId, 'transfer', 1, '-2.0000', '10.0000', '-20.0000');
        $inbound = $this->makeLedgerIntent($txDestId, 'transfer', 1, '2.0000', '10.0000', '20.0000');

        // Tamper sourceCurrentQuantity to be 100 instead of 10.
        // It must throw an exception because the pure planner will fail when trying to match the unitCost/value using the locked database state quantity.
        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            sourceLocationId: $this->locationSrc->id,
            destinationLocationId: $this->locationDest->id,
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('100.0000'), // Tampered!
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->expectException(InvalidArgumentException::class);
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 3. Both transaction legs must validate: mismatches fail.
     */
    public function test_transaction_metadata_mismatch_fails(): void
    {
        $groupSrcId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupSrcId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupSrcId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupSrcId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupSrcId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $txSrcId = (string) Str::ulid();
        $this->seedTransaction($txSrcId, $this->locationSrc->id, 'transfer_out', 1, '-2.0000', '10.0000', '-20.0000');

        $txDestId = (string) Str::ulid();
        // Mismatch: Transaction on dest is seeded with wrong sequence 5
        $this->seedTransaction($txDestId, $this->locationDest->id, 'transfer_in', 5, '2.0000', '10.0000', '20.0000');

        $outbound = $this->makeLedgerIntent($txSrcId, 'transfer', 1, '-2.0000', '10.0000', '-20.0000');
        $inbound = $this->makeLedgerIntent($txDestId, 'transfer', 1, '2.0000', '10.0000', '20.0000');

        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            sourceLocationId: $this->locationSrc->id,
            destinationLocationId: $this->locationDest->id,
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Inbound transaction mismatch');
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 4. Failure after first append rolls back everything.
     */
    public function test_failure_rolls_back_both_legs(): void
    {
        $groupSrcId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupSrcId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupSrcId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupSrcId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupSrcId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $txSrcId = (string) Str::ulid();
        $this->seedTransaction($txSrcId, $this->locationSrc->id, 'transfer_out', 1, '-2.0000', '10.0000', '-20.0000');

        $txDestId = (string) Str::ulid();
        $this->seedTransaction($txDestId, $this->locationDest->id, 'transfer_in', 1, '2.0000', '10.0000', '20.0000');

        $outbound = $this->makeLedgerIntent($txSrcId, 'transfer', 1, '-2.0000', '10.0000', '-20.0000');
        $inbound = $this->makeLedgerIntent($txDestId, 'transfer', 5, '2.0000', '10.0000', '20.0000'); // sequence 5 gap will fail the planner!

        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            sourceLocationId: $this->locationSrc->id,
            destinationLocationId: $this->locationDest->id,
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        try {
            $this->coordinator->apply($requestedIntent);
            $this->fail('Should fail due to planner sequence gap check.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Destination sequence gap', $e->getMessage());
        }

        // Verify rollback: no cost ledger entries written, states remain untouched
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $stateClean = CostAvcoState::where('property_id', $this->property->id)->get();
        $this->assertCount(2, $stateClean);
        foreach ($stateClean as $state) {
            $this->assertNull($state->last_valuation_sequence);
        }
    }

    /**
     * 5. applyUsingLockedStates() runs without initiating transactions.
     */
    public function test_apply_using_locked_states_pure_logic(): void
    {
        $groupSrcId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupSrcId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupSrcId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupSrcId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupSrcId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $txSrcId = (string) Str::ulid();
        $this->seedTransaction($txSrcId, $this->locationSrc->id, 'transfer_out', 1, '-2.0000', '10.0000', '-20.0000');

        $txDestId = (string) Str::ulid();
        $this->seedTransaction($txDestId, $this->locationDest->id, 'transfer_in', 1, '2.0000', '10.0000', '20.0000');

        $outbound = $this->makeLedgerIntent($txSrcId, 'transfer', 1, '-2.0000', '10.0000', '-20.0000');
        $inbound = $this->makeLedgerIntent($txDestId, 'transfer', 1, '2.0000', '10.0000', '20.0000');

        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            sourceLocationId: $this->locationSrc->id,
            destinationLocationId: $this->locationDest->id,
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        DB::transaction(function () use ($requestedIntent) {
            [$sourceState, $destState] = $this->stateRepository->lockExistingSeededStatePair(
                $requestedIntent->propertyId,
                $requestedIntent->itemId,
                $requestedIntent->sourceLocationId,
                $requestedIntent->destinationLocationId
            );

            $plan = $this->coordinator->applyUsingLockedStates($sourceState, $destState, $requestedIntent);
            $this->assertEquals('8.0000', $plan->sourceQuantityAfter->getValue());
        });
    }

    /**
     * 6. No production service references the coordinator.
     */
    public function test_no_production_service_references_coordinator(): void
    {
        $modulePath = base_path('Modules');
        $callers = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'ControlledTransferValuationApplyCoordinator.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledTransferValuationApplyCoordinator')) {
                $callers[] = $path;
            }
        }

        $this->assertEmpty($callers, 'Coordinator has production callers!');
    }
}
