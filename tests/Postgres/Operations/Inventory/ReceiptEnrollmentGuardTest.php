<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService;
use RuntimeException;
use Tests\PostgresTestCase;

class ReceiptEnrollmentGuardTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private ReceiptService $receiptService;
    private InventoryReceiptIntegrationService $integrationService;
    private CostAuthorityEnrollmentRepository $enrollmentRepo;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private Vendor $vendor;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptService     = app(ReceiptService::class);
        $this->integrationService = app(InventoryReceiptIntegrationService::class);
        $this->enrollmentRepo     = app(CostAuthorityEnrollmentRepository::class);

        $this->property = Property::first();
        $this->actorId  = (string) Str::ulid();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Receipt Guard Test Category',
        ]);

        $this->item = InventoryItem::firstOrCreate(
            ['property_id' => $this->property->id, 'sku' => 'GUARD-RCPT-001'],
            [
                'category_id'           => $category->id,
                'name'                  => 'Guard Receipt Test Item',
                'inventory_type'        => 'goods',
                'weighted_average_cost' => '10.00',
            ]
        );

        $this->location = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Guard Receipt Test Location'],
            ['type' => 'internal']
        );

        $vendorCategory = VendorCategory::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Guard Test Vendor Category'],
            ['category_code' => 'GVC-RCPT']
        );

        $this->vendor = Vendor::firstOrCreate(
            ['property_id' => $this->property->id, 'vendor_code' => 'GTV-RCPT-001'],
            ['vendor_category_id' => $vendorCategory->id, 'name' => 'Guard Receipt Test Vendor']
        );
    }

    // -------------------------------------------------------------------------
    // Proof 1: No enrollment group — ReceiptService::post() proceeds normally
    // -------------------------------------------------------------------------
    public function test_receipt_service_remains_available_with_no_enrollment_group(): void
    {
        $receipt = $this->makeDraftReceipt();

        $posted = $this->receiptService->post($receipt->id);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertEquals(
            1,
            DB::table('inventory_transactions')
                ->where('reference_id', $receipt->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 2: APPROVED enrollment group — ReceiptService::post() proceeds
    //          (only ENROLLED status blocks)
    // -------------------------------------------------------------------------
    public function test_receipt_service_remains_available_for_approved_enrollment_group(): void
    {
        $this->createApprovedGroup($this->property->id, $this->item->id);

        $receipt = $this->makeDraftReceipt();

        $posted = $this->receiptService->post($receipt->id);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertEquals(
            1,
            DB::table('inventory_transactions')
                ->where('reference_id', $receipt->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 3 + 6a: ENROLLED matching group — ReceiptService::post() fails closed
    //               before any mutation occurs
    // -------------------------------------------------------------------------
    public function test_receipt_service_blocks_before_mutation_for_enrolled_matching_group(): void
    {
        $this->createEnrolledGroup($this->property->id, $this->item->id);

        $receipt = $this->makeDraftReceipt();

        $txBefore          = DB::table('inventory_transactions')->where('reference_id', $receipt->id)->count();
        $stockBefore       = DB::table('inventory_stocks')->where('item_id', $this->item->id)->count();
        $wacBefore         = (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost');
        $avcoStatesBefore  = DB::table('cost_avco_states')->where('property_id', $this->property->id)->count();
        $outboxBefore      = DB::table('outbox_messages')->count();
        $ledgerBefore      = DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count();
        $candidatesBefore  = DB::table('journal_candidates')->count();
        $enrollsBefore     = DB::table('cost_authority_enrollment_groups')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('status', 'enrolled')
            ->count();

        try {
            $this->receiptService->post($receipt->id);
            $this->fail('Expected RuntimeException from enrollment guard; none thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CostControl authority is enrolled', $e->getMessage());
        }

        // Receipt status unchanged — still draft
        $this->assertEquals('draft', DB::table('inventory_receipts')->where('id', $receipt->id)->value('status'));

        // No InventoryTransaction created
        $this->assertEquals($txBefore, DB::table('inventory_transactions')->where('reference_id', $receipt->id)->count());

        // No InventoryStock mutation
        $this->assertEquals($stockBefore, DB::table('inventory_stocks')->where('item_id', $this->item->id)->count());

        // No WAC mutation
        $this->assertEquals(
            $wacBefore,
            (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost')
        );

        // No CostAvcoState
        $this->assertEquals($avcoStatesBefore, DB::table('cost_avco_states')->where('property_id', $this->property->id)->count());

        // No Outbox message
        $this->assertEquals($outboxBefore, DB::table('outbox_messages')->count());

        // No Cost Ledger entry
        $this->assertEquals($ledgerBefore, DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count());

        // No JournalCandidate
        $this->assertEquals($candidatesBefore, DB::table('journal_candidates')->count());

        // Enrollment group state unchanged — still enrolled
        $this->assertEquals(
            $enrollsBefore,
            DB::table('cost_authority_enrollment_groups')
                ->where('property_id', $this->property->id)
                ->where('item_id', $this->item->id)
                ->where('status', 'enrolled')
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 4 + 6b: ENROLLED matching group — InventoryReceiptIntegrationService
    //               fails closed before coordinator lock or any mutation
    // -------------------------------------------------------------------------
    public function test_integration_service_blocks_before_mutation_for_enrolled_matching_group(): void
    {
        $this->createEnrolledGroup($this->property->id, $this->item->id);

        $doc = $this->makeReceivingDocument();
        $this->makeReceivingLine($doc, $this->item->id, $this->location->id);

        $txBefore         = DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count();
        $stockBefore      = DB::table('inventory_stocks')->where('item_id', $this->item->id)->count();
        $wacBefore        = (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost');
        $avcoStatesBefore = DB::table('cost_avco_states')->where('property_id', $this->property->id)->count();
        $outboxBefore     = DB::table('outbox_messages')->count();
        $ledgerBefore     = DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count();
        $candidatesBefore = DB::table('journal_candidates')->count();

        try {
            $this->integrationService->syncToInventory($doc, $this->actorId);
            $this->fail('Expected RuntimeException from enrollment guard; none thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CostControl authority is enrolled', $e->getMessage());
        }

        // No InventoryTransaction
        $this->assertEquals($txBefore, DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count());

        // No InventoryStock mutation
        $this->assertEquals($stockBefore, DB::table('inventory_stocks')->where('item_id', $this->item->id)->count());

        // No WAC mutation
        $this->assertEquals(
            $wacBefore,
            (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost')
        );

        // No CostAvcoState
        $this->assertEquals($avcoStatesBefore, DB::table('cost_avco_states')->where('property_id', $this->property->id)->count());

        // No Outbox message
        $this->assertEquals($outboxBefore, DB::table('outbox_messages')->count());

        // No Cost Ledger entry
        $this->assertEquals($ledgerBefore, DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count());

        // No JournalCandidate
        $this->assertEquals($candidatesBefore, DB::table('journal_candidates')->count());

        // Enrollment group unchanged — still enrolled
        $this->assertEquals(
            1,
            DB::table('cost_authority_enrollment_groups')
                ->where('property_id', $this->property->id)
                ->where('item_id', $this->item->id)
                ->where('status', 'enrolled')
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 5a: ENROLLED group for a DIFFERENT item — does not block
    // -------------------------------------------------------------------------
    public function test_enrolled_group_for_different_item_does_not_block(): void
    {
        $differentItemId = (string) Str::ulid();
        $this->createEnrolledGroup($this->property->id, $differentItemId);

        $receipt = $this->makeDraftReceipt();

        // Guard must not fire: enrolled scope is for a different item.
        $posted = $this->receiptService->post($receipt->id);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertEquals(
            1,
            DB::table('inventory_transactions')
                ->where('reference_id', $receipt->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 5b: ENROLLED group for a DIFFERENT property — does not block
    // -------------------------------------------------------------------------
    public function test_enrolled_group_for_different_property_does_not_block(): void
    {
        $differentPropertyId = (string) Str::ulid();
        $this->createEnrolledGroup($differentPropertyId, $this->item->id);

        $receipt = $this->makeDraftReceipt();

        // Guard must not fire: enrolled scope is for a different property.
        $posted = $this->receiptService->post($receipt->id);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertEquals(
            1,
            DB::table('inventory_transactions')
                ->where('reference_id', $receipt->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function makeDraftReceipt(): InventoryReceipt
    {
        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-GUARD-' . Str::ulid(),
            'supplier_name'  => 'Guard Test Supplier',
            'status'         => 'draft',
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->item->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        return $receipt;
    }

    private function makeReceivingDocument(): ReceivingDocument
    {
        return ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id'   => $this->vendor->id,
            'grn_number'  => 'GRN-GUARD-' . Str::ulid(),
            'status'      => 'submitted',
        ]);
    }

    private function makeReceivingLine(
        ReceivingDocument $doc,
        string $itemId,
        string $locationId
    ): ReceivingLine {
        return ReceivingLine::create([
            'receiving_document_id'   => $doc->id,
            'inventory_item_id'       => $itemId,
            'destination_location_id' => $locationId,
            'description'             => 'Guard test receiving line',
            'received_quantity'       => '5.00',
            'unit_cost'               => '12.00',
            'line_total'              => '60.00',
        ]);
    }

    private function makeSnapshot(string $propertyId, string $locationId, string $itemId): array
    {
        return [
            'location_id'            => $locationId,
            'valuation_scope'        => "property:{$propertyId}:location:{$locationId}:item:{$itemId}",
            'opening_quantity'       => '100.0000',
            'opening_carrying_value' => '1000.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'financial_period_id'    => (string) Str::ulid(),
            'evidence_timestamp'     => now(),
        ];
    }

    private function createApprovedGroup(string $propertyId, string $itemId): CostAuthorityEnrollmentGroup
    {
        $locationId = (string) Str::ulid();

        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $propertyId, 'item_id' => $itemId],
            [$this->makeSnapshot($propertyId, $locationId, $itemId)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepo->approve($group->id, $this->actorId, now())
        );

        return CostAuthorityEnrollmentGroup::find($group->id);
    }

    private function createEnrolledGroup(string $propertyId, string $itemId): CostAuthorityEnrollmentGroup
    {
        $locationId = (string) Str::ulid();

        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $propertyId, 'item_id' => $itemId],
            [$this->makeSnapshot($propertyId, $locationId, $itemId)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepo->approve($group->id, $this->actorId, now())
        );

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update([
                'status'      => 'enrolled',
                'enrolled_at' => now(),
                'updated_at'  => now(),
            ]);

        return CostAuthorityEnrollmentGroup::find($group->id);
    }
}
