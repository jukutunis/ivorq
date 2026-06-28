<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use RuntimeException;
use Tests\PostgresTestCase;

class CostAuthorityEnrollmentBaselineSeedServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private CostAuthorityEnrollmentBaselineSeedService $seedService;
    private CostAuthorityEnrollmentRepository          $enrollmentRepository;

    private string $propertyId;
    private string $itemId;
    private string $locationId1;
    private string $locationId2;
    private string $actorId;
    private string $periodId;

    private CostAuthorityEnrollmentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedService          = app(CostAuthorityEnrollmentBaselineSeedService::class);
        $this->enrollmentRepository = app(CostAuthorityEnrollmentRepository::class);

        $this->propertyId  = (string) Str::ulid();
        $this->itemId      = (string) Str::ulid();
        $this->locationId1 = (string) Str::ulid();
        $this->locationId2 = (string) Str::ulid();
        $this->actorId     = (string) Str::ulid();
        $this->periodId    = (string) Str::ulid();

        DB::table('properties')->insert([
            'id'         => $this->propertyId,
            'name'       => 'Test Property',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->group = $this->createApprovedGroup([
            $this->locationId1 => ['quantity' => '100.0000', 'carrying_value' => '500.0000'],
            $this->locationId2 => ['quantity' => '50.0000',  'carrying_value' => '150.0000'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Proof 1: APPROVED group seeds one CostAvcoState per location
    // -------------------------------------------------------------------------
    public function test_approved_group_seeds_one_state_per_location(): void
    {
        $ids = $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        $this->assertCount(2, $ids);
        $this->assertCount(
            2,
            CostAvcoState::where('property_id', $this->propertyId)
                ->where('item_id', $this->itemId)
                ->get()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 2: Each state preserves exact snapshot attributes and provenance
    // -------------------------------------------------------------------------
    public function test_each_state_preserves_exact_snapshot_attributes_and_provenance(): void
    {
        $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        $snapshots = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $this->group->id)
            ->get()
            ->keyBy('location_id');

        $state1 = CostAvcoState::where('property_id', $this->propertyId)
            ->where('location_id', $this->locationId1)
            ->first();

        // Core scope identity
        $this->assertEquals($this->propertyId, $state1->property_id);
        $this->assertEquals($this->locationId1, $state1->location_id);
        $this->assertEquals($this->itemId, $state1->item_id);

        // Verbatim snapshot values
        $this->assertEquals('100.0000', $state1->on_hand_quantity);
        $this->assertEquals('500.0000', $state1->carrying_value);

        // WAUC derived via decimal-safe BC math: 500.0000 / 100.0000 = 5.0000
        $this->assertEquals('5.0000', $state1->weighted_average_unit_cost);

        // Baseline sequence N = null (proven: bootstrapAndLock initialises to null)
        $this->assertNull($state1->last_valuation_sequence);
        $this->assertNull($state1->last_valuation_business_date);

        // No unresolved provisional quantity at baseline
        $this->assertEquals('0.0000', $state1->unresolved_provisional_quantity);

        // Durable provenance to both group and snapshot
        $this->assertEquals($this->group->id, $state1->enrollment_group_id);
        $this->assertEquals($snapshots->get($this->locationId1)->id, $state1->enrollment_scope_snapshot_id);

        // Second location
        $state2 = CostAvcoState::where('property_id', $this->propertyId)
            ->where('location_id', $this->locationId2)
            ->first();

        $this->assertEquals('50.0000', $state2->on_hand_quantity);
        $this->assertEquals('150.0000', $state2->carrying_value);
        // 150.0000 / 50.0000 = 3.0000
        $this->assertEquals('3.0000', $state2->weighted_average_unit_cost);
        $this->assertNull($state2->last_valuation_sequence);
        $this->assertEquals($this->group->id, $state2->enrollment_group_id);
        $this->assertEquals($snapshots->get($this->locationId2)->id, $state2->enrollment_scope_snapshot_id);
    }

    // -------------------------------------------------------------------------
    // Proof 3: Group remains APPROVED after successful seed
    // -------------------------------------------------------------------------
    public function test_group_remains_approved_after_seed(): void
    {
        $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        $fresh = CostAuthorityEnrollmentGroup::find($this->group->id);
        $this->assertEquals(CostAuthorityEnrollmentStatusEnum::Approved, $fresh->status);
        $this->assertNull($fresh->enrolled_at);
    }

    // -------------------------------------------------------------------------
    // Proof 4: Second identical seed request is idempotent
    // -------------------------------------------------------------------------
    public function test_second_seed_request_is_idempotent(): void
    {
        $ids1 = $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);
        $ids2 = $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        $this->assertEquals(sort($ids1), sort($ids2));

        // Exactly two states still exist — no duplicates
        $this->assertCount(
            2,
            CostAvcoState::where('property_id', $this->propertyId)
                ->where('item_id', $this->itemId)
                ->get()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 5: Conflicting existing CostAvcoState fails closed
    // -------------------------------------------------------------------------
    public function test_conflicting_existing_state_fails_closed_without_partial_seed(): void
    {
        // Pre-create a state for location1 without enrollment provenance (like consumer bootstrap).
        CostAvcoState::create([
            'property_id'                     => $this->propertyId,
            'location_id'                     => $this->locationId1,
            'item_id'                         => $this->itemId,
            'valuation_scope'                 => "property:{$this->propertyId}:location:{$this->locationId1}:item:{$this->itemId}",
            'on_hand_quantity'                => '0.0000',
            'carrying_value'                  => '0.0000',
            'unresolved_provisional_quantity' => '0.0000',
        ]);

        try {
            $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);
            $this->fail('Seed must fail when a conflicting CostAvcoState already exists.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot overwrite existing state', $e->getMessage());
        }

        // Only the pre-existing state persists — no partial seed
        $states = CostAvcoState::where('property_id', $this->propertyId)
            ->where('item_id', $this->itemId)
            ->get();

        $this->assertCount(1, $states);
        $this->assertNull($states->first()->enrollment_scope_snapshot_id);

        // Group remains APPROVED
        $this->assertEquals(
            CostAuthorityEnrollmentStatusEnum::Approved,
            CostAuthorityEnrollmentGroup::find($this->group->id)->status
        );
    }

    // -------------------------------------------------------------------------
    // Proof 6: Non-APPROVED group fails without state creation
    // -------------------------------------------------------------------------
    public function test_non_approved_group_fails_without_state_creation(): void
    {
        $locationId = (string) Str::ulid();
        $draftGroup = $this->enrollmentRepository->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            [[
                'location_id'            => $locationId,
                'valuation_scope'        => "property:{$this->propertyId}:location:{$locationId}:item:{$this->itemId}",
                'opening_quantity'       => '10.0000',
                'opening_carrying_value' => '50.0000',
                'currency_code'          => 'USD',
                'business_date'          => '2026-07-01',
                'financial_period_id'    => $this->periodId,
                'evidence_timestamp'     => now(),
            ]]
        );

        try {
            $this->seedService->seedApprovedGroup($draftGroup->id, $this->actorId);
            $this->fail('Seeding a Draft group must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APPROVED', $e->getMessage());
        }

        // No state created for this draft group
        $this->assertNull(
            DB::table('cost_avco_states')
                ->where('enrollment_group_id', $draftGroup->id)
                ->first()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 7: Unique constraint prevents two CostAvcoState rows per snapshot
    // -------------------------------------------------------------------------
    public function test_snapshot_unique_constraint_prevents_two_states_per_snapshot(): void
    {
        // Seed to create the first state linked to each snapshot.
        $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        $snapshot1 = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $this->group->id)
            ->where('location_id', $this->locationId1)
            ->first();

        // Attempt to insert a second CostAvcoState row referencing the same snapshot.
        // uk_cas_enrollment_scope_snapshot_id must reject this.
        $this->expectException(QueryException::class);

        DB::table('cost_avco_states')->insert([
            'id'                           => (string) Str::ulid(),
            'property_id'                  => $this->propertyId,
            'location_id'                  => (string) Str::ulid(),  // different location
            'item_id'                      => $this->itemId,
            'valuation_scope'              => 'property:x:location:y:item:z',
            'on_hand_quantity'             => '0.0000',
            'carrying_value'               => '0.0000',
            'unresolved_provisional_quantity' => '0.0000',
            'enrollment_scope_snapshot_id' => $snapshot1->id,        // duplicate → violates unique index
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Proof 8: Seed creates no side-effect accounting data
    // -------------------------------------------------------------------------
    public function test_seed_creates_no_side_effect_accounting_data(): void
    {
        $this->seedService->seedApprovedGroup($this->group->id, $this->actorId);

        // No GL journal entries
        $this->assertEquals(
            0,
            DB::table('gl_journal_entries')
                ->where('property_id', $this->propertyId)
                ->count()
        );

        // No GL ledger balances
        $this->assertEquals(
            0,
            DB::table('gl_ledger_balances')
                ->where('property_id', $this->propertyId)
                ->count()
        );

        // No Cost Ledger entries
        $this->assertEquals(
            0,
            DB::table('cost_ledger_entries')
                ->where('property_id', $this->propertyId)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function createApprovedGroup(array $locationData): CostAuthorityEnrollmentGroup
    {
        $snapshots = [];
        foreach ($locationData as $locationId => $values) {
            $snapshots[] = [
                'location_id'            => $locationId,
                'valuation_scope'        => "property:{$this->propertyId}:location:{$locationId}:item:{$this->itemId}",
                'opening_quantity'       => $values['quantity'],
                'opening_carrying_value' => $values['carrying_value'],
                'currency_code'          => 'USD',
                'business_date'          => '2026-07-01',
                'financial_period_id'    => $this->periodId,
                'evidence_timestamp'     => now(),
            ];
        }

        $group = $this->enrollmentRepository->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            $snapshots
        );

        DB::transaction(function () use ($group) {
            $this->enrollmentRepository->approve($group->id, $this->actorId, now());
        });

        return CostAuthorityEnrollmentGroup::find($group->id);
    }
}
