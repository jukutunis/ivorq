<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverWorkspaceQuery;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverWorkspaceTest extends PostgresTestCase
{
    use CreatesHousekeepingCheckoutTurnoverIntakeData;
    use RefreshDatabase;

    private const URL = '/operations/housekeeping/checkout-turnovers';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCheckoutTurnoverFixture();
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($this->property->id);
        foreach ([
            'housekeeping.room.view',
            'housekeeping.task.view',
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->actor->givePermissionTo([
            'housekeeping.room.view',
            'housekeeping.task.view',
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_canonical_route_renders_the_read_only_inertia_workspace_with_canonical_authority(): void
    {
        $source = $this->source('101');

        $this->workspace()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operations/Housekeeping/CheckoutTurnovers/Index')
                ->where('turnovers.data.0.handoff_id', $source['handoff']->id)
                ->where('turnovers.data.0.operational_state', 'ready')
                ->has('kpis')
                ->has('filters')
                ->has('options'));

        $this->assertTrue($this->actor->can('viewAny', \Modules\Operations\Housekeeping\Models\Room::class));
    }

    public function test_all_operational_states_use_deterministic_priority_and_match_kpis(): void
    {
        $completed = $this->source('201');
        app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $pending = $this->source('202');
        $intake = app(HousekeepingCheckoutTurnoverIntakeService::class);
        $intake->setPostCommitTestingHookForTesting(
            fn () => throw new \RuntimeException('SIMULATED_POST_COMMIT_WINDOW')
        );
        try {
            $intake->consumeNextAvailable($this->property->id, 60);
            $this->fail('Expected the post-commit testing hook to interrupt delivery confirmation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SIMULATED_POST_COMMIT_WINDOW', $exception->getMessage());
        } finally {
            $intake->setPostCommitTestingHookForTesting(null);
        }

        $review = $this->source('203');
        $reviewClaim = $this->delivery()->claimAvailable($this->property->id, $review['handoff']->id, 60);
        $this->delivery()->markDelivered($this->property->id, $review['handoff']->id, $reviewClaim['claim_token']);

        $active = $this->source('204');
        $this->delivery()->claimAvailable($this->property->id, $active['handoff']->id, 60);

        $readyPending = $this->source('205');

        $retryWait = $this->source('206');
        $retryWaitClaim = $this->delivery()->claimAvailable($this->property->id, $retryWait['handoff']->id, 60);
        $this->delivery()->markFailed(
            $this->property->id,
            $retryWait['handoff']->id,
            $retryWaitClaim['claim_token'],
            'HK_DELIVERY_TIMEOUT',
            $this->databaseNow()->addMinutes(5),
        );

        $retryDue = $this->source('207');
        $retryDueClaim = $this->delivery()->claimAvailable($this->property->id, $retryDue['handoff']->id, 60);
        $this->delivery()->markFailed(
            $this->property->id,
            $retryDue['handoff']->id,
            $retryDueClaim['claim_token'],
            'HK_RETRY_DUE',
            $this->databaseNow()->addSeconds(3),
        );

        $expired = $this->source('208');
        $this->delivery()->claimAvailable($this->property->id, $expired['handoff']->id, 1);

        $scheduledAt = $this->databaseNow()->addDays(2);
        Carbon::setTestNow($scheduledAt);
        $scheduled = $this->source('209');
        Carbon::setTestNow();

        DB::selectOne('SELECT pg_sleep(3.2)');

        $response = $this->workspace(['per_page' => 100])->assertOk();
        $rows = collect($response->inertiaProps('turnovers')['data'])->keyBy('handoff_id');

        $expected = [
            $completed['handoff']->id => 'completed',
            $pending['handoff']->id => 'delivery_confirmation_pending',
            $review['handoff']->id => 'review_required',
            $active['handoff']->id => 'active_claim',
            $readyPending['handoff']->id => 'ready',
            $retryWait['handoff']->id => 'retry_wait',
            $retryDue['handoff']->id => 'ready',
            $expired['handoff']->id => 'ready',
            $scheduled['handoff']->id => 'scheduled',
        ];

        foreach ($expected as $handoffId => $state) {
            $this->assertSame($state, $rows[$handoffId]['operational_state'], $handoffId);
        }

        $kpis = $response->inertiaProps('kpis');
        $this->assertSame(3, $kpis['ready_now']);
        $this->assertSame(1, $kpis['active_claims']);
        $this->assertSame(1, $kpis['retry_waiting']);
        $this->assertSame(1, $kpis['delivery_confirmation_pending']);
        $this->assertSame(1, $kpis['completed_today']);
        $this->assertSame(1, $kpis['review_required']);

        $this->assertSame('completed', $rows[$completed['handoff']->id]['operational_state']);
        $this->assertSame('review_required', $rows[$review['handoff']->id]['operational_state']);
    }

    public function test_postgresql_wall_clock_not_frozen_application_time_classifies_active_claim(): void
    {
        $source = $this->source('301');
        $this->delivery()->claimAvailable($this->property->id, $source['handoff']->id, 60);

        Carbon::setTestNow($this->databaseNow()->addDay());

        $row = $this->workspace()->assertOk()->inertiaProps('turnovers')['data'][0];
        $this->assertSame('active_claim', $row['operational_state']);
    }

    public function test_state_business_date_and_task_status_filters_are_property_scoped(): void
    {
        $completed = $this->source('401');
        app(HousekeepingCheckoutTurnoverIntakeService::class)->consumeNextAvailable($this->property->id, 60);
        $originalDate = $completed['businessDate']->business_date->toDateString();

        $completed['businessDate']->forceFill([
            'status' => PropertyBusinessDateStatusEnum::Closed,
            'is_open' => null,
            'closed_by' => $this->actor->id,
            'closed_at' => now(),
        ])->save();
        $nextDate = Carbon::parse($originalDate)->addDay()->toDateString();
        PropertyBusinessDate::create([
            'property_id' => $this->property->id,
            'business_date' => $nextDate,
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'timezone_snapshot' => 'UTC',
            'opened_by' => $this->actor->id,
            'opened_at' => now(),
        ]);
        $ready = $this->source('402');

        $completedRows = $this->workspace(['state' => 'completed'])->assertOk()->inertiaProps('turnovers')['data'];
        $this->assertSame([$completed['handoff']->id], collect($completedRows)->pluck('handoff_id')->all());

        $datedRows = $this->workspace(['business_date' => $nextDate])->assertOk()->inertiaProps('turnovers')['data'];
        $this->assertSame([$ready['handoff']->id], collect($datedRows)->pluck('handoff_id')->all());

        $taskRows = $this->workspace(['task_status' => 'pending'])->assertOk()->inertiaProps('turnovers')['data'];
        $this->assertSame([$completed['handoff']->id], collect($taskRows)->pluck('handoff_id')->all());
    }

    public function test_search_matches_all_allowlisted_operational_identifiers(): void
    {
        $source = $this->source('501');
        app(HousekeepingCheckoutTurnoverIntakeService::class)->consumeNextAvailable($this->property->id, 60);
        $row = $this->workspace(['selected' => $source['handoff']->id])->assertOk()->inertiaProps('selected_turnover');

        foreach ([
            '501',
            $source['reservation']->reservation_number,
            $source['handoff']->id,
            $row['intake_id'],
            $source['execution']->id,
            $row['cleaning_task_code'],
        ] as $search) {
            $rows = $this->workspace(['search' => $search])->assertOk()->inertiaProps('turnovers')['data'];
            $this->assertSame([$source['handoff']->id], collect($rows)->pluck('handoff_id')->all(), (string) $search);
        }
    }

    public function test_pagination_is_bounded_stable_and_sorting_is_allowlisted(): void
    {
        $first = $this->source('601');
        $second = $this->source('602');
        $third = $this->source('603');

        $pageOne = $this->workspace([
            'per_page' => 1,
            'sort' => 'room_number',
            'direction' => 'asc',
            'page' => 1,
        ])->assertOk()->inertiaProps('turnovers');
        $pageTwo = $this->workspace([
            'per_page' => 1,
            'sort' => 'room_number',
            'direction' => 'asc',
            'page' => 2,
        ])->assertOk()->inertiaProps('turnovers');

        $this->assertSame(3, $pageOne['total']);
        $this->assertSame($first['handoff']->id, $pageOne['data'][0]['handoff_id']);
        $this->assertSame($second['handoff']->id, $pageTwo['data'][0]['handoff_id']);
        $this->assertNotSame($pageOne['data'][0]['handoff_id'], $pageTwo['data'][0]['handoff_id']);
        $this->assertNotSame($third['handoff']->id, $pageTwo['data'][0]['handoff_id']);

        $this->workspace(['per_page' => 101], true)->assertUnprocessable();
        $this->workspace(['sort' => 'claim_token_hash'], true)->assertUnprocessable();
        $this->workspace(['direction' => 'sideways'], true)->assertUnprocessable();
    }

    public function test_selected_detail_contains_safe_committed_outcome_and_authorized_navigation(): void
    {
        $source = $this->source('701');
        app(HousekeepingCheckoutTurnoverIntakeService::class)->consumeNextAvailable($this->property->id, 60);

        $detail = $this->workspace(['selected' => $source['handoff']->id])
            ->assertOk()
            ->inertiaProps('selected_turnover');

        $this->assertSame($source['handoff']->id, $detail['handoff_id']);
        $this->assertSame('completed', $detail['operational_state']);
        $this->assertTrue($detail['committed']);
        $this->assertTrue($detail['terminal_stay_evidence']);
        $this->assertNotNull($detail['intake_id']);
        $this->assertNotNull($detail['cleaning_task_id']);
        $this->assertNotNull($detail['readiness_transition_id']);
        $this->assertSame('CHECKOUT_TURNOVER_INTAKE', $detail['readiness_transition_type']);
        $this->assertSame('waiting_cleaning', $detail['room_readiness_after']);
        $this->assertSame('dirty', $detail['cleanliness_after']);
        $this->assertSame('/operations/rooms/' . $detail['room_id'], $detail['links']['room']);
        $this->assertSame('/operations/cleaning-tasks/' . $detail['cleaning_task_id'], $detail['links']['cleaning_task']);
        $this->assertSame('/operations/room-readiness/' . $detail['room_id'], $detail['links']['room_readiness']);
    }

    public function test_unknown_selected_identifier_is_non_disclosing(): void
    {
        $this->workspace(['selected' => (string) Str::ulid()])->assertNotFound();
    }

    public function test_query_count_does_not_grow_per_row(): void
    {
        $this->source('801');
        $query = app(HousekeepingCheckoutTurnoverWorkspaceQuery::class);
        $navigation = ['room' => true, 'cleaning_task' => true, 'room_readiness' => true];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->forProperty($this->property->id, ['per_page' => 100], $navigation);
        $singleCount = count(DB::getQueryLog());

        $this->source('802');
        $this->source('803');
        DB::flushQueryLog();
        $query->forProperty($this->property->id, ['per_page' => 100], $navigation);
        $multipleCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleCount, $multipleCount);
        $this->assertLessThanOrEqual(4, $multipleCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $roomNumber): array
    {
        return $this->p11CheckoutSource(
            $this->property,
            $this->p11Room($this->property, ['room_number' => $roomNumber]),
        );
    }

    private function delivery(): FrontDeskCheckoutHousekeepingHandoffDeliveryService
    {
        return app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
    }

    private function databaseNow(): Carbon
    {
        $row = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock");
        return Carbon::parse($row->wall_clock, 'UTC');
    }

    private function workspace(array $query = [], bool $json = false): \Illuminate\Testing\TestResponse
    {
        $request = $this->withSession([
            'active_company_id' => $this->company->id,
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
        ])->actingAs($this->actor, 'web');

        if ($json) {
            $request->withHeader('Accept', 'application/json');
        }

        return $request->get(self::URL . ($query === [] ? '' : '?' . http_build_query($query)));
    }
}
