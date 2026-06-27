<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Shared\Services\CurrentPropertyService;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Illuminate\Validation\ValidationException;
use Shared\Exceptions\BusinessLogicException;

class ControlledInventoryAdjustmentPostingTest extends PostgresTestCase
{
    use RefreshDatabase;
    protected $seed = true;

    private Property $property;
    private User $user;
    private InventoryItem $item1;
    private InventoryItem $item2;
    private InventoryLocation $location;
    private AdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->user = User::first();
        $this->actingAs($this->user);

        // Open Business Date and Financial Period
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id
            ]
        );

        FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth()
            ]
        );

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General'
        ]);

        $this->item1 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-ADJ-001',
            'name' => 'Adjustment Item 1',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->item2 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-ADJ-002',
            'name' => 'Adjustment Item 2',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 20.00,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Warehouse',
            'type' => 'internal',
        ]);

        // Seed initial physical quantities
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item1->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 100,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item2->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 50,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $this->service = app(AdjustmentService::class);
    }

    public function test_positive_adjustment_success(): void
    {
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-POS-001',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 105,
            'quantity_variance' => 5,
            'unit_cost' => 12.00, // positive adjustment stamps line cost if provided
        ]);

        $this->service->approve($adjustment->id);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $adjustment->fresh()->status);
        $this->assertEquals($this->user->id, $adjustment->fresh()->approved_by);

        // Verify stock card mutation
        $stock = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(105, $stock->physical_quantity);

        // Verify ledgers created
        $txs = InventoryTransaction::where('source_document_id', $adjustment->id)->get();
        $this->assertCount(1, $txs);

        $tx = $txs->first();
        $this->assertEquals(TransactionTypeEnum::AdjustmentIn, $tx->transaction_type);
        $this->assertEquals(5, $tx->quantity_change);
        $this->assertEquals(12.00, (float) $tx->unit_cost);
        $this->assertEquals(60.00, (float) $tx->total_cost);
        $this->assertEquals($this->location->id, $tx->location_id);
        $this->assertEquals($this->user->id, $tx->posted_by);
    }

    public function test_negative_adjustment_success(): void
    {
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-NEG-001',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 90,
            'quantity_variance' => -10,
            'unit_cost' => 12.00, // should be ignored for negative; WAC used instead
        ]);

        $this->service->approve($adjustment->id);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $adjustment->fresh()->status);

        // Verify stock card mutation
        $stock = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(90, $stock->physical_quantity);

        // Verify ledgers created
        $txs = InventoryTransaction::where('source_document_id', $adjustment->id)->get();
        $this->assertCount(1, $txs);

        $tx = $txs->first();
        $this->assertEquals(TransactionTypeEnum::AdjustmentOut, $tx->transaction_type);
        $this->assertEquals(-10, $tx->quantity_change);
        $this->assertEquals(10.00, (float) $tx->unit_cost); // WAC from setUp is 10.00
        $this->assertEquals(-100.00, (float) $tx->total_cost);
        $this->assertEquals($this->location->id, $tx->location_id);
    }

    public function test_idempotent_repeat_protection(): void
    {
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-IDEM-001',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 105,
            'quantity_variance' => 5,
        ]);

        $this->service->approve($adjustment->id);

        $stockBefore = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first()->physical_quantity;
        $txCountBefore = InventoryTransaction::where('source_document_id', $adjustment->id)->count();

        // Reset state back to submitted to simulate re-posting
        $adjustment->refresh()->update(['status' => AdjustmentStatusEnum::Submitted->value]);

        $this->service->approve($adjustment->id);

        $stockAfter = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first()->physical_quantity;
        $txCountAfter = InventoryTransaction::where('source_document_id', $adjustment->id)->count();

        $this->assertEquals($stockBefore, $stockAfter);
        $this->assertEquals($txCountBefore, $txCountAfter);
        $this->assertEquals(AdjustmentStatusEnum::Approved, $adjustment->fresh()->status);
    }

    public function test_closed_business_date_rejects_adjustment(): void
    {
        // Close the business date
        PropertyBusinessDate::where('property_id', $this->property->id)
            ->update([
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Closed,
                'is_open' => null
            ]);

        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-CLOSED-BD',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 105,
            'quantity_variance' => 5,
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->service->approve($adjustment->id);
        } finally {
            $this->assertEquals(AdjustmentStatusEnum::Submitted, $adjustment->fresh()->status);
            $stock = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first()->physical_quantity;
            $this->assertEquals(100, $stock);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $adjustment->id)->get());
        }
    }

    public function test_failure_atomicity_rolls_back_entire_adjustment(): void
    {
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-ATOM-001',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        // Line 1 is valid (system qty 100 vs physical qty 100, variance +5)
        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 105,
            'quantity_variance' => 5,
        ]);

        // Line 2 is stale (system qty 50, but we will modify physical qty to 45 so system quantity mismatch occurs)
        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item2->id,
            'quantity_system' => 50,
            'quantity_actual' => 55,
            'quantity_variance' => 5,
        ]);

        // Change the actual stock to 45 to trigger a staleness failure
        InventoryStock::where('item_id', $this->item2->id)->where('location_id', $this->location->id)->update([
            'physical_quantity' => 45
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/System quantity has changed/');

        try {
            $this->service->approve($adjustment->id);
        } finally {
            $this->assertEquals(AdjustmentStatusEnum::Submitted, $adjustment->fresh()->status);

            // Verify total rollback: Item 1 should remain at 100 (not 105)
            $stock1 = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->location->id)->first()->physical_quantity;
            $this->assertEquals(100, $stock1);

            // Item 2 remains at 45
            $stock2 = InventoryStock::where('item_id', $this->item2->id)->where('location_id', $this->location->id)->first()->physical_quantity;
            $this->assertEquals(45, $stock2);

            $this->assertCount(0, InventoryTransaction::where('source_document_id', $adjustment->id)->get());
        }
    }

    public function test_actor_compatibility_approve_with_caller_supplied_user(): void
    {
        auth()->logout();

        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-ACTOR-001',
            'location_id' => $this->location->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item1->id,
            'quantity_system' => 100,
            'quantity_actual' => 105,
            'quantity_variance' => 5,
        ]);

        $externalActorId = (string) \Illuminate\Support\Str::ulid();

        $this->service->approve($adjustment->id, $externalActorId);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $adjustment->fresh()->status);
        $this->assertEquals($externalActorId, $adjustment->fresh()->approved_by);

        $txs = InventoryTransaction::where('source_document_id', $adjustment->id)->get();
        $this->assertCount(1, $txs);
        $this->assertEquals($externalActorId, $txs->first()->posted_by);
    }
}
