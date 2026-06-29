<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Services\IssueService;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;

class ControlledIssueValuationInvocationGLTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private IssueService $issueService;
    private CostAuthorityEnrollmentRepository $enrollmentRepo;

    private Property $property;
    private InventoryItem $itemEnrolled;
    private InventoryLocation $location;
    private string $actorId;
    private string $businessDate;
    private string $assetAccountId;
    private string $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueService = app(IssueService::class);
        $this->enrollmentRepo = app(CostAuthorityEnrollmentRepository::class);
        $this->property = Property::first();

        // Mock authentication for IssueService::post
        $user = \Modules\Foundation\User\Models\User::firstOrCreate(
            ['email' => 'operator@ivorq.test'],
            [
                'name' => 'Operator',
                'password' => bcrypt('password'),
            ]
        );
        $this->actorId = $user->id;
        $this->actingAs($user);

        // Associate user with property to satisfy defaultProperty relationship
        DB::table('property_user')->insertOrIgnore([
            'property_id' => $this->property->id,
            'user_id' => $user->id,
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Explicitly override CurrentPropertyService context
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Invocation GL Test Category',
        ]);

        $this->itemEnrolled = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'GL-ISS-ENR-001',
            'name'                  => 'Invocation Enrolled GL Issue Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Invocation GL Test Location'],
            ['type' => 'internal']
        );

        $this->businessDate = '2026-06-28';

        // Seed property business date open
        DB::table('property_business_dates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => 'Open',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed financial period open
        DB::table('gl_financial_periods')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => 'Open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed standard stock record to prevent negative stock exceptions
        DB::table('inventory_stocks')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->itemEnrolled->id,
            'physical_quantity' => '100.0000',
            'status' => 'in_stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Setup accounts for mapping
        $assetAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '1010',
            'name' => 'Inventory Asset GL Test',
            'account_type' => AccountTypeEnum::Asset->value,
            'account_category' => 'CurrentAsset',
            'normal_balance' => 'Debit',
            'is_active' => true,
        ]);
        $this->assetAccountId = $assetAccount->id;

        $expenseAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '5010',
            'name' => 'Inventory Cost of Consumption GL Test',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => 'Expense',
            'normal_balance' => 'Debit',
            'is_active' => true,
        ]);
        $this->expenseAccountId = $expenseAccount->id;

        // Seed mappings
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->assetAccountId,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::COST_OF_CONSUMPTION->value,
            'account_id' => $this->expenseAccountId,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);
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

        // Now set to approved
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $id)
            ->update([
                'status' => 'approved',
                'approved_by' => $this->actorId,
                'approved_at' => now(),
            ]);

        // Now set to enrolled
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $id)
            ->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);

        return $id;
    }

    /**
     * Proof 1: Successful controlled enrolled issue creates one CostLedgerEntry and one idempotent GL JournalCandidate in draft/pending review status.
     */
    public function test_successful_controlled_enrolled_issue(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        DB::table('cost_avco_states')
            ->where('item_id', $this->itemEnrolled->id)
            ->update([
                'on_hand_quantity'           => '10.0000',
                'carrying_value'             => '125.0000',
                'weighted_average_unit_cost' => '12.5000',
            ]);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-GLTEST-' . substr(Str::ulid(), 0, 15),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $posted = $this->issueService->post($issue->id, $this->actorId);

        $this->assertEquals('posted', $posted->status->value);

        // Exactly one Cost Ledger entry appended
        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $costLedgerEntry = CostLedgerEntry::where('property_id', $this->property->id)->firstOrFail();
        $this->assertEquals('issue', $costLedgerEntry->entry_type);
        $this->assertEquals('-37.5000', (string) $costLedgerEntry->value_delta);

        // Exactly one JournalCandidate created
        $candidate = JournalCandidate::where([
            'property_id' => $this->property->id,
            'source_type' => 'CostLedgerEntry',
            'source_id' => $costLedgerEntry->id,
        ])->firstOrFail();

        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW->value, $candidate->status->value);
        $this->assertEquals('InventoryIssueCost', $candidate->posting_event);

        // Candidate lines match CostLedgerEntry value_delta absolute value
        $this->assertCount(2, $candidate->lines);
        $debitLine = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT)->first();
        $creditLine = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT)->first();

        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);

        $this->assertEquals('37.5000', (string) $debitLine->amount);
        $this->assertEquals('37.5000', (string) $creditLine->amount);

        $this->assertEquals(OperationalIdentityEnum::COST_OF_CONSUMPTION, $debitLine->operational_identity);
        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $creditLine->operational_identity);
    }

    /**
     * Proof 2: Repeated request does not create duplicate CostLedgerEntry or JournalCandidate.
     */
    public function test_idempotency(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $issueId = (string) Str::ulid();
        $issue = InventoryIssue::create([
            'id'           => $issueId,
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-IDEMPOTENT',
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $posted1 = $this->issueService->post($issue->id, $this->actorId);
        $this->assertEquals('posted', $posted1->status->value);

        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('journal_candidates', 1);

        // Simulate re-posting the same issue to trigger idempotency check
        try {
            $this->issueService->post($issue->id, $this->actorId);
        } catch (\Exception $e) {
            // Depending on service logic, duplicate posting of an already posted issue is blocked or idempotent
        }

        $this->assertDatabaseCount('cost_ledger_entries', 1);
        $this->assertDatabaseCount('journal_candidates', 1);
    }

    /**
     * Proof 3: Failure atomicity - missing mapping fails closed.
     */
    public function test_failure_atomicity(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        // Delete mapping to force mapping failure
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::COST_OF_CONSUMPTION->value)->delete();

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-FAILCLOSED-' . substr(Str::ulid(), 0, 11),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $this->expectException(\Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException::class);

        try {
            $this->issueService->post($issue->id, $this->actorId);
        } finally {
            // Verify everything rolled back
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $this->assertDatabaseCount('journal_candidates', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->assertEquals('draft', DB::table('inventory_issues')->where('id', $issue->id)->value('status'));
        }
    }

    /**
     * Proof 4: Business Date or Period Guard.
     */
    public function test_existing_date_period_guard(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        // Close the business date
        DB::table('property_business_dates')
            ->where('property_id', $this->property->id)
            ->update(['status' => 'Closed', 'is_open' => null]);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-BDGUARD-' . substr(Str::ulid(), 0, 14),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $this->expectException(\Shared\Exceptions\BusinessLogicException::class);
        $this->expectExceptionMessage('No open business date found for property.');

        try {
            $this->issueService->post($issue->id, $this->actorId);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $this->assertDatabaseCount('journal_candidates', 0);
        }
    }

    public function test_closed_financial_period_fails_closed_without_partial_persistence(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $costLedgerEntryCount = DB::table('cost_ledger_entries')->count();
        $journalCandidateCount = DB::table('journal_candidates')->count();
        $journalEntryCount = DB::table('gl_journal_entries')->count();

        $this->assertDatabaseHas('property_business_dates', [
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => 'Open',
            'is_open' => true,
        ]);

        DB::table('gl_financial_periods')
            ->where('property_id', $this->property->id)
            ->where('period_year', 2026)
            ->where('period_month', 6)
            ->update([
                'status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Closed->value,
            ]);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-FPGUARD-' . substr(Str::ulid(), 0, 14),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Financial period is closed or missing.');

        try {
            $this->issueService->post($issue->id, $this->actorId);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', $costLedgerEntryCount);
            $this->assertDatabaseCount('journal_candidates', $journalCandidateCount);
            $this->assertDatabaseCount('gl_journal_entries', $journalEntryCount);
            $this->assertEquals('draft', DB::table('inventory_issues')->where('id', $issue->id)->value('status'));
        }
    }

    /**
     * Proof 5: Non-goal protection - no JournalEntry is automatically posted.
     */
    public function test_non_goal_protection(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-NONGOAL-' . substr(Str::ulid(), 0, 14),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $posted = $this->issueService->post($issue->id, $this->actorId);
        $this->assertEquals('posted', $posted->status->value);

        // JournalCandidate exists
        $this->assertDatabaseCount('journal_candidates', 1);

        // No JournalEntry is posted
        $this->assertDatabaseCount('gl_journal_entries', 0);
    }
}
