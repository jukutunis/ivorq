<?php

namespace Tests\Postgres\Finance\CostControl;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Services\GrniPostingEngine;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;

class ControlledReceiptValuationInvocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ReceiptService $receiptService;
    private InventoryReceiptIntegrationService $integrationService;
    private CostAuthorityEnrollmentRepository $enrollmentRepo;

    private Property $property;
    private InventoryItem $itemEnrolled;
    private InventoryItem $itemUnenrolled;
    private InventoryLocation $location;
    private Vendor $vendor;
    private string $actorId;
    private string $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.pgsql.timezone' => 'UTC']);
        DB::purge('pgsql');

        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);

        $this->receiptService     = app(ReceiptService::class);
        $this->integrationService = app(InventoryReceiptIntegrationService::class);
        $this->enrollmentRepo     = app(CostAuthorityEnrollmentRepository::class);

        $this->property = Property::first();
        $this->actorId  = (string) Str::ulid();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Invocation Test Category',
        ]);

        $this->itemEnrolled = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'INVOC-ENR-001',
            'name'                  => 'Invocation Enrolled Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        $this->itemUnenrolled = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'INVOC-UNENR-001',
            'name'                  => 'Invocation Unenrolled Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Invocation Test Location'],
            ['type' => 'internal']
        );

        $vendorCategory = VendorCategory::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Invocation Test Vendor Category'],
            ['category_code' => 'GVC-INV']
        );

        $this->vendor = Vendor::firstOrCreate(
            ['property_id' => $this->property->id, 'vendor_code' => 'GTV-INV-001'],
            ['vendor_category_id' => $vendorCategory->id, 'name' => 'Invocation Test Vendor']
        );

        $this->businessDate = '2026-06-28';

        // Seed property business date open
        DB::table('property_business_dates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open->value,
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed financial period open
        DB::table('gl_financial_periods')->updateOrInsert(
            [
                'property_id' => $this->property->id,
                'period_year' => 2026,
                'period_month' => 6,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function createEnrolledGroup(string $itemId): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed snapshot
        $snapshotId = (string) Str::ulid();
        $valuationScope = "property:{$this->property->id}:location:{$this->location->id}:item:{$itemId}";
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $snapshotId,
            'enrollment_group_id' => $id,
            'location_id' => $this->location->id,
            'valuation_scope' => $valuationScope,
            'opening_quantity' => '10.0000',
            'opening_carrying_value' => '100.0000',
            'currency_code' => 'USD',
            'business_date' => $this->businessDate,
            'financial_period_id' => 'fp_1',
            'evidence_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $id)
            ->update([
                'status' => 'approved',
                'approved_by' => $this->actorId,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $id)
            ->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'updated_at' => now(),
            ]);

        // Seed CostAvcoState
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $itemId,
            'valuation_scope' => $valuationScope,
            'on_hand_quantity' => '10.0000',
            'carrying_value' => '100.0000',
            'weighted_average_unit_cost' => '10.0000',
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => null,
            'last_valuation_business_date' => null,
            'enrollment_group_id' => $id,
            'enrollment_scope_snapshot_id' => $snapshotId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedGrniMappings(): void
    {
        $assetAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '1200-' . substr(Str::ulid(), 0, 6),
            'name' => 'Inventory Asset GRNI Test',
            'account_type' => AccountTypeEnum::Asset->value,
            'account_category' => 'CurrentAsset',
            'normal_balance' => 'Debit',
            'is_active' => true,
        ]);

        $grniAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '2200-' . substr(Str::ulid(), 0, 6),
            'name' => 'GRNI Receipt Clearing Test',
            'account_type' => AccountTypeEnum::Liability->value,
            'account_category' => 'CurrentLiability',
            'normal_balance' => 'Credit',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $assetAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::GRNI_RECEIPT->value,
            'account_id' => $grniAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    /**
     * 1. All-enrolled ReceiptService receipt applies controlled path atomically
     */
    public function test_all_enrolled_receipt_service_atomic_apply(): void
    {
        $groupId = $this->createEnrolledGroup($this->itemEnrolled->id);

        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-TEST-' . Str::ulid(),
            'supplier_name'  => 'Test Supplier',
            'status'         => 'draft',
        ]);

        $line = InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        $posted = $this->receiptService->post($receipt->id, $this->actorId);

        $this->assertEquals('posted', $posted->status->value);

        // Exactly one Cost Ledger entry appended
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);

        $transaction = \Modules\Operations\Inventory\Models\InventoryTransaction::where('source_line_id', $line->id)->firstOrFail();
        $this->assertSame($line->id, $transaction->idempotency_key);
        $this->assertLessThanOrEqual(26, strlen($transaction->idempotency_key));
        $this->assertSame($receipt->id, $transaction->reference_id);
        $this->assertLessThanOrEqual(26, strlen($transaction->reference_id));

        try {
            $this->receiptService->post($receipt->id, $this->actorId);
        } catch (\Throwable) {
            // Re-posting an already posted receipt may be rejected, but must not duplicate transaction evidence.
        }

        $this->assertDatabaseCount('inventory_transactions', 1);

        // State updated
        $state = CostAvcoState::where('item_id', $this->itemEnrolled->id)->first();
        $this->assertEquals('15.0000', $state->on_hand_quantity);
        $this->assertEquals('160.0000', $state->carrying_value);
        $this->assertEquals('10.6666', $state->weighted_average_unit_cost);
    }

    /**
     * 2. All-enrolled InventoryReceiptIntegrationService receiving document applies controlled path
     */
    public function test_all_enrolled_receiving_integration_atomic_apply(): void
    {
        $groupId = $this->createEnrolledGroup($this->itemEnrolled->id);

        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id'   => $this->vendor->id,
            'grn_number'  => 'GRN-BUSINESS-LONG-TEST-2026-0000000001',
            'status'      => 'submitted',
        ]);

        $line = ReceivingLine::create([
            'receiving_document_id'   => $doc->id,
            'inventory_item_id'       => $this->itemEnrolled->id,
            'destination_location_id' => $this->location->id,
            'description'             => 'Receiving line test',
            'received_quantity'       => '5.00',
            'unit_cost'               => '12.00',
            'line_total'              => '60.00',
        ]);

        $this->integrationService->syncToInventory($doc, $this->actorId);

        // Exactly one Cost Ledger entry appended and one Inventory Transaction created
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);

        $transaction = \Modules\Operations\Inventory\Models\InventoryTransaction::where('source_line_id', $line->id)->firstOrFail();
        $this->assertSame($line->id, $transaction->idempotency_key);
        $this->assertSame($doc->id, $transaction->reference_id);

        // Repeat processing must be idempotent and not create duplicate transaction or cost ledger entry evidence
        $this->integrationService->syncToInventory($doc, $this->actorId);

        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);

        // Item WAC remains unchanged (bypassed)
        $this->assertEquals(
            '10.00',
            (string) DB::table('inventory_items')->where('id', $this->itemEnrolled->id)->value('weighted_average_cost')
        );
    }

    public function test_unlinked_inventory_receipt_does_not_create_grni_candidate_or_journal_entry(): void
    {
        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-UNLINKED-' . Str::ulid(),
            'supplier_name'  => 'Ad Hoc Supplier',
            'status'         => 'draft',
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        $journalCandidateCount = DB::table('journal_candidates')->count();
        $journalEntryCount = DB::table('gl_journal_entries')->count();

        $posted = $this->receiptService->post($receipt->id, $this->actorId);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertNull($posted->receiving_document_id);

        app(GrniPostingEngine::class)->process($posted);

        $this->assertDatabaseCount('journal_candidates', $journalCandidateCount);
        $this->assertDatabaseCount('gl_journal_entries', $journalEntryCount);
    }

    public function test_purchase_backed_linked_inventory_receipt_creates_idempotent_reviewable_grni_candidate_only(): void
    {
        $this->seedGrniMappings();

        $user = \Modules\Foundation\User\Models\User::first();
        if (!$user) {
            $user = \Modules\Foundation\User\Models\User::factory()->create([
                'id' => $this->actorId,
            ]);
        }

        $department = \Modules\Foundation\Department\Models\Department::firstOrCreate([
            'property_id' => $this->property->id,
            'code' => 'TEST-DEPT',
        ], [
            'id' => (string) Str::ulid(),
            'name' => 'Test Department',
            'is_active' => true,
        ]);

        $purchaseRequest = \Modules\Operations\Purchasing\Models\PurchaseRequest::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'request_no' => 'PR-GRNI-' . substr(Str::ulid(), 0, 8),
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7)->format('Y-m-d'),
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => 60.00,
            'status' => \Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum::Approved->value ?? 'APPROVED',
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $purchaseRequest->id,
        ]);

        $document = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_order_id' => $purchaseOrder->id,
            'grn_number' => (string) Str::ulid(),
            'status' => 'submitted',
            'received_at' => now(),
        ]);

        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'receipt_number' => 'RCP-GRNI-' . Str::ulid(),
            'supplier_name' => 'Linked Supplier',
            'receiving_document_id' => $document->id,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->actorId,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id' => $receipt->id,
            'item_id' => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity' => '5.000',
            'unit_cost' => '12.00',
            'line_total' => '60.00',
        ]);

        $journalEntryCount = DB::table('gl_journal_entries')->count();

        $engine = app(GrniPostingEngine::class);
        $engine->process($receipt->fresh(['lines', 'receivingDocument']));
        $engine->process($receipt->fresh(['lines', 'receivingDocument']));

        $candidates = JournalCandidate::where([
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => $receipt->id,
            'posting_event' => 'InventoryReceiptAccrual',
        ])->get();

        $this->assertCount(1, $candidates);

        $candidate = $candidates->first();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW->value, $candidate->status->value);
        $this->assertCount(2, $candidate->lines);

        $debitLine = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT)->first();
        $creditLine = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT)->first();

        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $debitLine->operational_identity);
        $this->assertEquals(OperationalIdentityEnum::GRNI_RECEIPT, $creditLine->operational_identity);
        $this->assertDatabaseCount('gl_journal_entries', $journalEntryCount);
    }

    /**
     * 3. All-unenrolled ReceiptService and Receiving documents preserve legacy behavior
     */
    public function test_all_unenrolled_preserve_legacy(): void
    {
        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-TEST-2-' . Str::ulid(),
            'supplier_name'  => 'Test Supplier',
            'status'         => 'draft',
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        $posted = $this->receiptService->post($receipt->id, $this->actorId);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertDatabaseCount('cost_ledger_entries', 0); // No Cost Ledger appends!

        // WAC updated on legacy item
        $newWac = DB::table('inventory_items')->where('id', $this->itemUnenrolled->id)->value('weighted_average_cost');
        $this->assertEquals('12.00', (string) $newWac); // since starting qty is 0, WAC becomes unit_cost
    }

    /**
     * 4. Mixed enrollment document fails before any writes
     */
    public function test_mixed_enrollment_fails_closed(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);
        // itemUnenrolled remains unenrolled

        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-TEST-MIXED-' . Str::ulid(),
            'supplier_name'  => 'Test Supplier',
            'status'         => 'draft',
        ]);

        // Line 1: Enrolled
        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        // Line 2: Unenrolled
        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '10.000',
            'unit_cost'   => '8.00',
            'line_total'  => '80.00',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed enrollment status detected');

        try {
            $this->receiptService->post($receipt->id, $this->actorId);
        } finally {
            // Assert no entries written to DB
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $this->assertEquals('draft', DB::table('inventory_receipts')->where('id', $receipt->id)->value('status'));
        }
    }

    /**
     * 5. Failure after transaction evidence creation rolls back everything
     */
    public function test_failure_rolls_back_everything(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $receipt = InventoryReceipt::create([
            'property_id'    => $this->property->id,
            'receipt_number' => 'RCP-TEST-ROLLBACK-' . Str::ulid(),
            'supplier_name'  => 'Test Supplier',
            'status'         => 'draft',
        ]);

        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '5.000',
            'unit_cost'   => '12.00',
            'line_total'  => '60.00',
        ]);

        // Force sequence error by setting last sequence to 10 in CostAvcoState
        // and setting the next allocated sequence to 12 (via last_sequence = 11 in DB)
        // so that the planner throws sequence gap error because expected sequence is 11 but got 12.
        DB::table('cost_avco_states')
            ->where('item_id', $this->itemEnrolled->id)
            ->update(['last_valuation_sequence' => 10, 'last_valuation_business_date' => $this->businessDate]);

        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->itemEnrolled->id,
            'last_sequence' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->receiptService->post($receipt->id, $this->actorId);
            $this->fail('Expected exception from sequence mismatch.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Sequence gap detected', $e->getMessage());
        }

        // Verify rollback: no transaction posted, status remains draft, state untouched
        $this->assertEquals('draft', DB::table('inventory_receipts')->where('id', $receipt->id)->value('status'));
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $state = CostAvcoState::where('item_id', $this->itemEnrolled->id)->first();
        $this->assertEquals(10, $state->last_valuation_sequence);
        $this->assertEquals('10.0000', $state->on_hand_quantity);
    }

    public function test_authorized_actor_approves_pending_review_candidate(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 1',
            'email' => 'reviewer1-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => 'finance.journal-candidate.review',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo('finance.journal-candidate.review');

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);
        $updated = $reviewService->approve($candidate->id, $user->id);

        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $updated->status);
        $this->assertEquals($user->id, $updated->approved_by);
        $this->assertNotNull($updated->approved_at);
        $this->assertNull($updated->rejected_by);

        $this->assertDatabaseCount('gl_journal_entries', 0);
    }

    public function test_authorized_actor_rejects_pending_review_candidate_with_reason(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 2',
            'email' => 'reviewer2-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => 'finance.journal-candidate.review',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo('finance.journal-candidate.review');

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);
        $updated = $reviewService->reject($candidate->id, 'Invalid account mapping', $user->id);

        $this->assertEquals(JournalCandidateStatusEnum::REJECTED, $updated->status);
        $this->assertEquals($user->id, $updated->rejected_by);
        $this->assertNotNull($updated->rejected_at);
        $this->assertEquals('Invalid account mapping', $updated->rejection_reason);
        $this->assertNull($updated->approved_by);

        $this->assertDatabaseCount('gl_journal_entries', 0);
    }

    public function test_rejection_without_reason_fails_and_leaves_candidate_unchanged(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 3',
            'email' => 'reviewer3-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => 'finance.journal-candidate.review',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo('finance.journal-candidate.review');

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);

        try {
            $reviewService->reject($candidate->id, ' ', $user->id);
            $this->fail("Expected rejection without a reason to fail.");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("rejection reason is mandatory", $e->getMessage());
        }

        $candidate = $candidate->fresh();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertNull($candidate->rejected_by);
    }

    public function test_final_candidate_cannot_be_decided_again_with_conflicting_decision(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user1 = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 4a',
            'email' => 'reviewer4a-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        $user2 = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 4b',
            'email' => 'reviewer4b-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => 'finance.journal-candidate.review',
            'guard_name' => 'web',
        ]);
        $user1->givePermissionTo('finance.journal-candidate.review');
        $user2->givePermissionTo('finance.journal-candidate.review');

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);
        $reviewService->approve($candidate->id, $user1->id);

        try {
            $reviewService->reject($candidate->id, 'Should be rejected now', $user2->id);
            $this->fail("Expected conflicting review decision to fail.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Conflicting review payload", $e->getMessage());
        }

        try {
            $reviewService->approve($candidate->id, $user2->id);
            $this->fail("Expected conflicting review actor to fail.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Conflicting review payload", $e->getMessage());
        }

        $candidate = $candidate->fresh();
        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $candidate->status);
        $this->assertEquals($user1->id, $candidate->approved_by);
    }

    public function test_identical_repeated_decision_is_idempotent(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Reviewer User 5',
            'email' => 'reviewer5-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => 'finance.journal-candidate.review',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo('finance.journal-candidate.review');

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);
        $first = $reviewService->approve($candidate->id, $user->id);
        $approvedAt = $first->approved_at;

        $second = $reviewService->approve($candidate->id, $user->id);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals($approvedAt, $second->approved_at);
        $this->assertDatabaseCount('gl_journal_entries', 0);
    }

    public function test_unauthorized_actor_fails_closed(): void
    {
        $candidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
            'candidate_date' => now()->format('Y-m-d'),
            'description' => 'Test Candidate',
        ]);

        $user = \Modules\Foundation\User\Models\User::create([
            'id' => (string) Str::ulid(),
            'name' => 'Unauthorized User',
            'email' => 'unauth-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $reviewService = app(\Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService::class);

        try {
            $reviewService->approve($candidate->id, $user->id);
            $this->fail("Expected unauthorized actor to fail.");
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertStringContainsString("Unauthorized", $e->getMessage());
        }

        try {
            $reviewService->reject($candidate->id, 'Rejected reason', $user->id);
            $this->fail("Expected unauthorized actor to fail.");
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertStringContainsString("Unauthorized", $e->getMessage());
        }

        $candidate = $candidate->fresh();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertNull($candidate->approved_by);
        $this->assertNull($candidate->rejected_by);
    }
}
