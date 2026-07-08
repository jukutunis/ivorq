<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Enums\FrontDeskDeparturePreparationEventTypeEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventTest extends PostgresTestCase
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

    // ── Event creation ──

    public function test_create_departure_note_event(): void
    {
        [$stay] = $this->checkedInStay('3001');
        $idempotencyKey = 'dpe-' . Str::ulid();

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor,
            $stay->id,
            'DEPARTURE_NOTE_RECORDED',
            'Guest requests late checkout.',
            $idempotencyKey
        );

        $this->assertFalse($result['replayed']);
        $this->assertInstanceOf(FrontDeskDeparturePreparationEvent::class, $result['event']);
        $this->assertSame('DEPARTURE_NOTE_RECORDED', $result['event']->event_type->value);
        $this->assertSame('Guest requests late checkout.', $result['event']->note);
        $this->assertSame($stay->id, $result['event']->front_desk_stay_id);
        $this->assertSame($stay->reservation_id, $result['event']->reservation_id);
        $this->assertSame($stay->guest_id, $result['event']->guest_id);
        $this->assertSame($stay->current_room_id, $result['event']->room_id);
        $this->assertSame($this->frontDeskActor->id, $result['event']->created_by);
        $this->assertSame($idempotencyKey, $result['event']->idempotency_key);
        $this->assertNotEmpty($result['event']->source_hash);
        $this->assertNotNull($result['event']->occurred_at);
    }

    public function test_create_all_allowed_event_types(): void
    {
        [$stay] = $this->checkedInStay('3002');

        $types = [
            'DEPARTURE_NOTE_RECORDED',
            'DEPARTURE_TIME_CONFIRMED',
            'LUGGAGE_ASSISTANCE_NOTED',
            'TRANSPORTATION_NOTED',
            'OPERATIONAL_BLOCKER_ACKNOWLEDGED',
            'GUEST_MESSAGE_NOTED',
        ];

        foreach ($types as $type) {
            $result = app(FrontDeskDeparturePreparationEventService::class)->create(
                $this->frontDeskActor,
                $stay->id,
                $type,
                null,
                'dpe-' . Str::ulid()
            );

            $this->assertFalse($result['replayed']);
            $this->assertSame($type, $result['event']->event_type->value);
        }

        $this->assertSame(6, FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)
            ->count());
    }

    public function test_event_without_note(): void
    {
        [$stay] = $this->checkedInStay('3003');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_TIME_CONFIRMED', null, 'dpe-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
        $this->assertNull($result['event']->note);
    }

    // ── Idempotency ──

    public function test_duplicate_idempotency_key_returns_existing_event(): void
    {
        [$stay] = $this->checkedInStay('3004');
        $key = 'dpe-idem-' . Str::ulid();

        $first = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'First attempt.', $key
        );

        $second = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_TIME_CONFIRMED', 'Second attempt with different type.', $key
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['event']->id, $second['event']->id);
        $this->assertSame('DEPARTURE_NOTE_RECORDED', $second['event']->event_type->value);
        $this->assertSame('First attempt.', $second['event']->note);
        $this->assertSame(1, FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->where('idempotency_key', $key)->count());
    }

    public function test_duplicate_source_hash_returns_existing_event(): void
    {
        [$stay] = $this->checkedInStay('3005');

        $first = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'GUEST_MESSAGE_NOTED', 'Same message content.', 'dpe-a-' . Str::ulid()
        );

        // Second attempt with same stay, type, note at same time → same source_hash
        $second = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'GUEST_MESSAGE_NOTED', 'Same message content.', 'dpe-b-' . Str::ulid()
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['event']->id, $second['event']->id);
    }

    // ── Only IN_HOUSE stays ──

    public function test_cannot_create_event_for_non_in_house_stay(): void
    {
        [$reservation, , $room] = $this->assignReadyReservation('3101');
        $assigned = app(\Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid()
        );
        $stay = $assigned['stay'];

        $this->assertSame('ROOM_ASSIGNED', $stay->status->value);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('IN_HOUSE');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );
    }

    // ── Cross-property rejected ──

    public function test_cross_property_stay_rejected(): void
    {
        [$stay] = $this->checkedInStay('3102');

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        setPermissionsTeamId($this->otherProperty->id);
        session($this->propertySession($this->otherProperty));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not found');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );
    }

    // ── Forbidden event types rejected ──

    public function test_forbidden_event_types_rejected(): void
    {
        [$stay] = $this->checkedInStay('3103');

        $forbidden = [
            'CHECKOUT_EXECUTED', 'CHECKED_OUT', 'SETTLED', 'PAYMENT_TAKEN',
            'FOLIO_CLOSED', 'BALANCE_CLEARED', 'INVOICE_GENERATED',
            'REVENUE_POSTED', 'TAX_POSTED', 'AR_CLEARED', 'GL_POSTED', 'NIGHT_AUDIT_READY',
        ];

        foreach ($forbidden as $type) {
            try {
                app(FrontDeskDeparturePreparationEventService::class)->create(
                    $this->frontDeskActor, $stay->id, $type, null, 'dpe-' . Str::ulid()
                );
                $this->fail("Expected DomainException for forbidden event type: {$type}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Invalid event type', $e->getMessage());
            }
        }

        $this->assertSame(0, FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->where('front_desk_stay_id', $stay->id)->count());
    }

    // ── Invalid event type rejected ──

    public function test_nonexistent_event_type_rejected(): void
    {
        [$stay] = $this->checkedInStay('3104');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invalid event type');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'NONEXISTENT_TYPE', null, 'dpe-' . Str::ulid()
        );
    }

    // ── Note length bounded ──

    public function test_note_length_bounded(): void
    {
        [$stay] = $this->checkedInStay('3105');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('2000');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', str_repeat('x', 2001), 'dpe-' . Str::ulid()
        );
    }

    // ── Event creation does not mutate stay status ──

    public function test_event_creation_does_not_mutate_stay_status(): void
    {
        [$stay] = $this->checkedInStay('3106');
        $beforeStatus = $stay->status->value;

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $afterStatus = $stay->fresh()->status->value;
        $this->assertSame($beforeStatus, $afterStatus);
        $this->assertSame('IN_HOUSE', $afterStatus);
    }

    // ── Event creation does not touch source aggregates ──

    public function test_event_creation_does_not_mutate_reservation(): void
    {
        [$stay] = $this->checkedInStay('3107');
        $before = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_guest(): void
    {
        [$stay] = $this->checkedInStay('3108');
        $before = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_room_master(): void
    {
        [$stay, $roomId] = $this->checkedInStay('3109');
        $before = DB::table('rooms')->where('id', $roomId)->value('room_number');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('rooms')->where('id', $roomId)->value('room_number');
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_housekeeping(): void
    {
        [$stay, $roomId] = $this->checkedInStay('3110');
        $before = DB::table('rooms')->where('id', $roomId)->value('readiness_state');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('rooms')->where('id', $roomId)->value('readiness_state');
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_engineering(): void
    {
        [$stay, $roomId] = $this->checkedInStay('3111');
        $before = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)->count();

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)->count();
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_assignments(): void
    {
        [$stay] = $this->checkedInStay('3112');
        $stayId = $stay->id;
        $before = DB::table('front_desk_room_assignments')
            ->where('front_desk_stay_id', $stayId)->count();

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('front_desk_room_assignments')
            ->where('front_desk_stay_id', $stayId)->count();
        $this->assertSame($before, $after);
    }

    // ── No financial tables touched ──

    public function test_event_creation_does_not_touch_financial_tables(): void
    {
        [$stay] = $this->checkedInStay('3113');
        $before = $this->domainTableCounts();

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }
}
