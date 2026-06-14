<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Services\StockMovementService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConcurrencyValidationTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private StockMovementService $service;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->service = app(StockMovementService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-CONC-1',
            'name' => 'Concurrency Test Item',
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

    public function test_issue_concurrency_race_condition()
    {
        // Initial stock of 100
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        // Request A issues 80
        DB::transaction(function () {
            $this->service->issue(
                $this->property->id,
                $this->item->id,
                $this->location->id,
                '80',
                null,
                'REQ-A'
            );
        });

        // Request B tries to issue 80, but stock is now 20
        $exceptionThrown = false;
        try {
            DB::transaction(function () {
                $this->service->issue(
                    $this->property->id,
                    $this->item->id,
                    $this->location->id,
                    '80',
                    null,
                    'REQ-B'
                );
            });
        } catch (ValidationException $e) {
            $exceptionThrown = true;
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertTrue($exceptionThrown, 'Expected negative stock exception was not thrown.');
        
        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(20, (float) $stock->physical_quantity);
    }
}
