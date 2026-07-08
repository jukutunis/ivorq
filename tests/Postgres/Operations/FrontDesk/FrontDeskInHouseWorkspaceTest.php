<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskInHouseWorkspaceService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskInHouseWorkspaceTest extends PostgresTestCase
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

    public function test_in_house_access_requires_authentication_permission_and_active_property(): void
    {
        $this->get('/frontdesk/in-house')->assertRedirect();

        try {
            app(FrontDeskInHouseWorkspaceService::class)->workspace($this->financeActor);
            $this->fail('In-house workspace must require exact Front Desk view permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        app(CurrentPropertyService::class)->setPropertyId($this->otherTenantProperty->id);
        session([
            'active_property_id' => $this->otherTenantProperty->id,
            'active_company_id' => $this->property->company_id,
            'current_property_id' => $this->otherTenantProperty->id,
        ]);

        $this->expectException(HttpException::class);
        app(FrontDeskInHouseWorkspaceService::class)->workspace($this->frontDeskActor);
    }

    public function test_in_house_workspace_projects_current_room_assignment_history_and_no_finance_controls(): void
    {
        [$stay] = $this->checkedInStay('1301');
        $this->assignReadyReservation('1302');
        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house?status=CHECKED_OUT&folio=true&payment=true')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeTab', 'in_house')
                ->where('inHouseWorkspace.views.inHouseStays.0.stay_id', $stay->id)
                ->where('inHouseWorkspace.views.inHouseStays.0.status', 'IN_HOUSE')
                ->where('inHouseWorkspace.views.inHouseStays.0.current_room.number', '1301')
                ->where('inHouseWorkspace.views.inHouseStays.0.assignment_history.0.assignment_kind', 'INITIAL_ASSIGNMENT')
                ->where('inHouseWorkspace.financeMarker', 'Financial settlement: Not evaluated in Front Desk Package A.')
                ->missing('inHouseWorkspace.views.inHouseStays.0.folio')
                ->missing('inHouseWorkspace.views.inHouseStays.0.payment')
                ->missing('inHouseWorkspace.views.inHouseStays.0.raw_status_edit')
            );

        $workspace = app(FrontDeskInHouseWorkspaceService::class)->workspace($this->frontDeskActor);
        $this->assertSame(1, $workspace['snapshots']['inHouse']);
        $this->assertSame($before, $this->domainTableCounts());
    }

    public function test_cross_property_and_cross_tenant_stays_are_not_visible(): void
    {
        [$visible] = $this->checkedInStay('1303');

        $otherRoom = $this->room($this->otherProperty, '2303');
        $otherGuest = $this->guest($this->otherProperty);
        $otherReservation = $this->reservation($this->otherProperty, $otherGuest, 'RES-OTHER-STAY', 'confirmed', $otherRoom);
        DB::table('front_desk_stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $otherReservation,
            'guest_id' => $otherGuest,
            'status' => 'IN_HOUSE',
            'current_room_id' => $otherRoom,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workspace = app(FrontDeskInHouseWorkspaceService::class)->workspace($this->frontDeskActor);
        $this->assertSame([$visible->id], collect($workspace['views']['inHouseStays'])->pluck('stay_id')->all());
    }

    protected function checkedInStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid());
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $stay = app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);

        return [$stay->fresh(), $room, $reservation];
    }
}
