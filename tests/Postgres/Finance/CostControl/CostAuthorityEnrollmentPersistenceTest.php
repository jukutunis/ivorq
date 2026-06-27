<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Carbon\Carbon;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;

class CostAuthorityEnrollmentPersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // Disable DatabaseTransactions wrapping so we can test "requires active
    // transaction" guards by calling repository methods outside any transaction.
    protected function connectionsToTransact(): array
    {
        return [];
    }

    private CostAuthorityEnrollmentRepository $repo;
    private string $propertyId;
    private string $itemId;
    private string $locationA;
    private string $locationB;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo       = new CostAuthorityEnrollmentRepository();
        $this->propertyId = (string) Str::ulid();
        $this->itemId     = (string) Str::ulid();
        $this->locationA  = (string) Str::ulid();
        $this->locationB  = (string) Str::ulid();
        $this->actorId    = (string) Str::ulid();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a canonical valuation_scope string for the given location.
     * This matches the exact format written by InventoryPostingControlCoordinator.
     */
    private function canonicalScope(string $locationId): string
    {
        return "property:{$this->propertyId}:location:{$locationId}:item:{$this->itemId}";
    }

    private function makeSnapshot(string $locationId, ?string $scope = null, array $overrides = []): array
    {
        return array_merge([
            'location_id'            => $locationId,
            'valuation_scope'        => $scope ?? $this->canonicalScope($locationId),
            'opening_quantity'       => '100.0000',
            'opening_carrying_value' => '1500.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'financial_period_id'    => (string) Str::ulid(),
            'source_reference'       => 'MIGRATION-PACKAGE-001',
            'evidence_timestamp'     => now()->toDateTimeString(),
        ], $overrides);
    }

    private function makeDraftGroup(?array $snapshots = null): CostAuthorityEnrollmentGroup
    {
        $snapshots ??= [
            $this->makeSnapshot($this->locationA),
            $this->makeSnapshot($this->locationB),
        ];

        return $this->repo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            $snapshots
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: draft parent with two distinct canonical-scope snapshots persists
    // -------------------------------------------------------------------------

    public function test_draft_group_with_two_snapshots_persists(): void
    {
        $group = $this->makeDraftGroup();

        $this->assertNotEmpty($group->id);
        $this->assertEquals($this->propertyId, $group->property_id);
        $this->assertEquals($this->itemId, $group->item_id);
        $this->assertEquals(CostAuthorityEnrollmentStatusEnum::Draft, $group->status);

        $snapshots = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)->get();
        $this->assertCount(2, $snapshots);

        $locationIds = $snapshots->pluck('location_id')->sort()->values()->toArray();
        $expected    = collect([$this->locationA, $this->locationB])->sort()->values()->toArray();
        $this->assertEquals($expected, $locationIds);

        // Verify each snapshot carries its canonical valuation_scope.
        foreach ($snapshots as $snapshot) {
            $this->assertEquals(
                $this->canonicalScope($snapshot->location_id),
                $snapshot->valuation_scope
            );
        }
    }

    // -------------------------------------------------------------------------
    // Test 2: duplicate location_id inside one group is rejected
    //         (regardless of valuation_scope — new uk_caess_group_location)
    // -------------------------------------------------------------------------

    public function test_duplicate_location_within_group_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Both snapshots share location_id — must violate uk_caess_group_location.
        $this->repo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            [
                $this->makeSnapshot($this->locationA),
                $this->makeSnapshot($this->locationA), // same location — duplicate
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Test 3: empty snapshot set is rejected by repository
    // -------------------------------------------------------------------------

    public function test_empty_snapshot_set_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one scope snapshot/');

        $this->repo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            []
        );
    }

    // -------------------------------------------------------------------------
    // Test 4: draft → approved succeeds when approval metadata exists
    // -------------------------------------------------------------------------

    public function test_draft_to_approved_succeeds_with_metadata(): void
    {
        $group    = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        $updated = DB::transaction(
            fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt)
        );

        $this->assertEquals(CostAuthorityEnrollmentStatusEnum::Approved, $updated->status);
        $this->assertEquals($this->actorId, $updated->approved_by);
        $this->assertNotNull($updated->approved_at);
    }

    // -------------------------------------------------------------------------
    // Test 5: draft → approved fails when approval metadata is missing
    // -------------------------------------------------------------------------

    public function test_draft_to_approved_pg_trigger_rejects_missing_approved_by(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/requires approved_by and approved_at/');

        $group = $this->makeDraftGroup();

        // Bypass repository to drive trigger directly
        DB::transaction(function () use ($group) {
            DB::table('cost_authority_enrollment_groups')
                ->where('id', $group->id)
                ->update([
                    'status'      => 'approved',
                    'approved_by' => null,
                    'approved_at' => now(),
                    'updated_at'  => now(),
                ]);
        });
    }

    // -------------------------------------------------------------------------
    // Test 6: approved parent identity/evidence mutation rejected by PostgreSQL
    // -------------------------------------------------------------------------

    public function test_pg_trigger_rejects_mutating_approved_property_id(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/property_id is immutable/');

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['property_id' => (string) Str::ulid()]);
    }

    public function test_pg_trigger_rejects_mutating_approved_item_id(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/item_id is immutable/');

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['item_id' => (string) Str::ulid()]);
    }

    public function test_pg_trigger_rejects_mutating_approved_evidence(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/approved_by is immutable after approval/');

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['approved_by' => (string) Str::ulid()]);
    }

    // -------------------------------------------------------------------------
    // Test 7: approved snapshot UPDATE, INSERT, and DELETE rejected
    // -------------------------------------------------------------------------

    public function test_pg_trigger_rejects_snapshot_update_when_parent_approved(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $snapshot = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)->first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/snapshot changes are not allowed when parent status=approved/');

        DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('id', $snapshot->id)
            ->update(['opening_quantity' => '999.0000']);
    }

    public function test_pg_trigger_rejects_snapshot_insert_when_parent_approved(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $newLocationId = (string) Str::ulid();

        $this->expectException(\Illuminate\Database\QueryException::class);
        // Parent-draft guard fires before canonical-scope guard — existing message.
        $this->expectExceptionMessageMatches('/snapshot changes are not allowed when parent status=approved/');

        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id'                     => (string) Str::ulid(),
            'enrollment_group_id'    => $group->id,
            'location_id'            => $newLocationId,
            'valuation_scope'        => $this->canonicalScope($newLocationId),
            'opening_quantity'       => '1.0000',
            'opening_carrying_value' => '10.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'evidence_timestamp'     => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function test_pg_trigger_rejects_snapshot_delete_when_parent_approved(): void
    {
        $group = $this->makeDraftGroup();
        $approvedAt = Carbon::now();

        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, $approvedAt));

        $snapshot = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)->first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/snapshot changes are not allowed when parent status=approved/');

        DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('id', $snapshot->id)
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Test 8: approved → superseded succeeds only with complete metadata
    // -------------------------------------------------------------------------

    public function test_approved_to_superseded_succeeds_with_complete_metadata(): void
    {
        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));

        $supersededAt = Carbon::now();
        $superseded = DB::transaction(
            fn () => $this->repo->supersedeApproved($group->id, $this->actorId, $supersededAt, 'RECONCILIATION_CORRECTION')
        );

        $this->assertEquals(CostAuthorityEnrollmentStatusEnum::Superseded, $superseded->status);
        $this->assertEquals('RECONCILIATION_CORRECTION', $superseded->superseded_reason);
    }

    public function test_approved_to_superseded_pg_trigger_rejects_missing_reason(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/requires superseded_by, superseded_at, and non-empty superseded_reason/');

        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));

        // Bypass repository to drive trigger directly
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update([
                'status'          => 'superseded',
                'superseded_by'   => $this->actorId,
                'superseded_at'   => now(),
                'superseded_reason' => '',   // empty — trigger must reject
                'updated_at'      => now(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Test 9: approved → enrolled succeeds only when enrolled_at is supplied
    //         (direct model write — repository intentionally has no enroll())
    // -------------------------------------------------------------------------

    public function test_approved_to_enrolled_succeeds_with_enrolled_at(): void
    {
        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update([
                'status'      => 'enrolled',
                'enrolled_at' => now(),
                'updated_at'  => now(),
            ]);

        $fresh = CostAuthorityEnrollmentGroup::find($group->id);
        $this->assertEquals(CostAuthorityEnrollmentStatusEnum::Enrolled, $fresh->status);
        $this->assertNotNull($fresh->enrolled_at);
    }

    public function test_approved_to_enrolled_pg_trigger_rejects_missing_enrolled_at(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/requires enrolled_at/');

        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update([
                'status'      => 'enrolled',
                'enrolled_at' => null,    // missing — trigger must reject
                'updated_at'  => now(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Test 10: second enrolled parent for same property_id + item_id rejected
    // -------------------------------------------------------------------------

    public function test_partial_unique_index_rejects_second_enrolled_group(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Enroll first group
        $group1 = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group1->id, $this->actorId, Carbon::now()));
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group1->id)
            ->update(['status' => 'enrolled', 'enrolled_at' => now(), 'updated_at' => now()]);

        // Attempt to enroll second group for same property + item
        $group2 = $this->repo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            [$this->makeSnapshot($this->locationA)]
        );
        DB::transaction(fn () => $this->repo->approve($group2->id, $this->actorId, Carbon::now()));

        // This must violate the partial unique index
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group2->id)
            ->update(['status' => 'enrolled', 'enrolled_at' => now(), 'updated_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Test 11: enrolled parent cannot be changed or deleted
    // -------------------------------------------------------------------------

    public function test_enrolled_parent_cannot_be_updated(): void
    {
        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['status' => 'enrolled', 'enrolled_at' => now(), 'updated_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/status=enrolled records are immutable/');

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['updated_at' => now()]);
    }

    public function test_enrolled_parent_cannot_be_deleted(): void
    {
        $group = $this->makeDraftGroup();
        DB::transaction(fn () => $this->repo->approve($group->id, $this->actorId, Carbon::now()));
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update(['status' => 'enrolled', 'enrolled_at' => now(), 'updated_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/status=enrolled records cannot be deleted/');

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Test 12: non-canonical valuation_scope is rejected by PostgreSQL trigger
    //          (canonical-scope invariant — new in corrective slice)
    // -------------------------------------------------------------------------

    public function test_pg_trigger_rejects_non_canonical_valuation_scope(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/valuation_scope is not canonical/');

        // Insert a parent group directly to bypass the repository scope guard.
        $groupId = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id'          => $groupId,
            'property_id' => $this->propertyId,
            'item_id'     => $this->itemId,
            'status'      => 'draft',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Insert a snapshot with an arbitrary (non-canonical) scope string.
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id'                     => (string) Str::ulid(),
            'enrollment_group_id'    => $groupId,
            'location_id'            => $this->locationA,
            'valuation_scope'        => 'arbitrary:non:canonical:string',
            'opening_quantity'       => '100.0000',
            'opening_carrying_value' => '1500.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'evidence_timestamp'     => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 13: second snapshot for the same enrollment_group_id + location_id
    //          is rejected, even with a different valuation_scope
    //          (new uk_caess_group_location uniqueness — corrective slice)
    // -------------------------------------------------------------------------

    public function test_unique_index_rejects_second_snapshot_for_same_location(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // First snapshot for locationA.
        $group = $this->makeDraftGroup([
            $this->makeSnapshot($this->locationA),
        ]);

        // Second snapshot for the same locationA — canonical scope is identical
        // (confirmed by canonicalScope helper), so uk_caess_group_location fires.
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id'                     => (string) Str::ulid(),
            'enrollment_group_id'    => $group->id,
            'location_id'            => $this->locationA,
            'valuation_scope'        => $this->canonicalScope($this->locationA),
            'opening_quantity'       => '50.0000',
            'opening_carrying_value' => '750.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'evidence_timestamp'     => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }
}
