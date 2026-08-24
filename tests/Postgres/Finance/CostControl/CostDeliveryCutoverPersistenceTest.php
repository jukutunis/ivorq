<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostDeliveryModeOwnershipBootstrapService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Tests\PostgresTestCase;

class CostDeliveryCutoverPersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A Cutover',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01A-CUT-'.Str::random(10),
            'name' => 'CC-P01A Cutover Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open]
        );
    }

    public function test_virgin_null_avco_with_absent_allocator_persists_only_zero_one_boundary(): void
    {
        $fixture = $this->makeFixture(1);
        $cutoverId = $this->activate($fixture, 'ALLOCATOR_ABSENT');

        $scope = DB::table('cost_delivery_cutover_scopes')->where('cutover_id', $cutoverId)->first();
        $this->assertSame('NO_PRIOR_APPLIED_VALUATION_SEQUENCE', $scope->sequence_state_classification);
        $this->assertSame(0, (int) $scope->last_synchronously_owned_sequence);
        $this->assertSame(1, (int) $scope->first_deferred_owned_sequence);
        $this->assertNull($scope->inventory_valuation_sequence_id);

        $state = CostAvcoState::where('enrollment_group_id', $fixture['group_id'])->firstOrFail();
        $this->assertNull($state->last_valuation_sequence);
        $this->assertDatabaseMissing('inventory_transactions', [
            'property_id' => $this->property->id,
            'valuation_sequence' => 0,
        ]);
    }

    public function test_virgin_null_avco_with_allocator_zero_persists_zero_one_boundary(): void
    {
        $fixture = $this->makeFixture(1);
        $snapshot = $fixture['snapshots'][0];
        $allocatorId = $this->insertAllocator($snapshot['location_id'], 0);
        $cutoverId = $this->activate($fixture, 'ALLOCATOR_ROW');

        $this->assertDatabaseHas('cost_delivery_cutover_scopes', [
            'cutover_id' => $cutoverId,
            'inventory_valuation_sequence_id' => $allocatorId,
            'inventory_allocator_last_sequence' => 0,
            'cost_avco_last_valuation_sequence' => null,
            'last_synchronously_owned_sequence' => 0,
            'first_deferred_owned_sequence' => 1,
        ]);
        $this->assertNull(CostAvcoState::where('enrollment_group_id', $fixture['group_id'])
            ->value('last_valuation_sequence'));
    }

    public function test_positive_allocator_with_null_avco_is_sequence_divergence(): void
    {
        $fixture = $this->makeFixture(1);
        $this->insertAllocator($fixture['snapshots'][0]['location_id'], 5);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE/');
        DB::transaction(function () use ($fixture) {
            $cutoverId = $this->insertCutover($fixture);
            $this->insertScope($fixture, $fixture['snapshots'][0], $cutoverId, [
                'inventory_sequence_source' => 'ALLOCATOR_ROW',
                'inventory_allocator_last_sequence' => 5,
                'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => 5,
                'first_deferred_owned_sequence' => 6,
            ]);
        });
    }

    public function test_allocator_and_avco_sequence_mismatch_is_rejected(): void
    {
        $fixture = $this->makeFixture(1);
        $snapshot = $fixture['snapshots'][0];
        $this->insertAllocator($snapshot['location_id'], 5);
        CostAvcoState::where('enrollment_scope_snapshot_id', $snapshot['id'])->update([
            'last_valuation_sequence' => 4,
            'last_valuation_business_date' => '2026-08-20',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE/');
        DB::transaction(function () use ($fixture, $snapshot) {
            $cutoverId = $this->insertCutover($fixture);
            $this->insertScope($fixture, $snapshot, $cutoverId, [
                'inventory_sequence_source' => 'ALLOCATOR_ROW',
                'inventory_allocator_last_sequence' => 5,
                'cost_avco_last_valuation_sequence' => 4,
                'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => 5,
                'first_deferred_owned_sequence' => 6,
            ]);
        });
    }

    public function test_non_virgin_equal_sequences_persist_exact_n_and_n_plus_one(): void
    {
        $fixture = $this->makeFixture(1);
        $snapshot = $fixture['snapshots'][0];
        $this->insertAllocator($snapshot['location_id'], 5);
        CostAvcoState::where('enrollment_scope_snapshot_id', $snapshot['id'])->update([
            'last_valuation_sequence' => 5,
            'last_valuation_business_date' => '2026-08-20',
        ]);
        $this->insertHistoricalSource($snapshot, 5);

        $cutoverId = $this->activate($fixture, 'ALLOCATOR_ROW', 5);
        $this->assertDatabaseHas('cost_delivery_cutover_scopes', [
            'cutover_id' => $cutoverId,
            'inventory_allocator_last_sequence' => 5,
            'cost_avco_last_valuation_sequence' => 5,
            'last_synchronously_owned_sequence' => 5,
            'first_deferred_owned_sequence' => 6,
        ]);
    }

    public function test_non_virgin_evidence_cannot_replace_an_absent_allocator_with_matching_history(): void
    {
        $fixture = $this->makeFixture(1);
        $snapshot = $fixture['snapshots'][0];
        CostAvcoState::where('enrollment_scope_snapshot_id', $snapshot['id'])->update([
            'last_valuation_sequence' => 5,
            'last_valuation_business_date' => '2026-08-20',
        ]);
        $this->insertHistoricalSource($snapshot, 5);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE/');
        DB::transaction(function () use ($fixture, $snapshot) {
            $cutoverId = $this->insertCutover($fixture);
            $this->insertScope($fixture, $snapshot, $cutoverId, [
                'inventory_sequence_source' => 'ALLOCATOR_ABSENT',
                'inventory_valuation_sequence_id' => null,
                'inventory_allocator_last_sequence' => 5,
                'cost_avco_last_valuation_sequence' => 5,
                'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => 5,
                'first_deferred_owned_sequence' => 6,
            ]);
        });
    }

    public function test_allocator_identity_from_another_scope_is_rejected(): void
    {
        $fixture = $this->makeFixture(2);
        $foreignAllocatorId = $this->insertAllocator($fixture['snapshots'][1]['location_id'], 0);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE/');
        DB::transaction(function () use ($fixture, $foreignAllocatorId) {
            $cutoverId = $this->insertCutover($fixture);
            $this->insertScope($fixture, $fixture['snapshots'][0], $cutoverId, [
                'inventory_sequence_source' => 'ALLOCATOR_ROW',
                'inventory_valuation_sequence_id' => $foreignAllocatorId,
            ]);
        });
    }

    public function test_n_plus_one_constraint_and_complete_scope_coverage_are_enforced(): void
    {
        $fixture = $this->makeFixture(2);
        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'cost_delivery_cutover_scopes'"))
            ->pluck('indexname');
        $this->assertContains('uk_cdcs_cutover_location', $indexes);
        $this->assertContains('uk_cdcs_cutover_scope', $indexes);
        $this->assertContains('uk_cdcs_snapshot', $indexes);

        try {
            DB::transaction(function () use ($fixture) {
                $cutoverId = $this->insertCutover($fixture);
                $this->insertScope($fixture, $fixture['snapshots'][0], $cutoverId, [
                    'first_deferred_owned_sequence' => 2,
                ]);
            });
            $this->fail('Invalid N/N+1 evidence must be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('chk_cdcs_n_plus_one', $exception->getMessage());
        }

        try {
            DB::transaction(function () use ($fixture) {
                $cutoverId = $this->insertCutover($fixture);
                $this->insertScope($fixture, $fixture['snapshots'][0], $cutoverId);
                $this->transitionOwnership($fixture['ownership_id'], $cutoverId);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Partial-location cutover evidence must be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('complete enrollment scope coverage is required', $exception->getMessage());
        } finally {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    public function test_activated_attempt_requires_exact_cutover_identity_context_and_provenance(): void
    {
        $fixture = $this->makeFixture(1);
        $cutoverId = $this->activate($fixture, 'ALLOCATOR_ABSENT');
        $cutover = DB::table('cost_delivery_cutovers')->where('id', $cutoverId)->first();
        $this->assertNotNull($cutover);

        DB::table('cost_delivery_cutover_attempts')->insert($this->attemptAttributes($cutover));
        $this->assertDatabaseHas('cost_delivery_cutover_attempts', [
            'cutover_id' => $cutoverId,
            'outcome' => 'ACTIVATED',
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'enrollment_group_id' => $fixture['group_id'],
            'target_financial_period_id' => $this->period->id,
        ]);

        $otherProperty = $this->makeOtherProperty();
        $otherItem = $this->makeItem($otherProperty, 'CROSS-PROPERTY');
        $otherGroupId = $this->insertEnrollmentGroup($otherProperty->id, $otherItem->id);
        $otherPeriod = FinancialPeriod::updateOrCreate(
            ['property_id' => $otherProperty->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open]
        );
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'property_id' => $otherProperty->id,
            'item_id' => $otherItem->id,
            'enrollment_group_id' => $otherGroupId,
            'target_financial_period_id' => $otherPeriod->id,
        ]), 'activated cutover context mismatch');

        $wrongItem = $this->makeItem($this->property, 'WRONG-ITEM');
        $wrongItemGroupId = $this->insertEnrollmentGroup($this->property->id, $wrongItem->id);
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'item_id' => $wrongItem->id,
            'enrollment_group_id' => $wrongItemGroupId,
        ]), 'activated cutover context mismatch');

        $wrongGroupId = $this->insertEnrollmentGroup($this->property->id, $this->item->id);
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'enrollment_group_id' => $wrongGroupId,
        ]), 'activated cutover context mismatch');

        $wrongPeriod = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 9],
            ['status' => FinancialPeriodStatusEnum::Open]
        );
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'target_financial_period_id' => $wrongPeriod->id,
        ]), 'activated cutover context mismatch');

        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'boundary_business_date' => '2026-09-01',
        ]), 'activated cutover context mismatch');
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'owner_approval_reference' => 'OWNER-UNRELATED',
        ]), 'activated cutover context mismatch');
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'requested_by' => (string) Str::ulid(),
        ]), 'activated cutover context mismatch');
        $this->assertAttemptRejected($this->attemptAttributes($cutover, [
            'requested_at' => now()->subDay(),
        ]), 'activated cutover context mismatch');
    }

    public function test_every_attempt_enforces_enrollment_and_financial_period_property_isolation(): void
    {
        $fixture = $this->makeFixture(1);
        $otherProperty = $this->makeOtherProperty();
        $otherPeriod = FinancialPeriod::updateOrCreate(
            ['property_id' => $otherProperty->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open]
        );

        $blocked = $this->blockedAttemptAttributes($fixture);
        $this->assertAttemptRejected(array_merge($blocked, [
            'property_id' => $otherProperty->id,
        ]), 'enrollment Property/Item mismatch');
        $this->assertAttemptRejected(array_merge($blocked, [
            'target_financial_period_id' => $otherPeriod->id,
        ]), 'Financial Period Property mismatch');
    }

    public function test_cutover_scope_and_attempts_are_append_only_and_deferred_is_terminal(): void
    {
        $fixture = $this->makeFixture(1);
        $cutoverId = $this->activate($fixture, 'ALLOCATOR_ABSENT');
        $scopeId = DB::table('cost_delivery_cutover_scopes')->where('cutover_id', $cutoverId)->value('id');

        foreach ([
            ['cost_delivery_cutovers', $cutoverId],
            ['cost_delivery_cutover_scopes', $scopeId],
        ] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)
                    ->update(['created_at' => now()->addSecond()]));
                $this->fail("{$table} UPDATE must be blocked.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable append-only evidence', $exception->getMessage());
            }
        }

        $attemptId = (string) Str::ulid();
        DB::table('cost_delivery_cutover_attempts')->insert([
            'id' => $attemptId,
            'request_id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'enrollment_group_id' => $fixture['group_id'],
            'target_financial_period_id' => $this->period->id,
            'boundary_business_date' => '2026-08-31',
            'outcome' => 'CUTOVER_BLOCKED',
            'reason_code' => 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE',
            'cutover_id' => null,
            'owner_approval_reference' => 'OWNER-CC-P01A-TEST',
            'requested_by' => $this->actor->id,
            'requested_at' => now(),
            'created_at' => now(),
        ]);

        try {
            DB::transaction(fn () => DB::table('cost_delivery_cutover_attempts')->where('id', $attemptId)
                ->update(['reason_code' => 'CHANGED']));
            $this->fail('Attempt UPDATE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable append-only evidence', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('cost_delivery_cutover_attempts')->where('id', $attemptId)->delete());
            $this->fail('Attempt DELETE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable append-only evidence', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/DEFERRED ownership is terminal/');
        DB::table('cost_delivery_mode_ownerships')->where('id', $fixture['ownership_id'])->update([
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 3,
            'activated_cutover_id' => null,
            'changed_by' => $this->actor->id,
            'changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cross_property_identity_cannot_satisfy_cutover_or_scope(): void
    {
        $fixture = $this->makeFixture(1);
        $otherProperty = Property::where('id', '<>', $this->property->id)->first();
        if ($otherProperty === null) {
            $otherProperty = Property::create([
                'company_id' => $this->property->company_id,
                'name' => 'CC-P01A Other Property',
                'slug' => 'cc-p01a-other-'.Str::random(8),
                'code' => 'CP'.Str::upper(Str::random(5)),
                'currency' => 'USD',
                'timezone' => 'UTC',
                'is_active' => true,
            ]);
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/ownership identity mismatch/');
        DB::transaction(function () use ($fixture, $otherProperty) {
            DB::table('cost_delivery_cutovers')->insert(array_merge(
                $this->cutoverAttributes($fixture, (string) Str::ulid()),
                ['property_id' => $otherProperty->id],
            ));
        });
    }

    private function makeFixture(int $locationCount): array
    {
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $snapshots = [];
        for ($index = 0; $index < $locationCount; $index++) {
            $location = InventoryLocation::create([
                'property_id' => $this->property->id,
                'name' => 'CC-P01A Cutover Location '.Str::random(8),
                'type' => 'internal',
            ]);
            $snapshots[] = [
                'location_id' => $location->id,
                'valuation_scope' => "property:{$this->property->id}:location:{$location->id}:item:{$this->item->id}",
                'opening_quantity' => '0.0000',
                'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD',
                'business_date' => '2026-08-01',
                'financial_period_id' => $this->period->id,
                'source_reference' => 'CC-P01A-CUTOVER-TEST',
                'evidence_timestamp' => now(),
            ];
        }

        $group = $repository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            $snapshots,
        );
        DB::transaction(fn () => $repository->approve($group->id, $this->actor->id, now()));
        DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'updated_at' => now(),
        ]);

        $persistedSnapshots = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $group->id)->orderBy('location_id')->get()
            ->map(fn ($snapshot) => (array) $snapshot)->all();
        foreach ($persistedSnapshots as $snapshot) {
            CostAvcoState::create([
                'property_id' => $this->property->id,
                'location_id' => $snapshot['location_id'],
                'item_id' => $this->item->id,
                'valuation_scope' => $snapshot['valuation_scope'],
                'on_hand_quantity' => '0.0000',
                'carrying_value' => '0.0000',
                'weighted_average_unit_cost' => null,
                'unresolved_provisional_quantity' => '0.0000',
                'last_valuation_sequence' => null,
                'last_valuation_business_date' => null,
                'enrollment_group_id' => $group->id,
                'enrollment_scope_snapshot_id' => $snapshot['id'],
            ]);
        }
        $ownership = DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
            ->bootstrap($group->id, $this->actor->id));
        CostDeliveryPilotProperty::create([
            'pilot_slot' => 1,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'OWNER-CC-P01A-TEST',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
        ]);

        return [
            'group_id' => $group->id,
            'ownership_id' => $ownership->id,
            'snapshots' => $persistedSnapshots,
        ];
    }

    private function activate(array $fixture, string $source, int $sequence = 0): string
    {
        $cutoverId = DB::transaction(function () use ($fixture, $source, $sequence) {
            $cutoverId = $this->insertCutover($fixture);
            foreach ($fixture['snapshots'] as $snapshot) {
                $this->insertScope($fixture, $snapshot, $cutoverId, [
                    'inventory_sequence_source' => $source,
                    'inventory_allocator_last_sequence' => $sequence,
                    'cost_avco_last_valuation_sequence' => $sequence > 0 ? $sequence : null,
                    'sequence_state_classification' => $sequence > 0
                        ? 'PRIOR_APPLIED_VALUATION_SEQUENCE'
                        : 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
                    'last_synchronously_owned_sequence' => $sequence,
                    'first_deferred_owned_sequence' => $sequence + 1,
                ]);
            }
            $this->transitionOwnership($fixture['ownership_id'], $cutoverId);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $cutoverId;
        });

        return $cutoverId;
    }

    private function insertCutover(array $fixture): string
    {
        $cutoverId = (string) Str::ulid();
        DB::table('cost_delivery_cutovers')->insert($this->cutoverAttributes($fixture, $cutoverId));

        return $cutoverId;
    }

    private function cutoverAttributes(array $fixture, string $cutoverId): array
    {
        return [
            'id' => $cutoverId,
            'ownership_id' => $fixture['ownership_id'],
            'enrollment_group_id' => $fixture['group_id'],
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'financial_period_id' => $this->period->id,
            'boundary_business_date' => '2026-08-31',
            'owner_approval_reference' => 'OWNER-CC-P01A-TEST',
            'requested_by' => $this->actor->id,
            'requested_at' => now()->subMinutes(2),
            'approved_by' => $this->actor->id,
            'approved_at' => now()->subMinute(),
            'activated_by' => $this->actor->id,
            'activated_at' => now(),
            'created_at' => now(),
        ];
    }

    private function insertScope(array $fixture, array $snapshot, string $cutoverId, array $overrides = []): void
    {
        $allocator = DB::table('inventory_valuation_sequences')
            ->where('property_id', $this->property->id)
            ->where('location_id', $snapshot['location_id'])
            ->where('item_id', $this->item->id)->first();
        DB::table('cost_delivery_cutover_scopes')->insert(array_merge([
            'id' => (string) Str::ulid(),
            'cutover_id' => $cutoverId,
            'enrollment_scope_snapshot_id' => $snapshot['id'],
            'property_id' => $this->property->id,
            'location_id' => $snapshot['location_id'],
            'item_id' => $this->item->id,
            'valuation_scope' => $snapshot['valuation_scope'],
            'inventory_sequence_source' => $allocator === null ? 'ALLOCATOR_ABSENT' : 'ALLOCATOR_ROW',
            'inventory_valuation_sequence_id' => $allocator?->id,
            'inventory_allocator_last_sequence' => (int) ($allocator?->last_sequence ?? 0),
            'cost_avco_last_valuation_sequence' => null,
            'sequence_state_classification' => 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
            'last_synchronously_owned_sequence' => 0,
            'first_deferred_owned_sequence' => 1,
            'created_at' => now(),
        ], $overrides));
    }

    private function transitionOwnership(string $ownershipId, string $cutoverId): void
    {
        DB::table('cost_delivery_mode_ownerships')->where('id', $ownershipId)->update([
            'delivery_mode' => 'DEFERRED',
            'ownership_version' => 2,
            'activated_cutover_id' => $cutoverId,
            'changed_by' => $this->actor->id,
            'changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAllocator(string $locationId, int $sequence): string
    {
        $id = (string) Str::ulid();
        DB::table('inventory_valuation_sequences')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'location_id' => $locationId,
            'item_id' => $this->item->id,
            'last_sequence' => $sequence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertHistoricalSource(array $snapshot, int $sequence): void
    {
        DB::table('inventory_transactions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $snapshot['location_id'],
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 1,
            'quantity_after' => 1,
            'unit_cost' => 1,
            'total_cost' => 1,
            'posted_at' => now(),
            'valuation_scope' => $snapshot['valuation_scope'],
            'valuation_sequence' => $sequence,
        ]);
    }

    private function attemptAttributes(object $cutover, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'property_id' => $cutover->property_id,
            'item_id' => $cutover->item_id,
            'enrollment_group_id' => $cutover->enrollment_group_id,
            'target_financial_period_id' => $cutover->financial_period_id,
            'boundary_business_date' => $cutover->boundary_business_date,
            'outcome' => 'ACTIVATED',
            'reason_code' => null,
            'cutover_id' => $cutover->id,
            'owner_approval_reference' => $cutover->owner_approval_reference,
            'requested_by' => $cutover->requested_by,
            'requested_at' => $cutover->requested_at,
            'created_at' => now(),
        ], $overrides);
    }

    private function blockedAttemptAttributes(array $fixture): array
    {
        return [
            'id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'enrollment_group_id' => $fixture['group_id'],
            'target_financial_period_id' => $this->period->id,
            'boundary_business_date' => '2026-08-31',
            'outcome' => 'CUTOVER_BLOCKED',
            'reason_code' => 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE',
            'cutover_id' => null,
            'owner_approval_reference' => 'OWNER-CC-P01A-TEST',
            'requested_by' => $this->actor->id,
            'requested_at' => now(),
            'created_at' => now(),
        ];
    }

    private function assertAttemptRejected(array $attributes, string $message): void
    {
        try {
            DB::transaction(fn () => DB::table('cost_delivery_cutover_attempts')->insert($attributes));
            $this->fail("Attempt should have been rejected with {$message}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function makeOtherProperty(): Property
    {
        return Property::create([
            'company_id' => $this->property->company_id,
            'name' => 'CC-P01A Other Property '.Str::random(8),
            'slug' => 'cc-p01a-other-'.Str::random(8),
            'code' => 'CP'.Str::upper(Str::random(5)),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
    }

    private function makeItem(Property $property, string $suffix): InventoryItem
    {
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $property->id,
            'name' => 'CC-P01A '.$suffix,
        ]);

        return InventoryItem::create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01A-'.$suffix.'-'.Str::random(8),
            'name' => 'CC-P01A '.$suffix,
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
    }

    private function insertEnrollmentGroup(string $propertyId, string $itemId): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'item_id' => $itemId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
