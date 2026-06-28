<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationInvocationService;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Property\Models\Property;

class ControlledAdjustmentValuationInvocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private AdjustmentService $adjustmentService;
    private CostAvcoStateRepository $stateRepository;
    private string $businessDate = '2026-06-28';
    private string $occurredAt = '2026-06-28 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->location = InventoryLocation::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Invocation Warehouse',
            'type' => 'internal'
        ]);

        $category = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Invocation Category'
        ]);

        $this->item = InventoryItem::firstOrCreate([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ1',
            'name' => 'Invoke Adjustment Item 1',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'category_id' => $category->id,
            'is_active' => true
        ]);

        $this->adjustmentService = app(AdjustmentService::class);
        $this->stateRepository = app(CostAvcoStateRepository::class);

        // Seed stock balance row for testing BR-065 staleness validation
        InventoryStock::firstOrCreate([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
        ], [
            'physical_quantity' => '10.0000',
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock->value,
            'last_movement_at' => now(),
        ]);
    }

    private function seedGroup(string $itemId, string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'name' => 'Group ' . $itemId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cost_authority_enrollments')->insert([
            'id' => (string) Str::ulid(),
            'enrollment_group_id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedSnapshot(string $groupId, string $itemId): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $id,
            'enrollment_group_id' => $groupId,
            'location_id' => $this->location->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$itemId}",
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
        string $itemId,
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
            'item_id' => $itemId,
            'valuation_scope' => "property:{$this->property->id}:location:{$this->location->id}:item:{$itemId}",
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

    /**
     * 1. All-enrolled multi-line document with distinct scopes.
     */
    public function test_all_enrolled_multi_line_distinct_scopes(): void
    {
        $otherItem = InventoryItem::create([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ2',
            'name' => 'Invoke Item 2',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '20.0000',
            'category_id' => $this->item->category_id,
            'is_active' => true
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock->value,
        ]);

        $g1 = $this->seedGroup($this->item->id);
        $s1 = $this->seedSnapshot($g1, $this->item->id);
        $this->seedState($g1, $s1, $this->item->id);

        $g2 = $this->seedGroup($otherItem->id);
        $s2 = $this->seedSnapshot($g2, $otherItem->id);
        $this->seedState($g2, $s2, $otherItem->id, null, null, '10.0000', '200.0000', '20.0000');

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-1',
            'status' => 'draft'
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '8.0000',
            'quantity_variance' => '-2.0000',
            'unit_cost' => '20.0000',
        ]);

        $approved = $this->adjustmentService->approve($adj->id);

        $this->assertEquals(\Modules\Operations\Inventory\Enums\AdjustmentStatusEnum::Approved, $approved->status);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseCount('cost_ledger_entries', 2);

        $state1 = CostAvcoState::where('item_id', $this->item->id)->first();
        $this->assertEquals('15.0000', $state1->on_hand_quantity);
        $this->assertEquals('160.0000', $state1->carrying_value);

        $state2 = CostAvcoState::where('item_id', $otherItem->id)->first();
        $this->assertEquals('8.0000', $state2->on_hand_quantity);
        $this->assertEquals('160.0000', $state2->carrying_value);
    }

    /**
     * 2. Exact composite-pair scope selection.
     */
    public function test_lock_set_filters_exact_composite_pairs_only(): void
    {
        $otherItem = InventoryItem::create([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ3',
            'name' => 'Invoke Item 3',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '15.0000',
            'category_id' => $this->item->category_id,
            'is_active' => true
        ]);

        $g1 = $this->seedGroup($this->item->id);
        $s1 = $this->seedSnapshot($g1, $this->item->id);
        $this->seedState($g1, $s1, $this->item->id);

        $g2 = $this->seedGroup($otherItem->id);
        $s2 = $this->seedSnapshot($g2, $otherItem->id);
        $this->seedState($g2, $s2, $otherItem->id);

        $requested = [
            ['itemId' => $this->item->id, 'locationId' => $this->location->id],
            ['itemId' => $otherItem->id, 'locationId' => $this->location->id],
        ];

        DB::transaction(function () use ($requested, $otherItem) {
            $map = $this->stateRepository->lockExistingSeededStateSetForAdjustmentScopes(
                $this->property->id,
                $requested
            );

            $this->assertCount(2, $map);

            $key1 = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
            $key2 = "property:{$this->property->id}:location:{$this->location->id}:item:{$otherItem->id}";

            $this->assertArrayHasKey($key1, $map);
            $this->assertArrayHasKey($key2, $map);
        });
    }

    /**
     * 3. Repeated same scope inside one document.
     */
    public function test_repeated_same_scope_updates_in_memory_state(): void
    {
        $g = $this->seedGroup($this->item->id);
        $s = $this->seedSnapshot($g, $this->item->id);
        $this->seedState($g, $s, $this->item->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-2',
            'status' => 'draft'
        ]);

        // Line 1: Positive adjustment +5 (Resulting quantity 15, carrying value 160, WAUC 10.6667)
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        // Line 2: Negative adjustment -3 (Must use updated WAUC of 10.6667)
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000', // Mock system matches first line initial
            'quantity_physical' => '7.0000',
            'quantity_variance' => '-3.0000',
            'unit_cost' => '20.0000',
        ]);

        // Temporarily bypass BR-065 staleness validation in this test by updating physical stock balance
        // so that the second line's system quantity check is bypassed by resetting system_quantity on lines
        // or mock stock balance update. Let's update stock balance for the second line check.
        // Wait, the validation in approve() does:
        // foreach ($sortedLines as $line) {
        //     $balance = $this->stockRepository->createOrLockControlled(...);
        //     if ($balance->physical_quantity !== $line->quantity_system) { throw ValidationException; }
        // }
        // For line 1: system=10. Stock starts at 10. (OK)
        // For line 2: system=10. Stock is still 10 before transaction writes. (OK)
        // This is perfectly correct! Both line system quantities are checked before any writes occur!

        $approved = $this->adjustmentService->approve($adj->id);

        $this->assertEquals(\Modules\Operations\Inventory\Enums\AdjustmentStatusEnum::Approved, $approved->status);
        $this->assertDatabaseCount('cost_ledger_entries', 2);

        $state = CostAvcoState::where('item_id', $this->item->id)->first();
        // Quantity: 10 + 5 - 3 = 12.0000
        // Carrying Value: 100 + 60 - 32.0001 = 127.9999
        $this->assertEquals('12.0000', $state->on_hand_quantity);
        $this->assertEquals('128.0000', $state->carrying_value); // Rounded to decimal(15,4)
    }

    /**
     * 4. AdjustmentIn authority uses approved line cost.
     */
    public function test_adjustment_in_uses_line_cost(): void
    {
        $g = $this->seedGroup($this->item->id);
        $s = $this->seedSnapshot($g, $this->item->id);
        $this->seedState($g, $s, $this->item->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-3',
            'status' => 'draft'
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '15.0000', // Line cost 15.0000 is authority, state WAUC is 10.0000
        ]);

        $this->adjustmentService->approve($adj->id);

        $tx = InventoryTransaction::where('transaction_type', 'adjustment_in')->first();
        $this->assertEquals('15.0000', $tx->unit_cost);
    }

    /**
     * 5. AdjustmentOut authority uses locked state WAUC.
     */
    public function test_adjustment_out_uses_locked_state_wauc(): void
    {
        $g = $this->seedGroup($this->item->id);
        $s = $this->seedSnapshot($g, $this->item->id);
        $this->seedState($g, $s, $this->item->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-4',
            'status' => 'draft'
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '8.0000',
            'quantity_variance' => '-2.0000',
            'unit_cost' => '99.0000', // Line cost 99.0000 must be ignored on AdjustmentOut
        ]);

        $this->adjustmentService->approve($adj->id);

        $tx = InventoryTransaction::where('transaction_type', 'adjustment_out')->first();
        $this->assertEquals('10.0000', $tx->unit_cost); // Must use locked state WAUC (10.0000)
    }

    /**
     * 6. Mixed authority fails.
     */
    public function test_mixed_authority_fails(): void
    {
        $otherItem = InventoryItem::create([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ4',
            'name' => 'Invoke Item 4',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '15.0000',
            'category_id' => $this->item->category_id,
            'is_active' => true
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock->value,
        ]);

        // Item 1 is enrolled, Item 2 is unenrolled
        $this->seedGroup($this->item->id, 'enrolled');

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-5',
            'status' => 'draft'
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '20.0000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed enrolled and unenrolled item authority');

        $this->adjustmentService->approve($adj->id);
    }

    /**
     * 7. All-unenrolled preserves legacy behavior.
     */
    public function test_all_unenrolled_preserves_legacy(): void
    {
        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-6',
            'status' => 'draft'
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $this->adjustmentService->approve($adj->id);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 0); // Unenrolled path writes no Cost Ledger entries
    }

    /**
     * 8. Later-line rollback via numeric overflow.
     */
    public function test_later_line_numeric_overflow_rolls_back_everything(): void
    {
        $otherItem = InventoryItem::create([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ5',
            'name' => 'Invoke Item 5',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'category_id' => $this->item->category_id,
            'is_active' => true
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '99999999999.0000',
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock->value,
        ]);

        $g1 = $this->seedGroup($this->item->id);
        $s1 = $this->seedSnapshot($g1, $this->item->id);
        $this->seedState($g1, $s1, $this->item->id);

        $g2 = $this->seedGroup($otherItem->id);
        $s2 = $this->seedSnapshot($g2, $otherItem->id);
        // Seed other item's state with max quantity: 99999999999.0000
        $this->seedState($g2, $s2, $otherItem->id, null, null, '99999999999.0000', '100.0000', '0.0000');

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-7',
            'status' => 'draft'
        ]);

        // Line 1: Normal positive adjustment (OK)
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        // Line 2: Positive adjustment that triggers numeric overflow on persist (fails!)
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '99999999999.0000',
            'quantity_physical' => '100000000000.0000',
            'quantity_variance' => '1.0000',
            'unit_cost' => '10.0000',
        ]);

        try {
            $this->adjustmentService->approve($adj->id);
            $this->fail('Should have failed due to database numeric overflow.');
        } catch (\PDOException $e) {
            $this->assertEquals('22003', $e->getCode());
        }

        // Verify full rollback: no transactions, no ledger entries, no state mutations
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cost_ledger_entries', 0);

        $state1 = CostAvcoState::where('item_id', $this->item->id)->first();
        $this->assertEquals('10.0000', $state1->on_hand_quantity);

        $state2 = CostAvcoState::where('item_id', $otherItem->id)->first();
        $this->assertEquals('99999999999.0000', $state2->on_hand_quantity);
    }

    /**
     * 9. Replay check.
     */
    public function test_replay_prevents_duplicate_processing(): void
    {
        $g = $this->seedGroup($this->item->id);
        $s = $this->seedSnapshot($g, $this->item->id);
        $this->seedState($g, $s, $this->item->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-8',
            'status' => 'draft'
        ]);

        $line = $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_physical' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $this->adjustmentService->approve($adj->id);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        // Run approve again on the same adjustment ID
        // The service should bypass processing due to idempotency transaction checks
        $this->adjustmentService->approve($adj->id);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
    }

    /**
     * 10. No production service outside AdjustmentService invokes ControlledAdjustmentValuationInvocationService.
     */
    public function test_no_production_service_references_invocation_service(): void
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
            if (str_contains($path, 'ControlledAdjustmentValuationInvocationService.php') ||
                str_contains($path, 'AdjustmentService.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledAdjustmentValuationInvocationService')) {
                $callers[] = $path;
            }
        }

        $this->assertEmpty($callers, 'Invocation service has unauthorized production callers!');
    }
}
