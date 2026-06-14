<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Inventory\Services\IssueService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class AVCOValidationTest extends TestCase
{
    use RefreshDatabase;

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
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-AVCO-1',
            'name' => 'AVCO Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 0.00
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);
    }

    private function doReceipt(float $qty, float $cost)
    {
        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'receipt_number' => 'REC-' . uniqid(),
            'status' => \Modules\Operations\Inventory\Enums\ReceiptStatusEnum::Draft,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'unit_cost' => $cost,
        ]);

        return app(ReceiptService::class)->post($receipt->id);
    }

    private function doIssue(float $qty)
    {
        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-' . uniqid(),
            'status' => \Modules\Operations\Inventory\Enums\IssueStatusEnum::Draft,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
        ]);

        return app(IssueService::class)->post($issue->id);
    }

    public function test_scenario_a()
    {
        // Receipt 1: 100 qty @ 10
        $this->doReceipt(100, 10.00);
        $this->item->refresh();
        $this->assertEquals(10.00, (float) $this->item->weighted_average_cost);

        // Receipt 2: 100 qty @ 20
        $this->doReceipt(100, 20.00);
        $this->item->refresh();
        $this->assertEquals(15.00, (float) $this->item->weighted_average_cost);

        // Issue: 50 qty
        $issue = $this->doIssue(50);
        
        $transaction = InventoryTransaction::where('reference_id', $issue->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(15.00, (float) $transaction->unit_cost);
    }

    public function test_scenario_b()
    {
        // Receipt: 100 qty @ 10
        $this->doReceipt(100, 10.00);
        
        // Issue: 50 qty
        $this->doIssue(50);

        $this->item->refresh();
        $this->assertEquals(10.00, (float) $this->item->weighted_average_cost);

        // Receipt: 100 qty @ 20
        $this->doReceipt(100, 20.00);

        // Expected AVCO: 
        // old qty = 50, old WAC = 10 -> value 500
        // new qty = 100, new cost = 20 -> value 2000
        // total value = 2500, total qty = 150
        // new WAC = 2500 / 150 = 16.666...
        
        $this->item->refresh();
        $this->assertEquals(round(2500/150, 2), round((float) $this->item->weighted_average_cost, 2));
    }
}
