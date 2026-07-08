<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckoutReadinessTest extends PostgresTestCase
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

    public function test_unauthenticated_readiness_access_denied(): void
    {
        [$stay] = $this->checkedInStay('1601');
        $this->getJson("/frontdesk/stays/{$stay->id}/checkout-readiness")->assertUnauthorized();
    }

    public function test_checkout_readiness_view_permission_required(): void
    {
        [$stay] = $this->checkedInStay('1602');

        try {
            app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->financeActor, $stay->id);
            $this->fail('Checkout readiness must require exact Front Desk permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_active_property_required(): void
    {
        [$stay] = $this->checkedInStay('1603');
        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame($this->property->id, $readiness['property_id']);
    }

    public function test_cross_property_stay_rejected(): void
    {
        $otherRoom = $this->room($this->otherProperty, '2603');
        $otherGuest = $this->guest($this->otherProperty);
        $otherReservation = $this->reservation($this->otherProperty, $otherGuest, 'RES-CP-RDY', 'confirmed', $otherRoom);
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

        $this->expectException(HttpException::class);
        app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, (string) Str::ulid());
    }

    public function test_cross_tenant_stay_rejected(): void
    {
        $crossRoom = $this->room($this->otherTenantProperty, '3601');
        $crossGuest = $this->guest($this->otherTenantProperty);
        $crossReservation = $this->reservation($this->otherTenantProperty, $crossGuest, 'RES-CT-RDY', 'confirmed', $crossRoom);
        DB::table('front_desk_stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherTenantProperty->id,
            'reservation_id' => $crossReservation,
            'guest_id' => $crossGuest,
            'status' => 'IN_HOUSE',
            'current_room_id' => $crossRoom,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(HttpException::class);
        app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, (string) Str::ulid());
    }

    public function test_in_house_stay_produces_checkout_operationally_ready(): void
    {
        [$stay, $room] = $this->checkedInStay('1604');
        $before = $this->domainTableCounts();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);

        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::READY, $readiness['readiness_status']);
        $this->assertSame([], $readiness['operational_blockers']);
        $this->assertSame('Financial settlement: Not evaluated in Front Desk Package A.', $readiness['financial_marker']);
        $this->assertSame($stay->id, $readiness['front_desk_stay_id']);
        $this->assertSame($this->property->id, $readiness['property_id']);
        $this->assertNotNull($readiness['evaluated_at']);

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    public function test_non_in_house_stay_produces_blocked(): void
    {
        [$reservation, , $room] = $this->assignReadyReservation('1605');
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid());

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $assigned['stay']->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
        $this->assertNotEmpty($readiness['operational_blockers']);
    }

    public function test_missing_current_room_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1606');
        DB::table('front_desk_stays')->where('id', $stay->id)->update(['current_room_id' => null]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_missing_current_assignment_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1607');
        DB::table('front_desk_stays')->where('id', $stay->id)->update(['current_room_assignment_id' => null]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_current_assignment_room_mismatch_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1608');
        $otherRoom = $this->room($this->property, '1609');
        DB::table('front_desk_stays')->where('id', $stay->id)->update(['current_room_id' => $otherRoom]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_unresolvable_guest_source_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1610');
        $otherGuest = $this->guest($this->otherProperty);
        DB::table('front_desk_stays')->where('id', $stay->id)->update(['guest_id' => $otherGuest]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_unresolvable_reservation_source_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1611');
        $otherGuest = $this->guest($this->otherProperty);
        $otherReservation = $this->reservation($this->otherProperty, $otherGuest, 'RES-OTHER-DEL', 'confirmed', $this->room($this->otherProperty, '2611'));
        DB::table('front_desk_stays')->where('id', $stay->id)->update(['reservation_id' => $otherReservation]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_housekeeping_blocking_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1612');
        $room = $this->room($this->property, '1613', ['readiness_state' => 'waiting_inspection']);
        DB::table('front_desk_stays')->where('id', $stay->id)->update([
            'current_room_id' => $room,
            'current_room_assignment_id' => null,
        ]);
        DB::table('front_desk_room_assignments')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'room_id' => $room,
            'room_type_id' => null,
            'assignment_kind' => 'ROOM_MOVE',
            'occurred_at' => now(),
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'idempotency_key' => 'hk-block-' . Str::ulid(),
            'source_hash' => hash('sha256', 'hk-block'),
        ]);
        DB::table('front_desk_stays')->where('id', $stay->id)->update([
            'current_room_assignment_id' => DB::table('front_desk_room_assignments')
                ->where('front_desk_stay_id', $stay->id)
                ->where('assignment_kind', 'ROOM_MOVE')
                ->value('id'),
        ]);
        $stay = $stay->fresh();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_engineering_blocked_blocks_readiness(): void
    {
        [$stay] = $this->checkedInStay('1614');
        $this->activeEngineeringBlock($stay->current_room_id);

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertSame(FrontDeskCheckoutReadinessProjectionService::BLOCKED, $readiness['readiness_status']);
    }

    public function test_readiness_does_not_mutate_front_desk_stay(): void
    {
        [$stay] = $this->checkedInStay('1615');
        $before = $stay->toArray();

        app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);

        $after = $stay->fresh()->toArray();
        $this->assertSame($before, $after);
    }

    public function test_readiness_does_not_mutate_domain_tables(): void
    {
        [$stay] = $this->checkedInStay('1616');
        $before = $this->domainTableCounts();

        $readiness = app(FrontDeskCheckoutReadinessProjectionService::class)->ready($this->frontDeskActor, $stay->id);
        $this->assertNotNull($readiness);

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    public function test_browser_cannot_control_readiness_status(): void
    {
        [$stay] = $this->checkedInStay('1617');

        $this->actingAs($this->frontDeskActor, 'web')
            ->withSession($this->propertySession($this->property))
            ->getJson("/frontdesk/stays/{$stay->id}/checkout-readiness?readiness_status=CHECKED_OUT&folio=paid")
            ->assertOk()
            ->assertJsonPath('readiness_status', FrontDeskCheckoutReadinessProjectionService::READY);
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
