<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class ReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptService $service;
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
        
        $this->service = app(ReceiptService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-002',
            'name' => 'Test Item 2',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 10.00
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);
    }

    public function test_avco_calculation_and_ledger_append()
    {
        // Give initial stock via direct movement to setup AVCO scenario
        // 10 units @ 10.00
        app(\Modules\Operations\Inventory\Services\StockMovementService::class)->receive(
            $this->property->id,
            $this->item->id,
            $this->location->id,
            '10',
            '10.00'
        );

        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'status' => ReceiptStatusEnum::Draft,
            'receipt_number' => 'RC-001',
        ]);

        InventoryReceiptLine::create([
            'receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'unit_cost' => 16.00, // new receipt cost
        ]);

        $this->service->post($receipt->id);

        $this->item->refresh();

        // Old AVCO: 10 * 10.00 = 100.00
        // Receipt: 20 * 16.00 = 320.00
        // New Total Value = 420.00
        // New Total Qty = 30
        // New AVCO = 420 / 30 = 14.00

        $this->assertEquals(14.00, (float) $this->item->weighted_average_cost);

        $ledgerCount = InventoryTransaction::where('reference_id', $receipt->id)->count();
        $this->assertEquals(1, $ledgerCount);
    }
}
