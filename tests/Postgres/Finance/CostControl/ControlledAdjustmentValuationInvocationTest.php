<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationInvocationService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\ValueObjects\InventoryAdjustmentIdempotencyKey;
use RuntimeException;
use Tests\PostgresTestCase;

class ControlledAdjustmentValuationInvocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private InventoryItem $item;

    private InventoryLocation $location;

    private AdjustmentService $adjustmentService;

    private CostAvcoStateRepository $stateRepository;

    private array $requestedGroupStatuses = [];

    private string $businessDate = '2026-06-28';

    private string $occurredAt = '2026-06-28 12:00:00';

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->location = InventoryLocation::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Invocation Warehouse',
            'type' => 'internal',
        ]);

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Invocation Category',
        ]);

        $this->item = InventoryItem::firstOrCreate([
            'property_id' => $this->property->id,
            'sku' => 'ITM-INVOKE-ADJ1',
            'name' => 'Invoke Adjustment Item 1',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->adjustmentService = app(AdjustmentService::class);
        $this->stateRepository = app(CostAvcoStateRepository::class);
        $this->actorId = (string) User::query()->firstOrFail()->id;

        DB::table('property_business_dates')->updateOrInsert(
            [
                'property_id' => $this->property->id,
                'business_date' => $this->businessDate,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => PropertyBusinessDateStatusEnum::Open->value,
                'is_open' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('gl_financial_periods')->updateOrInsert(
            [
                'property_id' => $this->property->id,
                'period_year' => now()->year,
                'period_month' => now()->month,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => FinancialPeriodStatusEnum::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Seed stock balance row for testing BR-065 staleness validation
        InventoryStock::firstOrCreate([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
        ], [
            'physical_quantity' => '10.0000',
            'status' => ItemStatusEnum::InStock->value,
            'last_movement_at' => now(),
        ]);
    }

    private function approveAdjustment(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $this->adjustmentService->submit($adjustment->id, $this->actorId);

        return $this->adjustmentService->approve($adjustment->id, $this->actorId);
    }

    private function seedGroup(string $itemId, string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requestedGroupStatuses[$id] = $status;

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
        $this->transitionGroupToRequestedStatus($groupId);

        return $id;
    }

    private function transitionGroupToRequestedStatus(string $groupId): void
    {
        $status = $this->requestedGroupStatuses[$groupId] ?? 'draft';
        if ($status === 'draft') {
            return;
        }

        DB::table('cost_authority_enrollment_groups')->where('id', $groupId)->update([
            'status' => 'approved',
            'approved_by' => (string) Str::ulid(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        if ($status === 'enrolled') {
            DB::table('cost_authority_enrollment_groups')->where('id', $groupId)->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cost_delivery_mode_ownerships')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'item_id' => DB::table('cost_authority_enrollment_groups')->where('id', $groupId)->value('item_id'),
                'enrollment_group_id' => $groupId,
                'delivery_mode' => 'SYNCHRONOUS',
                'ownership_version' => 1,
                'activated_cutover_id' => null,
                'established_by' => $this->actorId,
                'established_at' => now(),
                'changed_by' => null,
                'changed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
     * Canonical adjustment approval keys fit the narrowest persisted boundary.
     */
    public function test_adjustment_approval_key_is_exact_deterministic_and_bounded(): void
    {
        $adjustmentId = '01J'.str_repeat('0', 23);
        $lineId = '01K'.str_repeat('1', 23);
        $expected = "adj_{$adjustmentId}_{$lineId}_ap";

        $this->assertSame($expected, InventoryAdjustmentIdempotencyKey::approval($adjustmentId, $lineId));
        $this->assertSame($expected, InventoryAdjustmentIdempotencyKey::approval($adjustmentId, $lineId));
        $this->assertSame(60, strlen($expected));
        $this->assertLessThanOrEqual(InventoryAdjustmentIdempotencyKey::MAX_LENGTH, strlen($expected));

        $maximumLength = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'cost_ledger_entries')
            ->where('column_name', 'idempotency_key')
            ->value('character_maximum_length');

        $this->assertSame(64, (int) $maximumLength);
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
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => ItemStatusEnum::InStock->value,
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
            'status' => 'draft',
        ]);

        $line1 = $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $line2 = $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '8.0000',
            'quantity_variance' => '-2.0000',
            'unit_cost' => '20.0000',
        ]);

        $approved = $this->approveAdjustment($adj);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $approved->status);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseCount('cost_ledger_entries', 2);
        $this->assertDatabaseCount('outbox_messages', 2);

        $transactionsByLine = InventoryTransaction::query()
            ->where('source_document_id', $adj->id)
            ->get()
            ->keyBy('source_line_id');
        $keys = [];
        foreach ([$line1, $line2] as $line) {
            $expectedKey = InventoryAdjustmentIdempotencyKey::approval($adj->id, $line->id);
            $transaction = $transactionsByLine->get($line->id);

            $this->assertNotNull($transaction);
            $this->assertSame($expectedKey, $transaction->idempotency_key);
            $this->assertSame(60, strlen($transaction->idempotency_key));
            $this->assertSame(
                $expectedKey,
                DB::table('cost_ledger_entries')
                    ->where('source_inventory_transaction_id', $transaction->id)
                    ->value('idempotency_key')
            );
            $keys[] = $transaction->idempotency_key;
        }
        $this->assertCount(2, array_unique($keys));

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
            'is_active' => true,
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
            'status' => 'draft',
        ]);

        // Line 1 keeps WAUC exactly representable by the Inventory unit-cost scale.
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '10.0000',
        ]);

        // Line 2: Negative adjustment -3 after line 1 has moved stock to 15.
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '15.0000',
            'quantity_actual' => '7.0000',
            'quantity_variance' => '-3.0000',
            'unit_cost' => '20.0000',
        ]);

        $approved = $this->approveAdjustment($adj);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $approved->status);
        $this->assertDatabaseCount('cost_ledger_entries', 2);

        $state = CostAvcoState::where('item_id', $this->item->id)->first();
        // Quantity: 10 + 5 - 3 = 12.0000
        // Carrying Value: 100 + 50 - 30 = 120.
        $this->assertEquals('12.0000', $state->on_hand_quantity);
        $this->assertEquals('120.0000', $state->carrying_value);
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
            'status' => 'draft',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '15.0000', // Line cost 15.0000 is authority, state WAUC is 10.0000
        ]);

        $this->approveAdjustment($adj);

        $tx = InventoryTransaction::where('transaction_type', 'adjustment_in')->first();
        $this->assertEquals('15.00', $tx->unit_cost);
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
            'status' => 'draft',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '8.0000',
            'quantity_variance' => '-2.0000',
            'unit_cost' => '99.0000', // Line cost 99.0000 must be ignored on AdjustmentOut
        ]);

        $this->approveAdjustment($adj);

        $tx = InventoryTransaction::where('transaction_type', 'adjustment_out')->first();
        $this->assertEquals('10.00', $tx->unit_cost); // Must use locked state WAUC (10.0000)
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
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => ItemStatusEnum::InStock->value,
        ]);

        // Item 1 is enrolled, Item 2 is unenrolled
        $groupId = $this->seedGroup($this->item->id, 'enrolled');
        $this->seedSnapshot($groupId, $this->item->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-5',
            'status' => 'draft',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '20.0000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed enrolled and unenrolled item authority');

        $this->approveAdjustment($adj);
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
            'status' => 'draft',
        ]);

        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $this->approveAdjustment($adj);

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
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'location_id' => $this->location->id,
            'physical_quantity' => '10.0000',
            'status' => ItemStatusEnum::InStock->value,
        ]);

        $g1 = $this->seedGroup($this->item->id);
        $s1 = $this->seedSnapshot($g1, $this->item->id);
        $this->seedState($g1, $s1, $this->item->id);

        $g2 = $this->seedGroup($otherItem->id);
        $s2 = $this->seedSnapshot($g2, $otherItem->id);
        $this->seedState($g2, $s2, $otherItem->id);

        $adj = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'adjustment_number' => 'ADJ-INVOKE-7',
            'status' => 'draft',
        ]);

        // Line 1: Normal positive adjustment (OK)
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        // Line 2: source and ledger fit, but the AVCO carrying value overflows decimal(15,4).
        $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $otherItem->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '11.0000',
            'quantity_variance' => '1.0000',
            'unit_cost' => '100000000000.00',
        ]);

        try {
            $this->approveAdjustment($adj);
            $this->fail('Should have failed due to database numeric overflow.');
        } catch (\PDOException $e) {
            $this->assertEquals('22003', $e->getCode());
        }

        // Verify full rollback: no transactions, ledger, Outbox, or state mutations.
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(
            AdjustmentStatusEnum::Submitted,
            $adj->fresh()->status
        );

        $state1 = CostAvcoState::where('item_id', $this->item->id)->first();
        $this->assertEquals('10.0000', $state1->on_hand_quantity);

        $state2 = CostAvcoState::where('item_id', $otherItem->id)->first();
        $this->assertEquals('10.0000', $state2->on_hand_quantity);
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
            'status' => 'draft',
        ]);

        $line = $adj->lines()->create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'quantity_system' => '10.0000',
            'quantity_actual' => '15.0000',
            'quantity_variance' => '5.0000',
            'unit_cost' => '12.0000',
        ]);

        $this->approveAdjustment($adj);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        $transactionId = InventoryTransaction::query()->value('id');
        $stateBeforeReplay = CostAvcoState::where('item_id', $this->item->id)->firstOrFail();
        $this->assertSame(
            InventoryAdjustmentIdempotencyKey::approval($adj->id, $line->id),
            InventoryTransaction::query()->value('idempotency_key')
        );

        // The terminal lifecycle gate rejects document replay before duplicate monetary processing.
        try {
            $this->approveAdjustment($adj);
            $this->fail('Approved adjustment replay should be rejected by the lifecycle gate.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('to Submitted', $exception->getMessage());
        }

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertSame($transactionId, InventoryTransaction::query()->value('id'));
        $stateAfterReplay = CostAvcoState::where('item_id', $this->item->id)->firstOrFail();
        $this->assertSame($stateBeforeReplay->on_hand_quantity, $stateAfterReplay->on_hand_quantity);
        $this->assertSame($stateBeforeReplay->carrying_value, $stateAfterReplay->carrying_value);
        $this->assertSame($stateBeforeReplay->last_valuation_sequence, $stateAfterReplay->last_valuation_sequence);
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
