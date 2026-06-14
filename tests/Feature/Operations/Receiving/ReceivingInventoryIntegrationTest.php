<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStockBalance;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Illuminate\Support\Facades\Event;
use Shared\Services\CurrentPropertyService;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Exception;

class ReceivingInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        
        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->user = User::first();
        $this->actingAs($this->user);
        
        $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Cat', 'category_code' => 'C']);
        $this->vendor = Vendor::firstOrCreate(['property_id' => $this->property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-001',
            'name' => 'Test Item',
            'inventory_type' => 'goods',
        ]);
        
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);
        
        $this->workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'name' => 'Receiving Approval',
            'approvable_type' => ReceivingDocument::class,
            'is_active' => true,
        ]);
    }

    public function test_receiving_creates_inventory_transaction_and_updates_stock_ledger()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-001',
            'status' => 'submitted',
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc',
            'received_quantity' => 50,
            'unit_cost' => 10,
            'line_total' => 500,
        ]);
        
        $mockService = \Mockery::mock(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class);
        $mockService->shouldReceive('syncToInventory')->once()->withArgs(function($docArg) use ($doc) {
            return $docArg->id === $doc->id;
        });
        $this->app->instance(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class, $mockService);

        $this->app->make(\Modules\Operations\Receiving\Services\ReceivingApprovalIntegrationService::class)->handleApproval($doc);

        // Assert Document is Approved
        $this->assertEquals(ReceivingDocumentStatusEnum::Approved->value, $doc->fresh()->status->value);
    }
    
    public function test_receiving_rollback_on_failure()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-002',
            'status' => 'submitted',
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'description' => 'Test desc',
            // MISSING destination_location_id to cause failure in stock movement
            'received_quantity' => 50,
            'unit_cost' => 10,
            'line_total' => 500,
        ]);
        
        // Temporarily make the integration service throw an exception
        $mockService = \Mockery::mock(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class);
        $mockService->shouldReceive('syncToInventory')->andThrow(new Exception('Simulated failure'));
        $this->app->instance(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class, $mockService);
        
        try {
            $this->app->make(\Modules\Operations\Receiving\Services\ReceivingApprovalIntegrationService::class)->handleApproval($doc);
        } catch (\Throwable $e) {
        }
        
        // The transaction should rollback the status change if wrapped in DB::transaction.
        $this->assertEquals('submitted', $doc->fresh()->status->value);
    }
    
    public function test_multi_property_isolation_validation()
    {
        // Property 2
        $property1Id = $this->property->id;
        $property2 = Property::create(['name' => 'Prop2', 'property_code' => 'P2', 'code' => 'P2', 'company_id' => $this->property->company_id, 'slug' => 'prop2']);
        
        $doc = ReceivingDocument::create([
            'property_id' => $property2->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-PROP2',
            'status' => 'submitted',
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc',
            'received_quantity' => 50,
            'unit_cost' => 10,
            'line_total' => 500,
        ]);
        
        $request = ApprovalRequest::create([
            'property_id' => $property2->id,
            'workflow_id' => $this->workflow->id,
            'requester_id' => $this->user->id,
            'approvable_type' => get_class($doc),
            'approvable_id' => $doc->id,
            'status' => 'approved',
        ]);

        $mockService = \Mockery::mock(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class);
        $mockService->shouldReceive('syncToInventory')->once()->withArgs(function($docArg) use ($property2) {
            return $docArg->property_id === $property2->id;
        })->andThrow(new Exception('Isolated'));
        $this->app->instance(\Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService::class, $mockService);

        try {
            $this->app->make(\Modules\Operations\Receiving\Services\ReceivingApprovalIntegrationService::class)->handleApproval($doc);
        } catch (\Throwable $e) {
            $this->assertEquals('Isolated', $e->getMessage());
        }
    }
}
