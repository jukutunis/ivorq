<?php

namespace Tests\Feature\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Modules\Operations\Purchasing\Models\GoodsReceiptLine;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class ReceivingModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected $user;
    protected $property;
    protected $vendor;
    protected $po;
    protected $poLine;
    protected $item;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPurchasingPermissions();
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);
        
        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, ['is_active' => true, 'is_approved' => true]);

        $unit = InventoryUnit::firstOrCreate(
            ['property_id' => $this->property->id, 'unit_code' => 'PCS'],
            ['name' => 'Pieces', 'abbreviation' => 'PCS', 'is_active' => true]
        );
        
        $inventoryCategory = InventoryCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'CAT-001',
            'name' => 'Test Category',
            'is_active' => true,
            'type' => 'Goods',
        ]);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $inventoryCategory->id,
            'unit_id' => $unit->id,
            'item_code' => 'ITM-001',
            'name' => 'Test Item',
            'is_active' => true,
            'is_stockable' => true,
            'average_cost' => 0,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'location_code' => 'LOC-001',
            'name' => 'Main Store',
            'location_type' => 'main_store',
            'is_active' => true,
        ]);

        $pr = $this->createPurchaseRequest($this->property);

        $this->po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'status' => PurchaseOrderStatusEnum::Issued->value,
            'received_total' => 0,
        ]);

        $this->poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $this->po->id,
            'purchase_request_line_id' => $this->createPurchaseRequestLine($pr)->id,
            'inventory_item_id' => $this->item->id,
            'unit_id' => $unit->id,
            'quantity_ordered' => 100,
            'quantity_received' => 0,
            'unit_cost' => 50,
            'line_total' => 5000,
        ]);
    }

    public function test_can_receive_issued_po_and_generates_inventory_transaction()
    {
        $response = $this->actingAs($this->user)->postJson(route('purchasing.goods-receipts.store'), [
            'purchase_order_id' => $this->po->id,
            'received_date' => now()->format('Y-m-d'),
            'remarks' => 'Test Receiving',
            'lines' => [
                [
                    'purchase_order_line_id' => $this->poLine->id,
                    'location_id' => $this->location->id,
                    'quantity_received' => 40,
                ]
            ]
        ]);

        $response->assertStatus(201);
        
        // Assert GRN Created
        $this->assertDatabaseHas('goods_receipts', [
            'purchase_order_id' => $this->po->id,
            'status' => 'Posted',
        ]);
        
        $grnId = $response->json('data.id');

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $grnId,
            'quantity_received' => 40,
        ]);

        // Assert PO status partially received
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $this->po->id,
            'status' => PurchaseOrderStatusEnum::PartiallyReceived->value,
            'received_total' => 2000,
        ]);

        // Assert PO Line quantity received
        $this->assertDatabaseHas('purchase_order_lines', [
            'id' => $this->poLine->id,
            'quantity_received' => 40,
        ]);

        // Assert Inventory Receipt generated
        $this->assertDatabaseHas('inventory_receipts', [
            'property_id' => $this->property->id,
            'external_reference' => $response->json('data.grn_no'),
            'status' => 'posted', // Because ReceiptService->post() is called
        ]);

        // Assert Audit Logs
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => GoodsReceipt::class,
            'event' => 'created'
        ]);
    }

    public function test_cannot_receive_draft_po()
    {
        $this->po->update(['status' => PurchaseOrderStatusEnum::Draft->value]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.goods-receipts.store'), [
            'purchase_order_id' => $this->po->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $this->poLine->id,
                    'location_id' => $this->location->id,
                    'quantity_received' => 40,
                ]
            ]
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Only Issued or Partially Received Purchase Orders can be received.');
    }

    public function test_cannot_receive_more_than_quantity_ordered()
    {
        $response = $this->actingAs($this->user)->postJson(route('purchasing.goods-receipts.store'), [
            'purchase_order_id' => $this->po->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $this->poLine->id,
                    'location_id' => $this->location->id,
                    'quantity_received' => 101, // > 100
                ]
            ]
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Quantity received exceeds quantity ordered or is invalid.');
    }

    public function test_full_receiving_completes_po()
    {
        $response = $this->actingAs($this->user)->postJson(route('purchasing.goods-receipts.store'), [
            'purchase_order_id' => $this->po->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $this->poLine->id,
                    'location_id' => $this->location->id,
                    'quantity_received' => 100,
                ]
            ]
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $this->po->id,
            'status' => PurchaseOrderStatusEnum::FullyReceived->value,
            'received_total' => 5000,
        ]);
    }
}
