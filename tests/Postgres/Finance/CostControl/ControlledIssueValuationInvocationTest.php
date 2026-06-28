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
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Foundation\Property\Models\Property;

class ControlledIssueValuationInvocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private IssueService $issueService;
    private CostAuthorityEnrollmentRepository $enrollmentRepo;

    private Property $property;
    private InventoryItem $itemEnrolled;
    private InventoryItem $itemUnenrolled;
    private InventoryLocation $location;
    private string $actorId;
    private string $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueService = app(IssueService::class);
        $this->enrollmentRepo = app(CostAuthorityEnrollmentRepository::class);

        $this->property = Property::first();

        // Mock authentication for IssueService::post
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'operator@ivorq.test'],
            [
                'name' => 'Operator',
                'password' => bcrypt('password'),
            ]
        );
        $this->actorId = $user->id;
        $this->actingAs($user);

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Invocation Issue Test Category',
        ]);

        $this->itemEnrolled = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'INVOC-ISS-ENR-001',
            'name'                  => 'Invocation Enrolled Issue Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        $this->itemUnenrolled = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'INVOC-ISS-UNENR-001',
            'name'                  => 'Invocation Unenrolled Issue Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'Invocation Issue Test Location'],
            ['type' => 'internal']
        );

        $this->businessDate = '2026-06-28';

        // Seed property business date open
        DB::table('property_business_dates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => 'open',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed financial period open
        DB::table('financial_periods')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => 'open',
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

        DB::table('inventory_stocks')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->itemUnenrolled->id,
            'physical_quantity' => '100.0000',
            'status' => 'in_stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEnrolledGroup(string $itemId): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'status' => 'enrolled',
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

        return $id;
    }

    /**
     * 1. All-enrolled IssueService issue applies controlled path atomically
     */
    public function test_all_enrolled_issue_service_atomic_apply(): void
    {
        $groupId = $this->createEnrolledGroup($this->itemEnrolled->id);

        // Set intentionally different values:
        // - CostAvcoState WAUC = source-proven Amount A (12.5000)
        // - InventoryItem weighted_average_cost = Amount B (10.0000)
        DB::table('cost_avco_states')
            ->where('item_id', $this->itemEnrolled->id)
            ->update([
                'on_hand_quantity'           => '10.0000',
                'carrying_value'             => '125.0000',
                'weighted_average_unit_cost' => '12.5000',
            ]);

        $this->assertEquals(
            '10.0000',
            (string) DB::table('inventory_items')->where('id', $this->itemEnrolled->id)->value('weighted_average_cost')
        );

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-TEST-' . Str::ulid(),
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

        // Assert canonical InventoryTransaction unit_cost equals A (12.5000), not B (10.0000)
        $tx = DB::table('inventory_transactions')->where('item_id', $this->itemEnrolled->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('12.5000', (string) $tx->unit_cost);
        $this->assertNotEquals('10.0000', (string) $tx->unit_cost);

        // Canonical InventoryTransaction total_cost equals exact negative quantity * exact locked CostAvcoState WAUC (-3.000 * 12.5000 = -37.5000)
        $this->assertEquals('-37.5000', (string) $tx->total_cost);

        // State updated (10 - 3 = 7 qty, 125 - 37.5 = 87.5 value, WAUC = 12.5)
        $state = CostAvcoState::where('item_id', $this->itemEnrolled->id)->first();
        $this->assertEquals('7.0000', $state->on_hand_quantity);
        $this->assertEquals('87.5000', $state->carrying_value);
        $this->assertEquals('12.5000', $state->weighted_average_unit_cost);

        // Divergent caller/document/legacy-item cost is ignored (InventoryItem is still 10.0000)
        $this->assertEquals(
            '10.0000',
            (string) DB::table('inventory_items')->where('id', $this->itemEnrolled->id)->value('weighted_average_cost')
        );
    }

    /**
     * 2. All-unenrolled issue document preserves legacy behavior
     */
    public function test_all_unenrolled_preserve_legacy(): void
    {
        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-TEST-2-' . Str::ulid(),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        $posted = $this->issueService->post($issue->id, $this->actorId);

        $this->assertEquals('posted', $posted->status->value);
        $this->assertDatabaseCount('cost_ledger_entries', 0); // No Cost Ledger appends!
    }

    /**
     * 3. Mixed enrollment document fails before any writes
     */
    public function test_mixed_enrollment_fails_closed(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-TEST-MIXED-' . Str::ulid(),
            'status'       => 'draft',
        ]);

        // Line 1: Enrolled
        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        // Line 2: Unenrolled
        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemUnenrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '2.000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed enrollment status detected');

        try {
            $this->issueService->post($issue->id, $this->actorId);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
            $this->assertEquals('draft', DB::table('inventory_issues')->where('id', $issue->id)->value('status'));
        }
    }

    /**
     * 4. Failure after canonical transaction evidence creation rolls back everything
     */
    public function test_failure_rolls_back_everything(): void
    {
        $this->createEnrolledGroup($this->itemEnrolled->id);

        $issue = InventoryIssue::create([
            'property_id'  => $this->property->id,
            'issue_number' => 'ISS-TEST-ROLLBACK-' . Str::ulid(),
            'status'       => 'draft',
        ]);

        InventoryIssueLine::create([
            'issue_id'    => $issue->id,
            'item_id'     => $this->itemEnrolled->id,
            'location_id' => $this->location->id,
            'quantity'    => '3.000',
        ]);

        // Force sequence error
        DB::table('cost_avco_states')
            ->where('item_id', $this->itemEnrolled->id)
            ->update(['last_valuation_sequence' => 10, 'last_valuation_business_date' => $this->businessDate]);

        try {
            $this->issueService->post($issue->id, $this->actorId);
            $this->fail('Expected exception from sequence mismatch.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Sequence gap detected', $e->getMessage());
        }

        // Verify rollback: no transaction posted, status remains draft, state untouched
        $this->assertEquals('draft', DB::table('inventory_issues')->where('id', $issue->id)->value('status'));
        $this->assertDatabaseCount('cost_ledger_entries', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $state = CostAvcoState::where('item_id', $this->itemEnrolled->id)->first();
        $this->assertEquals(10, $state->last_valuation_sequence);
        $this->assertEquals('10.0000', $state->on_hand_quantity);
    }
}
