<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Services\AvailabilityService;
use Modules\Operations\PMS\Services\FolioService;
use Modules\Operations\PMS\Services\FrontDeskService;
use Modules\Operations\PMS\Services\GuestService;
use Modules\Operations\PMS\Services\RatePlanService;
use Modules\Operations\PMS\Services\ReservationService;
use Modules\Operations\PMS\Services\RoomBlockService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class PmsServiceTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('property', 'admin');
    }

    private function makeRoom(Property $property, array $overrides = []): Room
    {
        static $seq = 0;
        $seq++;

        return Room::create(array_merge([
            'property_id'        => $property->id,
            'room_number'        => "10{$seq}",
            'room_type'          => RoomTypeEnum::Standard->value,
            'cleanliness_status' => RoomCleanlinessStatusEnum::Clean->value,
            'occupancy_status'   => RoomOccupancyStatusEnum::Vacant->value,
            'is_active'          => true,
        ], $overrides));
    }

    private function makeGuest(Property $property, array $overrides = []): Guest
    {
        static $seq = 0;
        $seq++;

        return Guest::create(array_merge([
            'property_id' => $property->id,
            'guest_code'  => "GST-{$seq}",
            'full_name'   => "Guest {$seq}",
            'guest_type'  => GuestTypeEnum::Individual->value,
        ], $overrides));
    }

    private function makeRatePlan(Property $property, array $overrides = []): RatePlan
    {
        static $seq = 0;
        $seq++;

        return RatePlan::create(array_merge([
            'property_id' => $property->id,
            'rate_code'   => "RATE-{$seq}",
            'rate_name'   => "Rate Plan {$seq}",
            'plan_type'   => RatePlanTypeEnum::Nightly->value,
            'base_rate'   => 100.00,
            'currency'    => 'USD',
            'is_active'   => true,
        ], $overrides));
    }

    private function makeReservation(Property $property, Guest $guest, array $overrides = []): Reservation
    {
        static $seq = 0;
        $seq++;

        return Reservation::create(array_merge([
            'property_id'        => $property->id,
            'reservation_number' => "RES-{$seq}",
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'children'           => 0,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ], $overrides));
    }

    private function makeConfirmedReservationWithRoom(Property $property): array
    {
        $guest = $this->makeGuest($property);
        $room  = $this->makeRoom($property);

        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        return compact('guest', 'room', 'reservation');
    }

    private function makeRoomBlock(Room $room, array $overrides = []): RoomBlock
    {
        return RoomBlock::create(array_merge([
            'property_id' => $room->property_id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->subHour(),
            'end_at'      => now()->addDay(),
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Container resolution
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_pms_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(GuestService::class,        app(GuestService::class));
        $this->assertInstanceOf(RatePlanService::class,     app(RatePlanService::class));
        $this->assertInstanceOf(ReservationService::class,  app(ReservationService::class));
        $this->assertInstanceOf(AvailabilityService::class, app(AvailabilityService::class));
        $this->assertInstanceOf(RoomBlockService::class,    app(RoomBlockService::class));
        $this->assertInstanceOf(FrontDeskService::class,    app(FrontDeskService::class));
        $this->assertInstanceOf(FolioService::class,        app(FolioService::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GuestService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_guest_service_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(GuestService::class);

        $guest = $service->create([
            'property_id' => $property->id,
            'guest_code'  => 'GST-SVC-001',
            'full_name'   => 'Alice Test',
            'guest_type'  => GuestTypeEnum::Individual->value,
        ]);

        $this->assertInstanceOf(Guest::class, $guest);

        $found = $service->find($guest->id);
        $this->assertSame($guest->id, $found->id);
        $this->assertSame('Alice Test', $found->full_name);
    }

    public function test_guest_service_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(GuestService::class);
        $guest   = $this->makeGuest($property);

        $updated = $service->update($guest->id, ['full_name' => 'Updated Name']);

        $this->assertSame('Updated Name', $updated->full_name);
    }

    public function test_guest_service_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(GuestService::class);
        $guest   = $this->makeGuest($property);

        $this->assertTrue($service->delete($guest->id));
        $this->assertSoftDeleted('guests', ['id' => $guest->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RatePlanService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rate_plan_service_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RatePlanService::class);

        $plan = $service->create([
            'property_id' => $property->id,
            'rate_code'   => 'BAR',
            'rate_name'   => 'Best Available Rate',
            'plan_type'   => RatePlanTypeEnum::Nightly->value,
            'base_rate'   => 150.00,
            'currency'    => 'USD',
            'is_active'   => true,
        ]);

        $this->assertInstanceOf(RatePlan::class, $plan);

        $found = $service->find($plan->id);
        $this->assertSame($plan->id, $found->id);
        $this->assertSame('Best Available Rate', $found->rate_name);
    }

    public function test_rate_plan_service_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RatePlanService::class);
        $plan    = $this->makeRatePlan($property);

        $updated = $service->update($plan->id, ['rate_name' => 'Updated Rate']);
        $this->assertSame('Updated Rate', $updated->rate_name);
    }

    public function test_rate_plan_service_active_returns_only_active_plans(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RatePlanService::class);

        $active   = $this->makeRatePlan($property, ['is_active' => true]);
        $inactive = $this->makeRatePlan($property, ['is_active' => false]);

        $results = $service->active();

        $this->assertTrue($results->contains('id', $active->id));
        $this->assertFalse($results->contains('id', $inactive->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationService — create / update / delete
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reservation_create_fires_event_and_logs_activity(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(ReservationService::class);
        $guest   = $this->makeGuest($property);

        $reservation = $service->create([
            'property_id'        => $property->id,
            'reservation_number' => 'RES-EVT-001',
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 2,
            'children'           => 0,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    public function test_reservation_update_strips_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $updated = $service->update($reservation->id, [
            'adults' => 3,
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->assertSame(3, $updated->adults);
        $this->assertSame(ReservationStatusEnum::Tentative, $updated->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationService — confirm
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reservation_confirm_transitions_status_and_fires_event(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $this->makeRoom($property);

        $confirmed = $service->confirm($reservation->id);

        $this->assertSame(ReservationStatusEnum::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    public function test_reservation_confirm_fails_when_no_rooms_available(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service    = app(ReservationService::class);
        $guest      = $this->makeGuest($property);
        $otherGuest = $this->makeGuest($property);

        // 1 room of type Standard; already fully booked by another confirmed reservation
        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makeReservation($property, $otherGuest, [
            'status'             => ReservationStatusEnum::Confirmed->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $reservation = $this->makeReservation($property, $guest, [
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->confirm($reservation->id);
    }

    public function test_reservation_confirm_throws_on_invalid_status_transition(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status' => ReservationStatusEnum::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->confirm($reservation->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationService — cancel / noShow / assignRoom
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reservation_cancel_transitions_status_and_fires_event(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $cancelled = $service->cancel($reservation->id, 'Guest request');

        $this->assertSame(ReservationStatusEnum::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    public function test_reservation_cancel_throws_from_terminal_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status' => ReservationStatusEnum::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->cancel($reservation->id);
    }

    public function test_reservation_no_show_transitions_from_confirmed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $noShow = $service->noShow($reservation->id);

        $this->assertSame(ReservationStatusEnum::NoShow, $noShow->status);
    }

    public function test_reservation_no_show_throws_from_tentative(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $this->expectException(ValidationException::class);
        $service->noShow($reservation->id);
    }

    public function test_reservation_assign_room_sets_assigned_room_id(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(ReservationService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property);
        $reservation = $this->makeReservation($property, $guest);

        $updated = $service->assignRoom($reservation->id, $room->id);

        $this->assertSame($room->id, $updated->assigned_room_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AvailabilityService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_availability_returns_total_when_no_reservations_or_blocks(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(AvailabilityService::class);

        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $count = $service->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(2, $count);
    }

    public function test_availability_deducts_active_reservations(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(AvailabilityService::class);
        $guest   = $this->makeGuest($property);

        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $this->makeReservation($property, $guest, [
            'status'             => ReservationStatusEnum::Confirmed->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $count = $service->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(1, $count);
    }

    public function test_availability_deducts_blocked_rooms(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(AvailabilityService::class);

        $room1 = $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        RoomBlock::create([
            'property_id' => $property->id,
            'room_id'     => $room1->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => today()->addDay(),
            'end_at'      => today()->addDays(4),
        ]);

        $count = $service->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(1, $count);
    }

    public function test_availability_excludes_reservation_when_id_passed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(AvailabilityService::class);
        $guest   = $this->makeGuest($property);

        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $reservation = $this->makeReservation($property, $guest, [
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        // Without exclusion: 1 room - 1 tentative = 0
        $this->assertSame(0, $service->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ));

        // With exclusion: 1 room - 0 = 1 (reservation not counted against itself)
        $this->assertSame(1, $service->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
            $reservation->id,
        ));
    }

    public function test_availability_is_available_returns_bool(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(AvailabilityService::class);

        $this->assertFalse($service->isAvailable(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ));

        $this->makeRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $this->assertTrue($service->isAvailable(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RoomBlockService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_room_block_create_fires_event_and_persists(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RoomBlockService::class);
        $room    = $this->makeRoom($property);

        $block = $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(2),
        ]);

        $this->assertInstanceOf(RoomBlock::class, $block);
        $this->assertDatabaseHas('room_blocks', ['id' => $block->id]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => RoomBlock::class,
            'subject_id'   => $block->id,
        ]);
    }

    public function test_room_block_create_rejects_overlapping_active_block_for_same_room(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RoomBlockService::class);
        $room    = $this->makeRoom($property);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(3),
        ]);

        $this->expectException(ValidationException::class);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->addDays(2),
            'end_at'      => now()->addDays(4),
        ]);
    }

    public function test_room_block_release_sets_status_and_timestamps(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(RoomBlockService::class);
        $room    = $this->makeRoom($property);
        $block   = $this->makeRoomBlock($room);

        $released = $service->release($block->id);

        $this->assertSame(RoomBlockStatusEnum::Released, $released->status);
        $this->assertNotNull($released->released_at);
        $this->assertSame($admin->id, $released->released_by);
    }

    public function test_room_block_expire_sets_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RoomBlockService::class);
        $room    = $this->makeRoom($property);
        $block   = $this->makeRoomBlock($room, [
            'start_at' => now()->subDays(2),
            'end_at'   => now()->subHour(),
        ]);

        $expired = $service->expire($block->id);

        $this->assertSame(RoomBlockStatusEnum::Expired, $expired->status);
    }

    public function test_room_block_expire_throws_from_released_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(RoomBlockService::class);
        $room    = $this->makeRoom($property);
        $block   = $this->makeRoomBlock($room, [
            'status'   => RoomBlockStatusEnum::Released->value,
            'start_at' => now()->subDays(2),
            'end_at'   => now()->subHour(),
        ]);

        $this->expectException(ValidationException::class);
        $service->expire($block->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FrontDeskService — checkIn
    // ─────────────────────────────────────────────────────────────────────────

    public function test_check_in_creates_stay_and_transitions_reservation_to_checked_in(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation, 'room' => $room, 'guest' => $guest] = $this->makeConfirmedReservationWithRoom($property);

        $stay = $service->checkIn($reservation->id);

        $this->assertInstanceOf(Stay::class, $stay);
        $this->assertSame(StayStatusEnum::CheckedIn, $stay->status);
        $this->assertNotNull($stay->check_in_at);
        $this->assertSame($room->id, $stay->room_id);
        $this->assertSame($guest->id, $stay->guest_id);

        $reservation->refresh();
        $this->assertSame(ReservationStatusEnum::CheckedIn, $reservation->status);
    }

    public function test_check_in_fires_guest_checked_in_and_logs_activity(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->makeConfirmedReservationWithRoom($property);

        $stay = $service->checkIn($reservation->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Stay::class,
            'subject_id'   => $stay->id,
        ]);
    }

    public function test_check_in_fails_when_reservation_not_confirmed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Tentative->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_no_room_assigned(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_is_dirty(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property, [
            'cleanliness_status' => RoomCleanlinessStatusEnum::Dirty->value,
        ]);
        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_is_occupied(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property, [
            'occupancy_status' => RoomOccupancyStatusEnum::Occupied->value,
        ]);
        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_has_active_block(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->makeRoomBlock($room);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_already_has_active_stay(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property);
        $reservation = $this->makeReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        Stay::create([
            'property_id'           => $property->id,
            'reservation_id'        => $reservation->id,
            'room_id'               => $room->id,
            'guest_id'              => $guest->id,
            'status'                => StayStatusEnum::CheckedIn->value,
            'check_in_at'           => now()->subHour(),
            'expected_departure_at' => now()->addDay(),
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FrontDeskService — checkOut
    // ─────────────────────────────────────────────────────────────────────────

    public function test_check_out_sets_stay_checked_out_and_transitions_reservation(): void
    {
        ['property' => $property] = $this->bootProperty();
        $frontDesk = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->makeConfirmedReservationWithRoom($property);

        $stay       = $frontDesk->checkIn($reservation->id);
        $checkedOut = $frontDesk->checkOut($stay->id);

        $this->assertSame(StayStatusEnum::CheckedOut, $checkedOut->status);
        $this->assertNotNull($checkedOut->check_out_at);

        $reservation->refresh();
        $this->assertSame(ReservationStatusEnum::CheckedOut, $reservation->status);
    }

    public function test_check_out_fires_guest_checked_out_and_logs_activity(): void
    {
        ['property' => $property] = $this->bootProperty();
        $frontDesk = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->makeConfirmedReservationWithRoom($property);

        $stay       = $frontDesk->checkIn($reservation->id);
        $checkedOut = $frontDesk->checkOut($stay->id);

        // GuestCheckedOut fires → LogPmsActivity logs the stay
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Stay::class,
            'subject_id'   => $checkedOut->id,
        ]);
    }

    public function test_check_out_fails_when_stay_is_not_checked_in(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makeGuest($property);
        $room        = $this->makeRoom($property);
        $reservation = $this->makeReservation($property, $guest);

        $stay = Stay::create([
            'property_id'           => $property->id,
            'reservation_id'        => $reservation->id,
            'room_id'               => $room->id,
            'guest_id'              => $guest->id,
            'status'                => StayStatusEnum::CheckedOut->value,
            'check_in_at'           => now()->subDay(),
            'expected_departure_at' => now(),
            'check_out_at'          => now(),
        ]);

        $this->expectException(ValidationException::class);
        $service->checkOut($stay->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioService — createForReservation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_create_for_reservation_opens_folio_and_fires_event(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $this->assertInstanceOf(Folio::class, $folio);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
        $this->assertSame($reservation->id, $folio->reservation_id);
        $this->assertSame($guest->id, $folio->guest_id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Folio::class,
            'subject_id'   => $folio->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioService — postItem / voidItem / recalculateTotals
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_post_item_creates_line_and_recalculates_totals(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $item = $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge night 1',
            'quantity'    => 1,
            'amount'      => 150.00,
        ]);

        $this->assertDatabaseHas('folio_items', ['id' => $item->id, 'is_void' => false]);

        $folio->refresh();
        $this->assertSame('150.00', $folio->total_charges);
        $this->assertSame('0.00',   $folio->total_payments);
        $this->assertSame('150.00', $folio->balance);
    }

    public function test_folio_post_item_rejects_posting_to_non_open_folio(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = Folio::create([
            'property_id'    => $property->id,
            'folio_number'   => 'FOL-CLOSED',
            'reservation_id' => $reservation->id,
            'guest_id'       => $guest->id,
            'status'         => FolioStatusEnum::Closed->value,
            'total_charges'  => 0,
            'total_payments' => 0,
            'balance'        => 0,
        ]);

        $this->expectException(ValidationException::class);
        $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Charge',
            'quantity'    => 1,
            'amount'      => 100,
        ]);
    }

    public function test_folio_void_item_marks_void_and_removes_from_totals(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $item = $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 200.00,
        ]);

        $voided = $service->voidItem($item->id);

        $this->assertTrue($voided->is_void);

        $folio->refresh();
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->balance);
    }

    public function test_folio_void_item_throws_if_already_voided(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $item = $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $service->voidItem($item->id);

        $this->expectException(ValidationException::class);
        $service->voidItem($item->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioService — close / void
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_close_transitions_to_closed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $closed = $service->close($folio->id);

        $this->assertSame(FolioStatusEnum::Closed, $closed->status);
    }

    public function test_folio_void_transitions_to_void(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $voided = $service->void($folio->id);

        $this->assertSame(FolioStatusEnum::Void, $voided->status);
    }

    public function test_folio_close_throws_from_already_closed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $service->close($folio->id);

        $this->expectException(ValidationException::class);
        $service->close($folio->id);
    }

    public function test_folio_void_throws_from_already_closed(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service     = app(FolioService::class);
        $guest       = $this->makeGuest($property);
        $reservation = $this->makeReservation($property, $guest);

        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001',
            'currency'     => 'USD',
        ]);

        $service->close($folio->id);

        $this->expectException(ValidationException::class);
        $service->void($folio->id);
    }
}
