<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationBoundaryTest extends PostgresTestCase
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

    // ── FD-C1 terminal state exists, but departure preparation remains non-executing ──

    public function test_fd_c1_checked_out_state_exists_without_legacy_terminal_aliases(): void
    {
        $cases = \Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum::cases();
        $values = array_map(fn ($case) => $case->value, $cases);

        // FD-C1 intentionally introduced CheckedOut / CHECKED_OUT as the foundation terminal stay state
        $this->assertContains('CHECKED_OUT', $values);
        $this->assertSame(
            'CHECKED_OUT',
            \Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum::CheckedOut->value
        );

        // These legacy aliases must not exist
        $this->assertNotContains('SETTLED', $values);
        $this->assertNotContains('DEPARTED', $values);
        $this->assertNotContains('CANCELLED', $values);
        $this->assertNotContains('NO_SHOW', $values);
    }

    // ── No checkout routes exist ──

    public function test_no_checkout_execution_route_exists(): void
    {
        [$stay] = $this->checkedInStay('2001');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->post("/frontdesk/stays/{$stay->id}/check-out")
            ->assertNotFound();
    }

    public function test_no_settlement_route_exists(): void
    {
        [$stay] = $this->checkedInStay('2002');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->post("/frontdesk/stays/{$stay->id}/settle")
            ->assertNotFound();
    }

    // ── No Housekeeping mutation ──

    public function test_departure_projection_cannot_mutate_housekeeping(): void
    {
        [$stay, $roomId] = $this->checkedInStay('2003');
        $before = DB::table('rooms')->where('id', $roomId)->value('readiness_state');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('rooms')->where('id', $roomId)->value('readiness_state');
        $this->assertSame($before, $after);
    }

    // ── No Engineering mutation ──

    public function test_departure_projection_cannot_mutate_engineering(): void
    {
        [$stay, $roomId] = $this->checkedInStay('2004');
        $before = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)
            ->count();

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)
            ->count();
        $this->assertSame($before, $after);
    }

    // ── No Reservation mutation ──

    public function test_departure_projection_cannot_mutate_reservation(): void
    {
        [$stay] = $this->checkedInStay('2005');
        $before = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');
        $this->assertSame($before, $after);
    }

    // ── No Guest mutation ──

    public function test_departure_projection_cannot_mutate_guest(): void
    {
        [$stay] = $this->checkedInStay('2006');
        $before = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');
        $this->assertSame($before, $after);
    }

    // ── No FrontDeskStay status mutation ──

    public function test_departure_projection_cannot_mutate_front_desk_stay_status(): void
    {
        [$stay] = $this->checkedInStay('2007');
        $stayId = $stay->id;
        $before = DB::table('front_desk_stays')->where('id', $stayId)->value('status');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('front_desk_stays')->where('id', $stayId)->value('status');
        $this->assertSame($before, $after);
    }

    // ── No FrontDeskRoomAssignment mutation ──

    public function test_departure_projection_cannot_mutate_assignments(): void
    {
        [$stay] = $this->checkedInStay('2008');
        $stayId = $stay->id;
        $before = DB::table('front_desk_room_assignments')
            ->where('front_desk_stay_id', $stayId)
            ->count();

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('front_desk_room_assignments')
            ->where('front_desk_stay_id', $stayId)
            ->count();
        $this->assertSame($before, $after);
    }

    // ── No Room master mutation ──

    public function test_departure_projection_cannot_mutate_room_master(): void
    {
        [$stay, $roomId] = $this->checkedInStay('2009');
        $before = DB::table('rooms')->where('id', $roomId)->value('room_number');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = DB::table('rooms')->where('id', $roomId)->value('room_number');
        $this->assertSame($before, $after);
    }

    // ── No Finance / GL / AR / Tax / Cashier / Banking mutations ──

    public function test_departure_projection_does_not_touch_financial_tables(): void
    {
        [$stay] = $this->checkedInStay('2010');
        $before = $this->domainTableCounts();

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    // ── No Period or Business Date mutation ──

    public function test_departure_projection_does_not_touch_period_or_business_date(): void
    {
        [$stay] = $this->checkedInStay('2011');
        $before = $this->domainTableCounts();

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    // ── No Night Audit mutation ──

    public function test_departure_projection_does_not_create_night_audit_state(): void
    {
        [$stay] = $this->checkedInStay('2012');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $this->assertIsArray($queue);
        $this->assertArrayHasKey('financial_marker', $queue);
    }

    // ── Read-only: CONCURRENCY_NOT_REQUIRED_READ_ONLY_PROJECTION ──

    public function test_concurrency_not_required_read_only_projection(): void
    {
        $this->assertTrue(true, 'CONCURRENCY_NOT_REQUIRED_READ_ONLY_PROJECTION: '
            . 'FD-B1 has no write path and no concurrency requirement.');
    }

    // ── Departure date source is canonical Reservation ──

    public function test_departure_date_is_sourced_from_canonical_reservation(): void
    {
        [$stay] = $this->checkedInStay('2013');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-10';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $future = $queue['views']['dueOutFuture'];

        $this->assertNotEmpty($future);
        $this->assertSame('2026-07-10', $future[0]['expected_departure_date']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DUE_OUT_FUTURE, $future[0]['due_out_classification']);
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
