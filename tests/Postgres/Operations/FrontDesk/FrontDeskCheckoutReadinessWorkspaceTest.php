<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckoutReadinessWorkspaceTest extends PostgresTestCase
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

    public function test_workspace_shows_readiness_panel_only_when_authorized(): void
    {
        [$stay] = $this->checkedInStay('1801');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('inHouseWorkspace.views.inHouseStays.0.actions.can_view_checkout_readiness', true)
            ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.readiness_status', FrontDeskCheckoutReadinessProjectionService::READY)
        );
    }

    public function test_workspace_hides_readiness_for_unauthorized_users(): void
    {
        [$stay] = $this->checkedInStay('1802');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskViewOnlyActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.actions.can_view_checkout_readiness', false)
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness', null)
            );
    }

    public function test_workspace_displays_operational_ready_status(): void
    {
        [$stay] = $this->checkedInStay('1803');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.readiness_status', FrontDeskCheckoutReadinessProjectionService::READY)
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.operational_blockers', [])
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.financial_marker', 'Financial settlement: Not evaluated in Front Desk Package A.')
            );
    }

    public function test_workspace_displays_operational_blocker_list(): void
    {
        [$stay] = $this->checkedInStay('1804');
        $this->activeEngineeringBlock($stay->current_room_id);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.readiness_status', FrontDeskCheckoutReadinessProjectionService::BLOCKED)
            );
    }

    public function test_workspace_displays_housekeeping_blocker(): void
    {
        [$stay] = $this->checkedInStay('1805');
        DB::table('rooms')->where('id', $stay->current_room_id)->update(['readiness_state' => 'waiting_inspection']);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.readiness_status', FrontDeskCheckoutReadinessProjectionService::BLOCKED)
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.evidence.housekeeping.blocking', true)
            );
    }

    public function test_workspace_displays_engineering_blocked_blocker(): void
    {
        [$stay] = $this->checkedInStay('1806');
        $this->activeEngineeringBlock($stay->current_room_id);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.readiness_status', FrontDeskCheckoutReadinessProjectionService::BLOCKED)
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.evidence.engineering.blocking', true)
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.evidence.engineering.availability_status', 'ENGINEERING_BLOCKED')
            );
    }

    public function test_workspace_displays_financial_marker(): void
    {
        [$stay] = $this->checkedInStay('1807');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.financial_marker', 'Financial settlement: Not evaluated in Front Desk Package A.')
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.folio')
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.payment')
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.revenue')
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.tax')
            );
    }

    public function test_workspace_does_not_show_final_checkout_action(): void
    {
        [$stay] = $this->checkedInStay('1808');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house');

        $content = $response->getContent();
        $this->assertStringNotContainsString('Check Out', $content);
        $this->assertStringNotContainsString('check-out', $content);
        $this->assertStringNotContainsString('Settle', $content);
        $this->assertStringNotContainsString('Payment', $content);
    }

    public function test_workspace_does_not_expose_raw_status_edit_fields(): void
    {
        [$stay] = $this->checkedInStay('1809');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/in-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('inHouseWorkspace.views.inHouseStays.0.checkout_readiness.raw_status_edit')
                ->missing('inHouseWorkspace.views.inHouseStays.0.raw_status_edit')
            );
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
