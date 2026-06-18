<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Services\StockMovementService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;
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
        
        $this->service = app(StockMovementService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-001',
            'name' => 'Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 5.00
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);
    }

    public function test_property_isolation_prevents_cross_property_stock_pollution()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $otherProperty = Property::create(['name' => 'Prop2', 'property_code' => 'P2', 'code' => 'P2', 'company_id' => $this->property->company_id, 'slug' => 'prop2']);
        
        $this->service->receive(
            $otherProperty->id, // Passing wrong property
            $this->item->id,
            $this->location->id,
            '100',
            '5.00'
        );
    }

    public function test_negative_stock_prevention_throws_validation_exception()
    {
        $this->expectException(ValidationException::class);
        
        // Issuing 50 when we have 0
        $this->service->issue(
            $this->property->id,
            $this->item->id,
            $this->location->id,
            '50'
        );
    }

    public function test_status_recomputation_works_correctly()
    {
        // Receive 5 (below reorder point 10) -> LowStock
        $this->service->receive($this->property->id, $this->item->id, $this->location->id, '5', '5.00');
        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(ItemStatusEnum::LowStock, $stock->status);

        // Receive 20 more -> InStock
        $this->service->receive($this->property->id, $this->item->id, $this->location->id, '20', '5.00');
        $stock->refresh();
        $this->assertEquals(ItemStatusEnum::InStock, $stock->status);

        // Issue 25 -> OutOfStock
        $this->service->issue($this->property->id, $this->item->id, $this->location->id, '25');
        $stock->refresh();
        $this->assertEquals(ItemStatusEnum::OutOfStock, $stock->status);
    }
}
