<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskRoomAssignmentTest extends PostgresTestCase
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

    public function test_unauthenticated_and_exact_permission_assignment_boundary(): void
    {
        [$reservation, , $room] = $this->assignReadyReservation('901');

        $this->postJson('/frontdesk/room-assignments', [
            'reservation_id' => $reservation,
            'room_id' => $room,
            'idempotency_key' => 'unauth-' . Str::ulid(),
        ])->assertUnauthorized();

        foreach ([$this->frontDeskViewOnlyActor, $this->financeActor] as $actor) {
            try {
                app(FrontDeskRoomAssignmentService::class)->assign($actor, $reservation, $room, null, 'noperm-' . Str::ulid());
                $this->fail('Assignment must require the exact Front Desk assignment permission.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_assignment_is_server_resolved_idempotent_immutable_and_non_mutating(): void
    {
        [$reservation, $guest, $room] = $this->assignReadyReservation('902');
        $before = $this->domainTableCounts();
        $idempotency = 'assign-' . Str::ulid();

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson('/frontdesk/room-assignments', [
                'property_id' => $this->otherProperty->id,
                'reservation_id' => $reservation,
                'guest_id' => (string) Str::ulid(),
                'room_id' => $room,
                'status' => 'IN_HOUSE',
                'assignment_kind' => 'ROOM_MOVE',
                'occurred_at' => '2020-01-01T00:00:00Z',
                'created_by' => $this->financeActor->id,
                'readiness_state' => 'ready_for_arrival',
                'engineering_status' => 'ENGINEERING_AVAILABLE',
                'idempotency_key' => $idempotency,
            ]);

        $response->assertOk()->assertJson([
            'status' => 'ROOM_ASSIGNED',
            'replayed' => false,
        ]);

        $stay = FrontDeskStay::withoutGlobalScopes()->firstOrFail();
        $assignment = FrontDeskRoomAssignment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->property->id, $stay->property_id);
        $this->assertSame($reservation, $stay->reservation_id);
        $this->assertSame($guest, $stay->guest_id);
        $this->assertSame('ROOM_ASSIGNED', $stay->status->value);
        $this->assertSame($room, $stay->current_room_id);
        $this->assertSame($assignment->id, $stay->current_room_assignment_id);
        $this->assertSame('INITIAL_ASSIGNMENT', $assignment->assignment_kind->value);
        $this->assertSame($this->frontDeskActor->id, $assignment->created_by);
        $this->assertSame('2026-07-08T09:00:00.000000Z', $assignment->occurred_at->toISOString());

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson('/frontdesk/room-assignments', [
                'reservation_id' => $reservation,
                'room_id' => $room,
                'idempotency_key' => $idempotency,
            ])->assertOk()->assertJson(['replayed' => true]);

        $this->assertSame(1, DB::table('front_desk_stays')->count());
        $this->assertSame(1, DB::table('front_desk_room_assignments')->count());

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);

        $this->expectException(DomainException::class);
        $assignment->assignment_reason = 'attempted update';
        $assignment->save();
    }

    public function test_assignment_source_blockers_fail_closed(): void
    {
        [$reservation, , $room] = $this->assignReadyReservation('903');

        $this->expectAssignmentFailure($this->reservation($this->otherProperty, $this->guest($this->otherProperty), 'RES-XPROP', 'confirmed', $room), $room);
        $this->expectAssignmentFailure($reservation, $this->room($this->otherProperty, '1903'));
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-CAN', 'cancelled', $room), $room);
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-NOS', 'no_show', $room), $room);

        $hkRoom = $this->room($this->property, '904', ['readiness_state' => 'waiting_engineering']);
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-HK', 'confirmed', $hkRoom), $hkRoom);

        $blockedRoom = $this->room($this->property, '905');
        $this->activeEngineeringBlock($blockedRoom);
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-ENG', 'confirmed', $blockedRoom), $blockedRoom);

        $otherTenantRoom = $this->room($this->otherTenantProperty, '9905');
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-UNKNOWN', 'confirmed', null), $otherTenantRoom);

        $mismatchRoom = $this->room($this->property, '906', ['room_type' => 'standard']);
        $this->expectAssignmentFailure($this->reservation($this->property, $this->guest($this->property), 'RES-TYPE', 'confirmed', $mismatchRoom), $mismatchRoom);
    }

    public function test_same_room_cannot_be_assigned_to_two_active_stays(): void
    {
        $room = $this->room($this->property, '907');
        $first = $this->reservation($this->property, $this->guest($this->property, 'First Guest'), 'RES-FIRST', 'confirmed', $room);
        $second = $this->reservation($this->property, $this->guest($this->property, 'Second Guest'), 'RES-SECOND', 'confirmed', $room);

        app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $first, $room, null, 'first-' . Str::ulid());

        $this->expectException(DomainException::class);
        app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $second, $room, null, 'second-' . Str::ulid());
    }

    private function expectAssignmentFailure(string $reservationId, string $roomId): void
    {
        try {
            app(CurrentPropertyService::class)->setPropertyId($this->property->id);
            session($this->propertySession($this->property));
            app(FrontDeskRoomAssignmentService::class)->assign(
                $this->frontDeskActor,
                $reservationId,
                $roomId,
                null,
                'fail-' . Str::ulid()
            );
            $this->fail('Assignment should fail closed.');
        } catch (DomainException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
