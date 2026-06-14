<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\IssueService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class IssueServiceTest extends TestCase
{
    use RefreshDatabase;

    private IssueService $service;
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
        
        $this->service = app(IssueService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-ISSUE-1',
            'name' => 'Issue Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 15.00
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);
    }

    public function test_issue_post_reduces_stock_appends_ledger_and_uses_wac()
    {
        // Setup initial stock of 10
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-001',
            'status' => IssueStatusEnum::Draft,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 3,
        ]);

        $this->service->post($issue->id);

        $issue->refresh();
        $this->assertEquals(IssueStatusEnum::Posted, $issue->status);

        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(7, (float) $stock->physical_quantity);

        $transaction = InventoryTransaction::where('reference_id', $issue->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(-3, (float) $transaction->quantity_change);
        $this->assertEquals(15.00, (float) $transaction->unit_cost); // Should use item's WAC
        $this->assertEquals(-45.00, (float) $transaction->total_cost);
    }

    public function test_issue_post_blocks_negative_stock()
    {
        // Setup initial stock of 2
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 2,
            'reserved_quantity' => 0,
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-002',
            'status' => IssueStatusEnum::Draft,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 5, // Exceeds balance
        ]);

        $this->expectException(ValidationException::class);
        $this->service->post($issue->id);
    }
}
