<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskWorkspaceRoomAssignmentTest extends PostgresTestCase
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

    public function test_workspace_projects_assignment_action_only_when_authorized_and_eligible(): void
    {
        $room = $this->room($this->property, '1101');
        $reservation = $this->reservation($this->property, $this->guest($this->property), 'RES-WORK-ACTION', 'confirmed', $room);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('arrivalWorkspace.views.arrivingToday.0.reservation_id', $reservation)
                ->where('arrivalWorkspace.views.arrivingToday.0.actions.can_assign_room', true)
                ->where('arrivalWorkspace.views.arrivingToday.0.actions.can_prepare_check_in', false)
                ->where('arrivalWorkspace.financeMarker', 'Financial settlement: Not evaluated in Front Desk Package A.')
                ->missing('arrivalWorkspace.views.arrivingToday.0.folio')
                ->missing('arrivalWorkspace.views.arrivingToday.0.payment')
            );

        $workspace = app(ArrivalEligibilityProjectionService::class)->workspace($this->frontDeskViewOnlyActor);
        $this->assertFalse($workspace['views']['arrivingToday'][0]['actions']['can_assign_room']);
    }

    public function test_workspace_displays_housekeeping_and_engineering_blockers(): void
    {
        $hkRoom = $this->room($this->property, '1102', ['readiness_state' => 'waiting_engineering']);
        $this->reservation($this->property, $this->guest($this->property), 'RES-WORK-A-HK', 'confirmed', $hkRoom);

        $engRoom = $this->room($this->property, '1103');
        $this->reservation($this->property, $this->guest($this->property), 'RES-WORK-Z-ENG', 'confirmed', $engRoom);
        $this->activeEngineeringBlock($engRoom, 'Pump failure');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('arrivalWorkspace.views.arrivingToday.0.housekeeping.readiness_state', 'waiting_engineering')
                ->where('arrivalWorkspace.views.arrivingToday.0.actions.can_assign_room', false)
                ->where('arrivalWorkspace.views.arrivingToday.1.engineering.state', 'ENGINEERING_BLOCKED')
                ->where('arrivalWorkspace.views.arrivingToday.1.actions.can_assign_room', false)
            );
    }

    public function test_workspace_projects_check_in_action_after_assignment_without_raw_status_edit_fields(): void
    {
        [$reservation, , $room] = $this->assignReadyReservation('1104');
        $result = app(FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor,
            $reservation,
            $room,
            null,
            'workspace-assign-' . Str::ulid()
        );

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('arrivalWorkspace.views.arrivingToday.0.front_desk.stay_id', $result['stay']->id)
                ->where('arrivalWorkspace.views.arrivingToday.0.front_desk.status', 'ROOM_ASSIGNED')
                ->where('arrivalWorkspace.views.arrivingToday.0.actions.can_assign_room', false)
                ->where('arrivalWorkspace.views.arrivingToday.0.actions.can_prepare_check_in', true)
                ->missing('arrivalWorkspace.views.arrivingToday.0.raw_status_edit')
                ->missing('arrivalWorkspace.views.arrivingToday.0.checkout')
                ->missing('arrivalWorkspace.views.arrivingToday.0.final_checkout')
            );
    }
}
