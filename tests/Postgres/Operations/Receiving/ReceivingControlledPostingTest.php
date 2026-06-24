<?php

namespace Tests\Postgres\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Shared\Services\CurrentPropertyService;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Operations\Receiving\Listeners\ReceivingApprovalListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Exception;
use RuntimeException;

class ReceivingControlledPostingTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->user = User::first();
        $this->approver = User::firstOrCreate(
            ['email' => 'approver@ivorq.test'],
            ['name' => 'Approver', 'password' => \Illuminate\Support\Facades\Hash::make('password')]
        );
        $this->approver->properties()->attach($this->property->id);
        
        $this->actingAs($this->user);

        // Open Business Date and Financial Period
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            ['status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open, 'is_open' => true, 'opened_at' => now(), 'opened_by' => $this->user->id]
        );

        FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            ['status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Open, 'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth()]
        );
        
        $category = VendorCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Cat', 'category_code' => 'C']);
        $this->vendor = Vendor::firstOrCreate(['property_id' => $this->property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T']);
        
        $invCategory = InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-001',
            'name' => 'Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
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

        $this->step = \Modules\Foundation\Approval\Models\ApprovalStep::create([
            'workflow_id' => $this->workflow->id,
            'name' => 'Step 1',
            'sequence' => 1,
            'required_approvals' => 1,
        ]);
    }

    private function triggerApproval(ReceivingDocument $doc)
    {
        $request = ApprovalRequest::create([
            'property_id' => $this->property->id,
            'workflow_id' => $this->workflow->id,
            'requester_id' => $this->user->id,
            'approvable_type' => get_class($doc),
            'approvable_id' => $doc->id,
            'status' => 'approved',
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        ApprovalAction::create([
            'approval_request_id' => $request->id,
            'approval_step_id' => $this->step->id,
            'user_id' => $this->approver->id,
            'action_type' => 'approve',
            'notes' => 'Approved OK',
        ]);

        $event = new ApprovalApproved($request);
        $listener = app(ReceivingApprovalListener::class);
        $listener->handleApproved($event);
    }

    public function test_single_line_approved_receiving_produces_one_controlled_transaction_and_stock_change()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-001',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc',
            'received_quantity' => 50,
            'unit_cost' => 12.00,
            'line_total' => 600.00,
        ]);

        $this->triggerApproval($doc);

        $this->assertEquals(ReceivingDocumentStatusEnum::Approved->value, $doc->fresh()->status->value);

        $txs = InventoryTransaction::where('source_document_id', $doc->id)->get();
        $this->assertCount(1, $txs);
        $this->assertEquals(50, $txs->first()->quantity_change);
        
        // actor is approver, not requester
        $this->assertEquals($this->approver->id, $txs->first()->posted_by);

        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(50, $stock->physical_quantity);
        
        // AVCO calculation
        // old wac 10.00, old qty 0
        // new wac should be 12.00
        $this->assertEquals(12.00, $this->item->fresh()->weighted_average_cost);
    }

    public function test_multi_line_approved_receiving_processes_atomically()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-002',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc 1',
            'received_quantity' => 10,
            'unit_cost' => 10,
            'line_total' => 100,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc 2',
            'received_quantity' => 20,
            'unit_cost' => 13,
            'line_total' => 260,
        ]);

        $this->triggerApproval($doc);

        $txs = InventoryTransaction::where('source_document_id', $doc->id)->get();
        $this->assertCount(2, $txs);

        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(30, $stock->physical_quantity);

        // new wac: (10*10 + 20*13) / 30 = 360 / 30 = 12.00
        $this->assertEquals(12.00, $this->item->fresh()->weighted_average_cost);
    }

    public function test_same_idempotency_key_replay_does_not_duplicate()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-003',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc 3',
            'received_quantity' => 50,
            'unit_cost' => 12.00,
            'line_total' => 600.00,
        ]);

        $this->triggerApproval($doc);

        $txsBefore = InventoryTransaction::count();
        $stockBefore = InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity;

        // Try triggering approval again
        $this->triggerApproval($doc);

        // Should just return the existing, not create a new one
        $txsAfter = InventoryTransaction::count();
        $stockAfter = InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity;

        $this->assertEquals($txsBefore, $txsAfter);
        $this->assertEquals($stockBefore, $stockAfter);
    }

    public function test_closed_business_date_rejects_without_partial_change()
    {
        // Close the business date
        PropertyBusinessDate::where('property_id', $this->property->id)->update(['status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Closed, 'is_open' => null]);

        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-004',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc 3',
            'received_quantity' => 50,
            'unit_cost' => 12.00,
            'line_total' => 600.00,
        ]);

        try {
            $this->triggerApproval($doc);
            $this->fail('Expected exception');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('No open business date', $e->getMessage());
        }

        // Must rollback document status
        $this->assertEquals('submitted', $doc->fresh()->status->value);
        $this->assertCount(0, InventoryTransaction::where('source_document_id', $doc->id)->get());
    }

    public function test_forced_failure_rolls_back_everything()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-005',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->location->id,
            'description' => 'Test desc 5',
            'received_quantity' => 10,
            'unit_cost' => 10,
            'line_total' => 100,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            // MISSING destination_location_id to cause failure in stock creation
            'description' => 'Test desc 6',
            'received_quantity' => 20,
            'unit_cost' => 13,
            'line_total' => 260,
        ]);

        try {
            $this->triggerApproval($doc);
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertEquals('submitted', $doc->fresh()->status->value);
        $this->assertCount(0, InventoryTransaction::where('source_document_id', $doc->id)->get());
    }

    public function test_it_preserves_legacy_property_avco_for_same_item_receiving_lines_across_locations()
    {
        $locationA = $this->location;
        $locationB = \Modules\Operations\Inventory\Models\InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Secondary Store',
            'type' => 'internal',
        ]);

        \Modules\Operations\Inventory\Models\InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $locationA->id,
            'physical_quantity' => 10,
        ]);
        
        \Modules\Operations\Inventory\Models\InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $locationB->id,
            'physical_quantity' => 5,
        ]);

        $secondProperty = Property::create([
            'company_id' => $this->property->company_id,
            'name' => 'Second Property',
            'code' => 'PROP-2ND',
            'property_code' => 'PROP2ND',
            'slug' => 'second-property',
        ]);

        $locationSec = \Modules\Operations\Inventory\Models\InventoryLocation::create([
            'property_id' => $secondProperty->id,
            'name' => 'Second Property Store',
            'type' => 'internal',
        ]);

        \Modules\Operations\Inventory\Models\InventoryStock::create([
            'property_id' => $secondProperty->id,
            'item_id' => $this->item->id,
            'location_id' => $locationSec->id,
            'physical_quantity' => 1000,
        ]);

        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-INV-006',
            'status' => 'submitted',
            'created_by' => $this->user->id,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $locationA->id,
            'description' => 'Test desc A',
            'received_quantity' => 4,
            'unit_cost' => 20.00,
            'line_total' => 80.00,
        ]);
        
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $locationB->id,
            'description' => 'Test desc B',
            'received_quantity' => 6,
            'unit_cost' => 30.00,
            'line_total' => 180.00,
        ]);

        $oldQuantity = 15;
        $oldWac = 10.00;
        $receiptQuantity = 4 + 6;
        $receiptValue = (4 * 20.00) + (6 * 30.00); 
        $expectedWac = (($oldQuantity * $oldWac) + $receiptValue) / ($oldQuantity + $receiptQuantity);

        $this->triggerApproval($doc);

        $this->assertEquals(16.40, (float) $this->item->fresh()->weighted_average_cost);

        $this->assertNotEquals(20.00, (float) $this->item->fresh()->weighted_average_cost);
        $this->assertNotEquals(30.00, (float) $this->item->fresh()->weighted_average_cost);

        $stockA = \Modules\Operations\Inventory\Models\InventoryStock::where('item_id', $this->item->id)->where('location_id', $locationA->id)->first();
        $this->assertEquals(14, (float) $stockA->physical_quantity);

        $stockB = \Modules\Operations\Inventory\Models\InventoryStock::where('item_id', $this->item->id)->where('location_id', $locationB->id)->first();
        $this->assertEquals(11, (float) $stockB->physical_quantity);

        $txs = InventoryTransaction::where('source_document_id', $doc->id)->get();
        $this->assertCount(2, $txs);

        $lineIds = $doc->lines->pluck('id')->toArray();
        $this->assertEquals('receiving_document', $txs[0]->source_document_type);
        $this->assertEquals('receiving_line', $txs[0]->source_line_type);
        $this->assertContains($txs[0]->source_line_id, $lineIds);
        
        $this->assertEquals('receiving_document', $txs[1]->source_document_type);
        $this->assertEquals('receiving_line', $txs[1]->source_line_type);
        $this->assertContains($txs[1]->source_line_id, $lineIds);

        $this->assertEquals($this->approver->id, $txs[0]->posted_by);
        $this->assertEquals($this->approver->id, $txs[1]->posted_by);

        $txA = $txs->firstWhere('location_id', $locationA->id);
        $txB = $txs->firstWhere('location_id', $locationB->id);

        $this->assertNotNull($txA->unit_cost);
        $this->assertNotNull($txB->unit_cost);

        $this->assertEquals(20.00, (float) $txA->unit_cost);
        $this->assertEquals(80.00, (float) $txA->total_cost);

        $this->assertEquals(30.00, (float) $txB->unit_cost);
        $this->assertEquals(180.00, (float) $txB->total_cost);

        $legacyReceiptCount = \Illuminate\Support\Facades\DB::table('inventory_receipts')
            ->where('receipt_number', $doc->grn_number)
            ->count();
        $this->assertEquals(0, $legacyReceiptCount);

        $this->assertEquals(0, InventoryTransaction::where('property_id', $secondProperty->id)->count());
    }
}
