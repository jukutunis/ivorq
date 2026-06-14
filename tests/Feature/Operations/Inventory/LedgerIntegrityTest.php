<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\StockMovementService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    public function test_ledger_integrity_maintains_balance()
    {
        $property = Property::first();
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($property->id);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $item = InventoryItem::create([
            'property_id' => $property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-003',
            'name' => 'Test Item 3',
            'inventory_type' => 'goods',
            'is_active' => true,
        ]);
        
        $location = InventoryLocation::create([
            'property_id' => $property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);

        $service = app(StockMovementService::class);

        // Receipt +100
        $service->receive($property->id, $item->id, $location->id, '100', '10.00');
        // Issue -20
        $service->issue($property->id, $item->id, $location->id, '20');
        // Issue -30
        $service->issue($property->id, $item->id, $location->id, '30');
        // Adjustment +10
        $service->adjust($property->id, $item->id, $location->id, '10');

        $ledgerSum = InventoryTransaction::where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->sum('quantity_change');

        $stockBalance = InventoryStock::where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('physical_quantity');

        // Expected 100 - 20 - 30 + 10 = 60
        $this->assertEquals(60, $ledgerSum);
        $this->assertEquals(60, $stockBalance);
    }
}
