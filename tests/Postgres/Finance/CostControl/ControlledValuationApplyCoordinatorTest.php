<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Services\ControlledValuationApplyCoordinator;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionPlan;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use RuntimeException;
use Tests\PostgresTestCase;

class ControlledValuationApplyCoordinatorTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ControlledValuationApplyCoordinator $coordinator;

    private Property $property;

    private InventoryCategory $category;

    private InventoryItem $item;

    private InventoryLocation $location;

    private string $businessDate;

    private string $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator = app(ControlledValuationApplyCoordinator::class);

        $this->property = Property::first();

        $this->category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General',
        ]);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->category->id,
            'sku' => 'COORDINATOR-001',
            'name' => 'Coordinator Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Coordinator Test Warehouse',
            'type' => 'internal',
        ]);

        $this->businessDate = '2026-06-28';
        $this->occurredAt = '2026-06-28 12:00:00';
    }

    private function seedGroup(string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedSnapshot(string $groupId): string
    {
        $id = (string) Str::ulid();
        $valuationScope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $id,
            'enrollment_group_id' => $groupId,
            'location_id' => $this->location->id,
            'valuation_scope' => $valuationScope,
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

    private function seedState(string $groupId, string $snapshotId, ?int $lastSeq = null, ?string $lastDate = null): void
    {
        $valuationScope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'valuation_scope' => $valuationScope,
            'on_hand_quantity' => '10.0000',
            'carrying_value' => '100.0000',
            'weighted_average_unit_cost' => '10.0000',
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
        string $txId,
        string $type = 'purchase_receipt',
        int $valuationSeq = 1,
        string $quantityChange = '5.0000',
        string $unitCost = '12.00',
        string $totalCost = '60.00',
        ?string $businessDate = null,
        ?string $occurredAt = null,
        ?string $valuationScope = null
    ): void {
        $scope = $valuationScope ?? "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        DB::table('inventory_transactions')->insert([
            'id' => $txId,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'transaction_type' => $type,
            'quantity_before' => '10.0000',
            'quantity_change' => $quantityChange,
            'quantity_after' => '15.0000',
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'posted_at' => $occurredAt ?? $this->occurredAt,
            'business_date' => $businessDate ?? $this->businessDate,
            'occurred_at' => $occurredAt ?? $this->occurredAt,
            'currency_code' => 'USD',
            'valuation_scope' => $scope,
            'valuation_sequence' => $valuationSeq,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. Valid receipt evidence atomically applies state mutation and ledger entry.
     */
    public function test_apply_valid_receipt_atomically(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-1',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $plan = $this->coordinator->apply(
            $this->property->id,
            $this->location->id,
            $this->item->id,
            $costLedgerIntent
        );

        $this->assertInstanceOf(ControlledValuationStateTransitionPlan::class, $plan);
        $this->assertEquals('15.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('160.0000', $plan->carryingValueAfter->getValue());
        $this->assertEquals('10.6667', $plan->weightedAverageUnitCostAfter->getValue());

        // Verify exactly one Cost Ledger entry was appended
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        // Verify CostAvcoState was mutated
        $state = CostAvcoState::where('property_id', $this->property->id)
            ->where('location_id', $this->location->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals('15.0000', $state->on_hand_quantity);
        $this->assertEquals('160.0000', $state->carrying_value);
        $this->assertEquals('10.6667', $state->weighted_average_unit_cost);
        $this->assertEquals(1, $state->last_valuation_sequence);
        $this->assertEquals($this->businessDate, $state->last_valuation_business_date->format('Y-m-d'));

        // Verify enrollment provenance remains unchanged
        $this->assertEquals($groupId, $state->enrollment_group_id);
        $this->assertEquals($snapshotId, $state->enrollment_scope_snapshot_id);
    }

    /**
     * 2. An APPROVED group or mismatched group fails before append and state mutation.
     */
    public function test_fails_when_group_not_enrolled(): void
    {
        $groupId = $this->seedGroup('approved');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-2',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not ENROLLED');

        try {
            $this->coordinator->apply(
                $this->property->id,
                $this->location->id,
                $this->item->id,
                $costLedgerIntent
            );
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $state = CostAvcoState::where('property_id', $this->property->id)->first();
            $this->assertEquals('10.0000', $state->on_hand_quantity);
        }
    }

    /**
     * 3. Missing CostAvcoState fails before append and does not create a bootstrap row.
     */
    public function test_fails_when_state_missing(): void
    {
        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-3',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CostAvcoState row missing');

        try {
            $this->coordinator->apply(
                $this->property->id,
                $this->location->id,
                $this->item->id,
                $costLedgerIntent
            );
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $this->assertDatabaseCount('cost_avco_states', 0);
        }
    }

    /**
     * 4. Mismatch on transaction evidence fails before append and state mutation.
     */
    public function test_fails_when_evidence_mismatches(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        // Mismatched valueDelta (55.0000 vs 60.0000)
        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-4',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('55.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value delta mismatch on transaction evidence.');

        try {
            $this->coordinator->apply(
                $this->property->id,
                $this->location->id,
                $this->item->id,
                $costLedgerIntent
            );
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $state = CostAvcoState::where('property_id', $this->property->id)->first();
            $this->assertEquals('10.0000', $state->on_hand_quantity);
        }
    }

    /**
     * 5. Reapplying the same evidence fails as stale sequence.
     */
    public function test_reapply_fails_as_stale(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-5',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->coordinator->apply(
            $this->property->id,
            $this->location->id,
            $this->item->id,
            $costLedgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stale or duplicate sequence detected.');

        $this->coordinator->apply(
            $this->property->id,
            $this->location->id,
            $this->item->id,
            $costLedgerIntent
        );
    }

    /**
     * 6. Duplicate append boundary rolls back the entire coordinator transaction.
     */
    public function test_duplicate_append_boundary_rolls_back_everything(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, 1, $this->businessDate);

        $txId1 = (string) Str::ulid();
        $this->seedTransaction($txId1, 'purchase_receipt', 2, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent1 = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId1,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-dup-test',
            entrySequence: 2,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->coordinator->apply(
            $this->property->id,
            $this->location->id,
            $this->item->id,
            $costLedgerIntent1
        );

        $state = CostAvcoState::where('property_id', $this->property->id)->first();
        $this->assertEquals(2, $state->last_valuation_sequence);

        // Reset state back to sequence 1 (keeping the committed cost ledger entry with the idempotency key)
        DB::table('cost_avco_states')
            ->where('id', $state->id)
            ->update([
                'last_valuation_sequence' => 1,
                'on_hand_quantity' => '10.0000',
                'carrying_value' => '100.0000',
                'weighted_average_unit_cost' => '10.0000',
            ]);

        $txId2 = (string) Str::ulid();
        $this->seedTransaction($txId2, 'purchase_receipt', 2, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent2 = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId2,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-dup-test', // Duplicate!
            entrySequence: 2,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        try {
            $this->coordinator->apply(
                $this->property->id,
                $this->location->id,
                $this->item->id,
                $costLedgerIntent2
            );
            $this->fail('Should fail on duplicate idempotency key on append.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate idempotency detected', $e->getMessage());
        }

        // Verify rollback: state remains sequence 1, quantity 10
        $stateClean = CostAvcoState::where('property_id', $this->property->id)->first();
        $this->assertEquals(1, $stateClean->last_valuation_sequence);
        $this->assertEquals('10.0000', $stateClean->on_hand_quantity);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
    }

    /**
     * 7. Side effect prevention.
     */
    public function test_causes_no_side_effects_on_other_tables(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'purchase_receipt', 1, '5.0000', '12.0000', '60.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idem-side-effects',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('5.0000'),
            unitCost: new AvcoDecimal('12.0000'),
            valueDelta: new AvcoDecimal('60.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $tables = [
            'inventory_transactions',
            'cost_authority_enrollment_groups',
            'cost_authority_enrollment_scope_snapshots',
            'outbox_messages',
            'journal_candidates',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
        ];

        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $this->coordinator->apply(
            $this->property->id,
            $this->location->id,
            $this->item->id,
            $costLedgerIntent
        );

        foreach ($tables as $table) {
            $this->assertEquals($countsBefore[$table], DB::table($table)->count(), "Table '{$table}' mutated!");
        }
    }

    /**
     * 8. Unsupported types reject before state mutation.
     */
    public function test_rejects_unsupported_movement_types(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, null, null);

        $txId = (string) Str::ulid();
        $this->seedTransaction($txId, 'issue', 1, '-5.0000', '10.0000', '-50.0000');

        $costLedgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'issue',
            idempotencyKey: 'idem-issue',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('-5.0000'),
            unitCost: new AvcoDecimal('10.0000'),
            valueDelta: new AvcoDecimal('-50.0000'),
            businessDate: $this->businessDate,
            occurredAt: $this->occurredAt
        );

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->coordinator->apply(
                $this->property->id,
                $this->location->id,
                $this->item->id,
                $costLedgerIntent
            );
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $state = CostAvcoState::where('property_id', $this->property->id)->first();
            $this->assertEquals('10.0000', $state->on_hand_quantity);
        }
    }

    /**
     * 9. Only approved synchronous invocation services and the deferred handler reference the coordinator.
     */
    public function test_only_approved_services_reference_coordinator(): void
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
            if (str_contains($path, 'ControlledValuationApplyCoordinator.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledValuationApplyCoordinator')) {
                $callers[] = $path;
            }
        }

        sort($callers, SORT_STRING);
        $expected = [
            realpath(base_path('Modules/Finance/CostControl/Adapters/InventorySynchronousCostValuationAdapter.php')),
            realpath(base_path('Modules/Finance/CostControl/Services/ControlledIssueValuationInvocationService.php')),
            realpath(base_path('Modules/Finance/CostControl/Services/ControlledReceiptValuationInvocationService.php')),
            realpath(base_path('Modules/Finance/CostControl/Services/DeferredSingleTransactionValuationHandler.php')),
        ];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $callers, 'Coordinator has an unauthorized production caller.');
    }
}
