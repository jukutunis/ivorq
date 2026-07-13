<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationWorkspaceTest extends PostgresTestCase
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

    // ── Workspace renders due-out surface ──

    public function test_workspace_renders_departure_surface(): void
    {
        [$stay] = $this->checkedInStay('1801');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('activeTab', 'departures')
            ->where('departureWorkspace.snapshots.dueOutToday', 1)
            ->where('departureWorkspace.views.dueOutToday.0.stay_id', $stay->id)
            ->where('departureWorkspace.views.dueOutToday.0.due_out_classification', FrontDeskDepartureQueueProjectionService::DUE_OUT_TODAY)
            ->where('departureWorkspace.views.dueOutToday.0.financial_marker', 'Financial settlement readiness is not exposed in this queue row.')
            ->where('departureWorkspace.financial_marker', 'Financial settlement readiness is sourced read-only from PMS Guest Ledger GLF-D when authorized.')
        );
    }

    public function test_workspace_shows_guest_and_room_information(): void
    {
        [$stay] = $this->checkedInStay('1802');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('departureWorkspace.views.dueOutToday.0.guest.id', $stay->guest_id)
            ->where('departureWorkspace.views.dueOutToday.0.room.id', $stay->current_room_id)
            ->where('departureWorkspace.views.dueOutToday.0.reservation_id', $stay->reservation_id)
        );
    }

    // ── Workspace exposes no checkout/settlement/payment action ──

    public function test_workspace_does_not_show_checkout_action(): void
    {
        [$stay] = $this->checkedInStay('1803');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('departureWorkspace.views.dueOutToday.0.actions.can_check_out')
            ->missing('departureWorkspace.views.dueOutToday.0.actions.can_settle')
            ->missing('departureWorkspace.views.dueOutToday.0.actions.can_take_payment')
            ->missing('departureWorkspace.views.dueOutToday.0.actions.can_close_folio')
        );
    }

    public function test_workspace_has_no_financial_ui_labels(): void
    {
        [$stay] = $this->checkedInStay('1804');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('departureWorkspace.views.dueOutToday.0.balance')
            ->missing('departureWorkspace.views.dueOutToday.0.paid')
            ->missing('departureWorkspace.views.dueOutToday.0.settlement')
            ->missing('departureWorkspace.views.dueOutToday.0.folio')
            ->missing('departureWorkspace.views.dueOutToday.0.invoice')
            ->missing('departureWorkspace.views.dueOutToday.0.receipt')
            ->missing('departureWorkspace.views.dueOutToday.0.revenue')
            ->missing('departureWorkspace.views.dueOutToday.0.tax')
        );
    }

    // ── Workspace read does not mutate domain tables ──

    public function test_workspace_read_does_not_mutate_domain_tables(): void
    {
        [$stay] = $this->checkedInStay('1805');
        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    // ── Snapshots are correct ──

    public function test_snapshots_are_correct(): void
    {
        [$stay] = $this->checkedInStay('1806');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get('/frontdesk/departures')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('departureWorkspace.snapshots.dueOutToday', 1)
            ->where('departureWorkspace.snapshots.departureOperationallyReady', 1)
            ->where('departureWorkspace.snapshots.departureOperationallyBlocked', 0)
            ->where('departureWorkspace.snapshots.overdueDeparture', 0)
        );
    }

    // ── Helpers ──

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
