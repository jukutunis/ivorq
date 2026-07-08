<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Action log projection ──

    public function test_action_log_displays_events_for_stay(): void
    {
        [$stay] = $this->checkedInStay('5001');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Note 1', 'dpe-a-' . Str::ulid()
        );
        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_TIME_CONFIRMED', 'Confirmed 11:00', 'dpe-b-' . Str::ulid()
        );

        $log = app(FrontDeskDeparturePreparationEventProjectionService::class)->actionLog(
            $this->frontDeskActor, $stay->id
        );

        $this->assertSame(2, $log['event_count']);
        $this->assertCount(2, $log['events']);
        $this->assertSame($stay->id, $log['stay_id']);
        $this->assertTrue($log['actions']['can_create_event']);
    }

    public function test_action_log_ordered_by_occurred_at_desc(): void
    {
        [$stay] = $this->checkedInStay('5002');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'First', 'dpe-a-' . Str::ulid()
        );

        Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00'));
        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'GUEST_MESSAGE_NOTED', 'Second', 'dpe-b-' . Str::ulid()
        );

        $log = app(FrontDeskDeparturePreparationEventProjectionService::class)->actionLog(
            $this->frontDeskActor, $stay->id
        );

        $this->assertSame(2, $log['event_count']);
        $this->assertSame('GUEST_MESSAGE_NOTED', $log['events'][0]['event_type']);
        $this->assertSame('DEPARTURE_NOTE_RECORDED', $log['events'][1]['event_type']);
    }

    // ── Action log permission ──

    public function test_action_log_requires_view_permission(): void
    {
        [$stay] = $this->checkedInStay('5003');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $userWithoutPermission = \Modules\Foundation\User\Models\User::create([
            'name' => 'No Permission User',
            'email' => 'noperm-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $userWithoutPermission->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);

        app(FrontDeskDeparturePreparationEventProjectionService::class)->actionLog(
            $userWithoutPermission, $stay->id
        );
    }

    public function test_view_only_actor_sees_events_but_cannot_create(): void
    {
        [$stay] = $this->checkedInStay('5004');

        // Create a user with view but not create permission
        $viewOnlyUser = \Modules\Foundation\User\Models\User::create([
            'name' => 'FD B2 View Only',
            'email' => 'fd-b2-view-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $viewOnlyUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);
        $viewOnlyUser->givePermissionTo(FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION);

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Note', 'dpe-' . Str::ulid()
        );

        $log = app(FrontDeskDeparturePreparationEventProjectionService::class)->actionLog(
            $viewOnlyUser, $stay->id
        );

        $this->assertSame(1, $log['event_count']);
        $this->assertFalse($log['actions']['can_create_event']);
        $this->assertEmpty($log['allowed_event_types']);
    }

    // ── Departure queue includes events ──

    public function test_departure_queue_includes_event_data(): void
    {
        [$stay] = $this->checkedInStay('5005');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Queue test note', 'dpe-' . Str::ulid()
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        // Reservation departure_date is 2026-07-09, test time is 2026-07-08 → DUE_OUT_TOMORROW
        $tomorrow = $queue['views']['dueOutTomorrow'];
        $this->assertNotEmpty($tomorrow);
        $stayData = collect($tomorrow)->firstWhere('stay_id', $stay->id);
        $this->assertNotNull($stayData);
        $this->assertNotEmpty($stayData['departure_preparation_events']);
        $this->assertTrue($stayData['can_create_departure_preparation_event']);
        $this->assertNotEmpty($stayData['allowed_event_types']);
    }

    // ── HTTP action log endpoint ──

    public function test_http_action_log_endpoint(): void
    {
        [$stay] = $this->checkedInStay('5006');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'HTTP test', 'dpe-' . Str::ulid()
        );

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->getJson("/frontdesk/stays/{$stay->id}/departure-preparation-events");

        $response->assertOk();
        $response->assertJsonPath('event_count', 1);
        $response->assertJsonPath('stay_id', $stay->id);
    }

    public function test_http_action_log_requires_view_permission(): void
    {
        [$stay] = $this->checkedInStay('5007');

        $userWithoutPermission = \Modules\Foundation\User\Models\User::create([
            'name' => 'No Perm User',
            'email' => 'noperm2-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $userWithoutPermission->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($userWithoutPermission, 'web')
            ->getJson("/frontdesk/stays/{$stay->id}/departure-preparation-events")
            ->assertForbidden();
    }

    // ── Workspace financial marker ──

    public function test_workspace_financial_marker_is_b2(): void
    {
        [$stay] = $this->checkedInStay('5008');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $this->assertStringContainsString('Package B2', $queue['financial_marker']);

        $log = app(FrontDeskDeparturePreparationEventProjectionService::class)->actionLog(
            $this->frontDeskActor, $stay->id
        );
        $this->assertStringContainsString('Package B2', $log['financial_marker']);
    }

    // ── Workspace hides create action without permission ──

    public function test_workspace_hides_create_action_without_permission(): void
    {
        [$stay] = $this->checkedInStay('5009');

        // Create a user with view but not create permission
        $viewOnlyUser = \Modules\Foundation\User\Models\User::create([
            'name' => 'FD B2 Queue View Only',
            'email' => 'fd-b2-qview-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $viewOnlyUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);
        $viewOnlyUser->givePermissionTo(FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION);

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($viewOnlyUser);

        // Reservation departure_date is 2026-07-09, test time is 2026-07-08 → DUE_OUT_TOMORROW
        $tomorrow = $queue['views']['dueOutTomorrow'];
        $stayData = collect($tomorrow)->firstWhere('stay_id', $stay->id);
        $this->assertNotNull($stayData);
        $this->assertFalse($stayData['can_create_departure_preparation_event']);
        $this->assertEmpty($stayData['allowed_event_types']);
    }

    // ── Workspace renders no checkout/payment/folio/settlement actions ──

    public function test_workspace_has_no_financial_actions(): void
    {
        [$stay] = $this->checkedInStay('5010');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $this->assertArrayNotHasKey('checkout_action', $queue);
        $this->assertArrayNotHasKey('settlement_action', $queue);
        $this->assertArrayNotHasKey('payment_action', $queue);
        $this->assertArrayNotHasKey('folio_action', $queue);
    }
}
