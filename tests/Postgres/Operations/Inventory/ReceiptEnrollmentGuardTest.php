<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostDeliveryModeOwnershipBootstrapService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
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
use Tests\PostgresTestCase;

class ReceiptEnrollmentGuardTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

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

        $this->travelTo(Carbon::parse('2026-06-28 10:00:00+00'));

        $this->receiptService = app(ReceiptService::class);
        $this->integrationService = app(InventoryReceiptIntegrationService::class);
        $this->enrollmentRepo = app(CostAuthorityEnrollmentRepository::class);

        $this->property = Property::first();
        $this->actorId = (string) Str::ulid();

        DB::table('property_business_dates')->updateOrInsert(
            [
                'property_id' => $this->property->id,
                'business_date' => '2026-06-28',
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => PropertyBusinessDateStatusEnum::Open->value,
                'is_open' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('gl_financial_periods')->updateOrInsert(
            [
                'property_id' => $this->property->id,
                'period_year' => 2026,
                'period_month' => 6,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => FinancialPeriodStatusEnum::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Receipt Guard Test Category',
        ]);

        $this->item = InventoryItem::firstOrCreate(
            ['property_id' => $this->property->id, 'sku' => 'GUARD-RCPT-001'],
            [
                'category_id' => $category->id,
                'name' => 'Guard Receipt Test Item',
                'inventory_type' => 'goods',
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
    // Proof 3 + 6a: ENROLLED matching group — ReceiptService::post() follows the
    //               synchronously owned CostControl path exactly once
    // -------------------------------------------------------------------------
    public function test_receipt_service_posts_once_through_synchronous_cost_control_for_enrolled_matching_group(): void
    {
        $this->createEnrolledGroup($this->property->id, $this->item->id, $this->location->id);

        $receipt = $this->makeDraftReceipt();

        $txBefore = DB::table('inventory_transactions')->where('reference_id', $receipt->id)->count();
        $stockBefore = DB::table('inventory_stocks')->where('item_id', $this->item->id)->count();
        $wacBefore = (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost');
        $avcoStatesBefore = DB::table('cost_avco_states')->where('property_id', $this->property->id)->count();
        $outboxBefore = DB::table('outbox_messages')->count();
        $ledgerBefore = DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count();
        $candidatesBefore = DB::table('journal_candidates')->count();
        $enrollsBefore = DB::table('cost_authority_enrollment_groups')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('status', 'enrolled')
            ->count();

        $posted = $this->receiptService->post($receipt->id);
        $transaction = DB::table('inventory_transactions')->where('reference_id', $receipt->id)->sole();

        $this->assertEquals('posted', $posted->status->value);
        $this->assertEquals($txBefore + 1, DB::table('inventory_transactions')->where('reference_id', $receipt->id)->count());
        $this->assertSame('SYNCHRONOUS', $transaction->cost_delivery_mode);
        $this->assertSame(1, (int) $transaction->cost_delivery_ownership_version);
        $this->assertEquals($stockBefore + 1, DB::table('inventory_stocks')->where('item_id', $this->item->id)->count());
        $this->assertEquals($wacBefore, (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost'));
        $this->assertEquals($avcoStatesBefore, DB::table('cost_avco_states')->where('property_id', $this->property->id)->count());
        $this->assertEquals($outboxBefore + 1, DB::table('outbox_messages')->count());
        $this->assertEquals($ledgerBefore + 1, DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count());
        $this->assertEquals(1, DB::table('cost_ledger_entries')->where('source_inventory_transaction_id', $transaction->id)->count());
        $this->assertGreaterThanOrEqual($candidatesBefore, DB::table('journal_candidates')->count());
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
    //               follows the synchronously owned CostControl path exactly once
    // -------------------------------------------------------------------------
    public function test_integration_service_posts_once_through_synchronous_cost_control_for_enrolled_matching_group(): void
    {
        $this->createEnrolledGroup($this->property->id, $this->item->id, $this->location->id);

        $doc = $this->makeReceivingDocument();
        $this->makeReceivingLine($doc, $this->item->id, $this->location->id);

        $txBefore = DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count();
        $stockBefore = DB::table('inventory_stocks')->where('item_id', $this->item->id)->count();
        $wacBefore = (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost');
        $avcoStatesBefore = DB::table('cost_avco_states')->where('property_id', $this->property->id)->count();
        $outboxBefore = DB::table('outbox_messages')->count();
        $ledgerBefore = DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count();
        $candidatesBefore = DB::table('journal_candidates')->count();

        $this->integrationService->syncToInventory($doc, $this->actorId);
        $transaction = DB::table('inventory_transactions')->where('source_document_id', $doc->id)->sole();

        $this->assertEquals($txBefore + 1, DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count());
        $this->assertSame('SYNCHRONOUS', $transaction->cost_delivery_mode);
        $this->assertSame(1, (int) $transaction->cost_delivery_ownership_version);
        $this->assertEquals($stockBefore + 1, DB::table('inventory_stocks')->where('item_id', $this->item->id)->count());
        $this->assertEquals($wacBefore, (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost'));
        $this->assertEquals($avcoStatesBefore, DB::table('cost_avco_states')->where('property_id', $this->property->id)->count());
        $this->assertEquals($outboxBefore + 1, DB::table('outbox_messages')->count());
        $this->assertEquals($ledgerBefore + 1, DB::table('cost_ledger_entries')->where('property_id', $this->property->id)->count());
        $this->assertEquals(1, DB::table('cost_ledger_entries')->where('source_inventory_transaction_id', $transaction->id)->count());
        $this->assertGreaterThanOrEqual($candidatesBefore, DB::table('journal_candidates')->count());
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
        $differentItemId = (string) Str::ulid();
        $this->createEnrolledGroup($differentPropertyId, $differentItemId);

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
    // Proof 7: ReceivingLine with missing inventory_item_id fails before any
    //          enrollment check, mutation, or partial posting
    // -------------------------------------------------------------------------
    public function test_integration_service_rejects_line_missing_item_id_before_enrollment_check(): void
    {
        $doc = $this->makeReceivingDocument();

        // Line has destination_location_id but NO inventory_item_id.
        ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => null,
            'destination_location_id' => $this->location->id,
            'description' => 'Missing item guard test line',
            'received_quantity' => '5.00',
            'unit_cost' => '12.00',
            'line_total' => '60.00',
        ]);

        $txBefore = DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count();
        $stockBefore = DB::table('inventory_stocks')->where('property_id', $this->property->id)->count();
        $wacBefore = (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost');
        $enrollsBefore = DB::table('cost_authority_enrollment_groups')
            ->where('property_id', $this->property->id)
            ->count();

        $exceptionThrown = false;
        try {
            $this->integrationService->syncToInventory($doc, $this->actorId);
            $this->fail('Expected exception for missing inventory_item_id; none thrown.');
        } catch (\Throwable $e) {
            $exceptionThrown = true;
            // Must not reach the enrollment check — guard message must not appear.
            $this->assertStringNotContainsString(
                'CostControl authority is enrolled',
                $e->getMessage(),
                'Enrollment guard must not be reached before line validation fails.'
            );
            $this->assertStringContainsString(
                'missing item or destination location',
                $e->getMessage()
            );
        }

        $this->assertTrue($exceptionThrown);

        // No InventoryTransaction
        $this->assertEquals($txBefore, DB::table('inventory_transactions')->where('source_document_id', $doc->id)->count());

        // No stock mutation
        $this->assertEquals($stockBefore, DB::table('inventory_stocks')->where('property_id', $this->property->id)->count());

        // No WAC mutation
        $this->assertEquals(
            $wacBefore,
            (float) DB::table('inventory_items')->where('id', $this->item->id)->value('weighted_average_cost')
        );

        // No CostAuthority enrollment state mutation
        $this->assertEquals(
            $enrollsBefore,
            DB::table('cost_authority_enrollment_groups')
                ->where('property_id', $this->property->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function makeDraftReceipt(): InventoryReceipt
    {
        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'receipt_number' => 'RCP-GUARD-'.Str::ulid(),
            'supplier_name' => 'Guard Test Supplier',
            'status' => 'draft',
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '5.000',
            'unit_cost' => '12.00',
            'line_total' => '60.00',
        ]);

        return $receipt;
    }

    private function makeReceivingDocument(): ReceivingDocument
    {
        return ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-GUARD-'.Str::ulid(),
            'status' => 'submitted',
        ]);
    }

    private function makeReceivingLine(
        ReceivingDocument $doc,
        string $itemId,
        string $locationId
    ): ReceivingLine {
        return ReceivingLine::create([
            'receiving_document_id' => $doc->id,
            'inventory_item_id' => $itemId,
            'destination_location_id' => $locationId,
            'description' => 'Guard test receiving line',
            'received_quantity' => '5.00',
            'unit_cost' => '12.00',
            'line_total' => '60.00',
        ]);
    }

    private function makeSnapshot(string $propertyId, string $locationId, string $itemId): array
    {
        return [
            'location_id' => $locationId,
            'valuation_scope' => "property:{$propertyId}:location:{$locationId}:item:{$itemId}",
            'opening_quantity' => '100.0000',
            'opening_carrying_value' => '1000.0000',
            'currency_code' => 'USD',
            'business_date' => '2026-07-01',
            'financial_period_id' => (string) Str::ulid(),
            'evidence_timestamp' => now(),
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

    private function createEnrolledGroup(
        string $propertyId,
        string $itemId,
        ?string $locationId = null
    ): CostAuthorityEnrollmentGroup {
        $locationId ??= (string) Str::ulid();

        $this->ensureAuthorityScopeExists($propertyId, $itemId);

        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $propertyId, 'item_id' => $itemId],
            [$this->makeSnapshot($propertyId, $locationId, $itemId)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepo->approve($group->id, $this->actorId, now())
        );

        DB::transaction(function () use ($group): void {
            DB::table('cost_authority_enrollment_groups')
                ->where('id', $group->id)
                ->update([
                    'status' => 'enrolled',
                    'enrolled_at' => now(),
                    'updated_at' => now(),
                ]);

            app(CostDeliveryModeOwnershipBootstrapService::class)
                ->bootstrap($group->id, $this->actorId);
        });

        $snapshot = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $group->id)
            ->where('location_id', $locationId)
            ->first();
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'location_id' => $locationId,
            'item_id' => $itemId,
            'valuation_scope' => $snapshot->valuation_scope,
            'on_hand_quantity' => $snapshot->opening_quantity,
            'carrying_value' => $snapshot->opening_carrying_value,
            'weighted_average_unit_cost' => '10.0000',
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => null,
            'last_valuation_business_date' => null,
            'enrollment_group_id' => $group->id,
            'enrollment_scope_snapshot_id' => $snapshot->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CostAuthorityEnrollmentGroup::find($group->id);
    }

    private function ensureAuthorityScopeExists(string $propertyId, string $itemId): void
    {
        if (! DB::table('properties')->where('id', $propertyId)->exists()) {
            DB::table('properties')->insert([
                'id' => $propertyId,
                'company_id' => DB::table('companies')->value('id'),
                'name' => 'Receipt Guard Property '.substr($propertyId, -6),
                'slug' => 'receipt-guard-'.strtolower($propertyId),
                'code' => 'RG'.substr($propertyId, -6),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('inventory_items')->where('id', $itemId)->exists()) {
            return;
        }

        $categoryId = (string) Str::ulid();
        DB::table('inventory_categories')->insert([
            'id' => $categoryId,
            'property_id' => $propertyId,
            'name' => 'Receipt Guard Category '.substr($itemId, -6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_items')->insert([
            'id' => $itemId,
            'property_id' => $propertyId,
            'sku' => 'RG-'.substr($itemId, -12),
            'name' => 'Receipt Guard Item '.substr($itemId, -6),
            'category_id' => $categoryId,
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
