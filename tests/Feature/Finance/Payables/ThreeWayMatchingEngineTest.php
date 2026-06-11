<?php

namespace Tests\Feature\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Finance\Payables\Models\VendorInvoiceLine;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Modules\Operations\Purchasing\Models\GoodsReceiptLine;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Models\Vendor;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class ThreeWayMatchingEngineTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected User $user;
    protected Property $property;
    protected Vendor $vendor;
    protected PurchaseOrder $po;
    protected PurchaseOrderLine $poLine;
    protected GoodsReceipt $grn;
    protected GoodsReceiptLine $grnLine;
    protected InventoryItem $item;
    protected InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        $this->seedPurchasingPermissions();

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);

        $this->user->givePermissionTo([
            'payables.vendor-invoice.view',
            'payables.vendor-invoice.create',
            'payables.vendor-invoice.edit',
            'payables.match.create',
            'payables.match.view',
        ]);

        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, ['is_approved' => true]);

        $unit = InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'code' => 'PCS'], ['name' => 'Pieces']);
        $invCat = InventoryCategory::create(['property_id' => $this->property->id, 'name' => 'CAT1']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCat->id,
            'sku' => 'ITM-01',
            'name' => 'Item 1',
            'inventory_type' => 'stock',
            'criticality' => 'medium',
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Store',
            'type' => 'storeroom', ]);

        $pr = $this->createPurchaseRequest($this->property);

        $this->po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'status' => PurchaseOrderStatusEnum::Issued->value,
        ]);

        $this->poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $this->po->id,
            'purchase_request_line_id' => $this->createPurchaseRequestLine($pr)->id,
            'inventory_item_id' => $this->item->id,
            'unit_id' => $unit->id,
            'quantity_ordered' => 10,
            'unit_cost' => 100,
            'line_total' => 1000,
        ]);

        $this->grn = GoodsReceipt::create([
            'property_id' => $this->property->id,
            'purchase_order_id' => $this->po->id,
            'vendor_id' => $this->vendor->id,
            'grn_no' => 'GRN-001',
            'received_date' => now(),
            'status' => 'Posted',
        ]);

        $this->grnLine = GoodsReceiptLine::create([
            'goods_receipt_id' => $this->grn->id,
            'purchase_order_line_id' => $this->poLine->id,
            'inventory_item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity_received' => 10,
            'unit_cost' => 100,
            'line_total' => 1000,
        ]);
    }

    public function test_successful_perfect_match()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $this->po->id,
            'goods_receipt_id' => $this->grn->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]);

        VendorInvoiceLine::factory()->create([
            'vendor_invoice_id' => $invoice->id,
            'purchase_order_line_id' => $this->poLine->id,
            'goods_receipt_line_id' => $this->grnLine->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 10,
            'unit_price' => 100,
            'line_total' => 1000,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/match");

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', MatchStatusEnum::Matched->value);
        $response->assertJsonPath('data.total_quantity_variance', 0);
        $response->assertJsonPath('data.total_price_variance', 0);

        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'status' => VendorInvoiceStatusEnum::Matched->value,
        ]);
    }

    public function test_match_with_quantity_and_price_variance()
    {
        // Invoice bills 12 quantity (we ordered 10, received 10)
        // Invoice bills 110 price (we ordered at 100)
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $this->po->id,
            'goods_receipt_id' => $this->grn->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]);

        VendorInvoiceLine::factory()->create([
            'vendor_invoice_id' => $invoice->id,
            'purchase_order_line_id' => $this->poLine->id,
            'goods_receipt_line_id' => $this->grnLine->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 12,
            'unit_price' => 110,
            'line_total' => 1320,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/match");

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', MatchStatusEnum::MatchedWithVariance->value);
        
        // Qty Variance = Inv (12) - GRN (10) = 2
        $response->assertJsonPath('data.total_quantity_variance', 2);
        
        // Price Variance = Inv (110) - PO (100) = 10
        $response->assertJsonPath('data.total_price_variance', 10);

        // Amount Variance = Billed (1320) - Expected (10 * 100 = 1000) = 320
        $response->assertJsonPath('data.total_amount_variance', 320);

        // Invoice status becomes matched per rules
        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'status' => VendorInvoiceStatusEnum::Matched->value,
        ]);
    }

    public function test_matching_fails_without_po_or_grn()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]); // Missing PO and GRN

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/match");

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', MatchStatusEnum::Exception->value);
        $response->assertJsonPath('data.exception_code', 'MissingPurchaseOrder');

        // Invoice status remains Submitted
        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'status' => VendorInvoiceStatusEnum::Submitted->value,
        ]);
    }
}
