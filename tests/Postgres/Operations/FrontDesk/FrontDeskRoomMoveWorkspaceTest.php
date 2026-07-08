<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskRoomMoveWorkspaceTest extends PostgresTestCase
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

    public function test_workspace_shows_room_move_only_for_authorized_in_house_stay(): void
    {
        [$stay] = $this->checkedInStay('1501');
        $this->room($this->property, '1502');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.stay_id', $stay->id)
                ->where('inHouseWorkspace.views.inHouseStays.0.actions.can_move_room', true)
                ->where('inHouseWorkspace.views.inHouseStays.0.target_room_candidates.0.eligible', true)
                ->where('inHouseWorkspace.views.inHouseStays.0.assignment_history.0.assignment_kind', 'INITIAL_ASSIGNMENT')
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout')
                ->missing('inHouseWorkspace.views.inHouseStays.0.folio')
                ->missing('inHouseWorkspace.views.inHouseStays.0.raw_status_edit')
            );

        $workspace = app(\Modules\Operations\FrontDesk\Services\FrontDeskInHouseWorkspaceService::class)->workspace($this->frontDeskViewOnlyActor);
        $this->assertFalse($workspace['views']['inHouseStays'][0]['actions']['can_move_room']);
    }

    public function test_workspace_displays_target_housekeeping_and_engineering_blockers(): void
    {
        $this->checkedInStay('1503');
        $this->room($this->property, '1504', ['readiness_state' => 'waiting_inspection']);
        $engRoom = $this->room($this->property, '1505');
        $this->activeEngineeringBlock($engRoom);

        $workspace = app(\Modules\Operations\FrontDesk\Services\FrontDeskInHouseWorkspaceService::class)->workspace($this->frontDeskActor);
        $candidates = collect($workspace['views']['inHouseStays'][0]['target_room_candidates']);

        $this->assertTrue($candidates->contains(fn (array $candidate) => $candidate['housekeeping']['readiness_state'] === 'waiting_inspection' && $candidate['eligible'] === false));
        $this->assertTrue($candidates->contains(fn (array $candidate) => $candidate['engineering']['state'] === 'ENGINEERING_BLOCKED' && $candidate['eligible'] === false));
    }

    protected function checkedInStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid());
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $stay = app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);

        return [$stay->fresh(), $room];
    }
}
