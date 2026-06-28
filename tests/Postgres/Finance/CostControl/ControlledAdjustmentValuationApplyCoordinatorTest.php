<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationApplyCoordinator;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Property\Models\Property;

class ControlledAdjustmentValuationApplyCoordinatorTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private ControlledAdjustmentValuationApplyCoordinator $coordinator;
    private CostAvcoStateRepository $stateRepository;
    private string $businessDate = '2026-06-28';
    private string $occurredAt = '2026-06-28 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->location = InventoryLocation::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Apply Warehouse',
            'type' => 'internal'
        ]);

        $category = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Apply Category'
        ]);

        $this->item = InventoryItem::firstOrCreate([
            'property_id' => $this->property->id,
            'sku' => 'ITM-APPLY-ADJ',
            'name' => 'Apply Adjustment Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'category_id' => $category->id,
            'is_active' => true
        ]);

        $this->coordinator = app(ControlledAdjustmentValuationApplyCoordinator::class);
        $this->stateRepository = app(CostAvcoStateRepository::class);
    }

    private function seedGroup(string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'name' => 'Group ' . $status,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cost_authority_enrollments')->insert([
            'id' => (string) Str::ulid(),
            'enrollment_group_id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedSnapshot(string $groupId): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $id,
            'enrollment_group_id' => $groupId,
            'location_id' => $this->location->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            'opening_quantity' => '10.0000',
            'opening_carrying_value' => '100.0000',
            'currency_code' => 'USD',
            'business_date' => $this->businessDate,
            'financial_period_id' => 'fp_1',
            'evidence_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedState(
        string $groupId,
        string $snapshotId,
        ?int $lastSeq = null,
        ?string $lastDate = null,
        string $qty = '10.0000',
        string $val = '100.0000',
        string $wauc = '10.0000'
    ): void {
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            'on_hand_quantity' => $qty,
            'carrying_value' => $val,
            'weighted_average_unit_cost' => $wauc,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => $lastSeq,
            'last_valuation_business_date' => $lastDate,
            'enrollment_group_id' => $groupId,
            'enrollment_scope_snapshot_id' => $snapshotId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTransaction(
        string $id,
        string $type,
        int $seq,
        string $qtyChange,
        string $unitCost,
        string $totalCost
    ): void {
        DB::table('inventory_transactions')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'transaction_type' => $type,
            'quantity_change' => $qtyChange,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'business_date' => $this->businessDate,
            'occurred_at' => $this->occurredAt,
            'posted_at' => now(),
            'posted_by' => (string) Str::ulid(),
            'valuation_sequence' => $seq,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            'currency_code' => 'USD',
            'idempotency_key' => 'idem-' . $id,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. Valid enrolled AdjustmentIn.
     */
    public function test_valid_enrolled_adjustment_in(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $plan = $this->coordinator->apply($requestedIntent);

        $this->assertInstanceOf(ControlledAdjustmentValuationPlan::class, $plan);
        $this->assertEquals('15.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('160.0000', $plan->carryingValueAfter->getValue());
        $this->assertEquals('10.6667', $plan->weightedAverageUnitCostAfter->getValue());

        $this->assertDatabaseCount('cost_ledger_entries', 1);

        $state = CostAvcoState::where('property_id', $this->property->id)->first();
        $this->assertEquals('15.0000', $state->on_hand_quantity);
    }

    /**
     * 2. Valid enrolled AdjustmentOut.
     */
    public function test_valid_enrolled_adjustment_out(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, 5, $this->businessDate);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'adjustment_out', 6, '-3.0000', '10.0000', '-30.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 6,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('-3.0000'),
            unitCost: new AvcoDecimal('10.0000'),
            valueDelta: new AvcoDecimal('-30.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $priorSeq = new ValuationSequence(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            valuationScope: "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            businessDate: $this->businessDate,
            ledgerSequence: 5
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: $priorSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $plan = $this->coordinator->apply($requestedIntent);

        $this->assertEquals('7.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('70.0000', $plan->carryingValueAfter->getValue());
        $this->assertEquals('10.0000', $plan->weightedAverageUnitCostAfter->getValue());
    }

    /**
     * 3. Caller snapshot tampering verification.
     */
    public function test_caller_snapshot_tampering_rejection(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        // Requested quantity 12.0000 is tampered (database is 10.0000)
        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('12.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 4. Immutable transaction evidence mismatch.
     */
    public function test_transaction_evidence_mismatch_fails(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        // Quantity on transaction (6.0000) does not match Cost Ledger intent (5.0000)
        $this->seedTransaction($txId, 'adjustment_in', 1, '6.0000', '12.0000', '72.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 5. Authority mismatch fails.
     */
    public function test_group_authority_mismatch_fails(): void
    {
        $groupId = $this->seedGroup('unenrolled'); // Unenrolled!
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not ENROLLED');
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 6. Replay / stale sequence fails.
     */
    public function test_duplicate_sequence_rejection(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, 5, $this->businessDate);

        $txId = (string) Str::ulid();
        // Transaction sequence 5 is duplicate (stale) since last applied sequence is 5
        $this->seedTransaction($txId, 'adjustment_in', 5, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 5,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $priorSeq = new ValuationSequence(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            valuationScope: "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}",
            businessDate: $this->businessDate,
            ledgerSequence: 5
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: $priorSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stale or duplicate sequence detected.');
        $this->coordinator->apply($requestedIntent);
    }

    /**
     * 7. Failures roll back both cost ledger entries and states.
     */
    public function test_duplicate_idempotency_key_rolls_back_everything(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId1 = (string) Str::ulid();
        $this->seedTransaction($txId1, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent1 = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId1,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-dup-key',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent1 = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent1
        );

        $this->coordinator->apply($requestedIntent1);

        $this->assertDatabaseCount('cost_ledger_entries', 1);

        // Reset state sequence back to null to trigger another apply on same sequence
        DB::table('cost_avco_states')
            ->where('property_id', $this->property->id)
            ->update([
                'last_valuation_sequence' => null,
                'last_valuation_business_date' => null,
                'on_hand_quantity' => '10.0000',
                'carrying_value' => '100.0000',
                'weighted_average_unit_cost' => '10.0000'
            ]);

        $txId2 = (string) Str::ulid();
        $this->seedTransaction($txId2, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent2 = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId2,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-dup-key', // Duplicate idempotency key!
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent2 = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent2
        );

        try {
            $this->coordinator->apply($requestedIntent2);
            $this->fail('Should fail on duplicate idempotency key.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate idempotency detected', $e->getMessage());
        }

        // Verify rollback: state remains unchanged
        $stateClean = CostAvcoState::where('property_id', $this->property->id)->first();
        $this->assertNull($stateClean->last_valuation_sequence);
        $this->assertEquals('10.0000', $stateClean->on_hand_quantity);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
    }

    /**
     * 8. applyUsingLockedState() behavior.
     */
    public function test_apply_using_locked_state_rules(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'adjustment_in', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'adjustment',
            idempotencyKey: 'idem-' . $txId,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $requestedIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $this->property->id,
            locationId: $this->location->id,
            itemId: $this->item->id,
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $costLedgerIntent
        );

        DB::transaction(function () use ($requestedIntent) {
            $lockedState = $this->stateRepository->lockExistingSeededStateForScope(
                $this->property->id,
                $this->location->id,
                $this->item->id
            );

            $plan = $this->coordinator->applyUsingLockedState($lockedState, $requestedIntent);

            $this->assertInstanceOf(ControlledAdjustmentValuationPlan::class, $plan);
            $this->assertEquals('15.0000', $plan->quantityAfter->getValue());
        });
    }

    /**
     * 9. No production service references this coordinator.
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
            if (str_contains($path, 'ControlledAdjustmentValuationApplyCoordinator.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledAdjustmentValuationApplyCoordinator')) {
                $callers[] = $path;
            }
        }

        $this->assertEmpty($callers, 'Coordinator has production callers!');
    }
}
