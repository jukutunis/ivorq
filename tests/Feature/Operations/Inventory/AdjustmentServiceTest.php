<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class AdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdjustmentService $service;
    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->service = app(AdjustmentService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-ADJ-1',
            'name' => 'Adjustment Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 20.00
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Store C',
            'type' => 'internal',
        ]);
    }

    public function test_adjustment_approve_processes_positive_variance()
    {
        // System thinks we have 10, we found 12 (variance +2)
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-001',
            'status' => AdjustmentStatusEnum::Submitted,
            'location_id' => $this->location->id,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item->id,
            'quantity_system' => 10,
            'quantity_actual' => 12,
            'quantity_variance' => 2,
            'unit_cost' => null, // Should fallback to WAC
        ]);

        $this->service->approve($adjustment->id);

        $adjustment->refresh();
        $this->assertEquals(AdjustmentStatusEnum::Approved, $adjustment->status);

        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(12, (float) $stock->physical_quantity);

        $transaction = InventoryTransaction::where('reference_id', $adjustment->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(2, (float) $transaction->quantity_change);
        $this->assertEquals(TransactionTypeEnum::AdjustmentIn, $transaction->transaction_type);
        $this->assertEquals(20.00, (float) $transaction->unit_cost);
    }

    public function test_adjustment_approve_fails_on_staleness()
    {
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 15, // Actual DB says 15
            'reserved_quantity' => 0,
        ]);

        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'ADJ-002',
            'status' => AdjustmentStatusEnum::Submitted,
            'location_id' => $this->location->id,
        ]);

        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item->id,
            'quantity_system' => 10, // Line was created when system had 10
            'quantity_actual' => 12,
            'quantity_variance' => 2,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->approve($adjustment->id);
    }
}
