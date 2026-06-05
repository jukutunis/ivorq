<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Services\FrontDeskService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class FrontDeskModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    private function boot(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    private function confirmedReservationWithCleanRoom(array $boot): array
    {
        ['property' => $property] = $boot;
        $guest = $this->makePmsGuest($property);
        $room  = $this->makePmsRoom($property, [
            'cleanliness_status' => RoomCleanlinessStatusEnum::Clean->value,
            'occupancy_status'   => RoomOccupancyStatusEnum::Vacant->value,
        ]);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        return compact('guest', 'room', 'reservation');
    }

    // ── Check-in: success ─────────────────────────────────────────────────────

    public function test_check_in_creates_stay_with_checked_in_status(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation, 'room' => $room, 'guest' => $guest] = $this->confirmedReservationWithCleanRoom($boot);

        $stay = $service->checkIn($reservation->id);

        $this->assertInstanceOf(Stay::class, $stay);
        $this->assertSame(StayStatusEnum::CheckedIn, $stay->status);
        $this->assertSame($room->id,  $stay->room_id);
        $this->assertSame($guest->id, $stay->guest_id);
        $this->assertNotNull($stay->check_in_at);
    }

    public function test_check_in_transitions_reservation_to_checked_in(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $service->checkIn($reservation->id);

        $reservation->refresh();
        $this->assertSame(ReservationStatusEnum::CheckedIn, $reservation->status);
    }

    public function test_check_in_fires_guest_checked_in_and_logs_activity(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $stay = $service->checkIn($reservation->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Stay::class,
            'subject_id'   => $stay->id,
        ]);
    }

    public function test_check_in_listener_marks_room_occupied(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation, 'room' => $room] = $this->confirmedReservationWithCleanRoom($boot);

        $service->checkIn($reservation->id);

        $room->refresh();
        $this->assertSame(RoomOccupancyStatusEnum::Occupied, $room->occupancy_status);
    }

    public function test_check_in_listener_creates_folio_if_missing(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $service->checkIn($reservation->id);

        $this->assertDatabaseHas('folios', [
            'reservation_id' => $reservation->id,
            'status'         => FolioStatusEnum::Open->value,
        ]);
    }

    public function test_check_in_does_not_duplicate_folio_if_already_exists(): void
    {
        $boot    = $this->boot();
        ['property' => $property] = $boot;
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation, 'guest' => $guest] = $this->confirmedReservationWithCleanRoom($boot);

        // Pre-create a folio
        $this->makePmsFolio($reservation, $guest);

        $service->checkIn($reservation->id);

        // Should still have exactly one open folio
        $this->assertSame(
            1,
            Folio::where('reservation_id', $reservation->id)->where('status', FolioStatusEnum::Open->value)->count()
        );
    }

    // ── Check-in: validation guards ────────────────────────────────────────────

    public function test_check_in_fails_when_reservation_not_confirmed(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Tentative->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_no_room_assigned(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_is_dirty(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property, ['cleanliness_status' => RoomCleanlinessStatusEnum::Dirty->value]);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_is_occupied(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property, ['occupancy_status' => RoomOccupancyStatusEnum::Occupied->value]);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_has_active_block(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        RoomBlock::create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'start_at'    => now()->subHour(),
            'end_at'      => now()->addDay(),
        ]);

        $this->expectException(ValidationException::class);
        $service->checkIn($reservation->id);
    }

    public function test_check_in_fails_when_room_already_has_active_stay(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest, [
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

    // ── Check-out: success ────────────────────────────────────────────────────

    public function test_check_out_sets_stay_checked_out_with_timestamp(): void
    {
        $boot      = $this->boot();
        $service   = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $stay       = $service->checkIn($reservation->id);
        $checkedOut = $service->checkOut($stay->id);

        $this->assertSame(StayStatusEnum::CheckedOut, $checkedOut->status);
        $this->assertNotNull($checkedOut->check_out_at);
    }

    public function test_check_out_transitions_reservation_to_checked_out(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $stay = $service->checkIn($reservation->id);
        $service->checkOut($stay->id);

        $reservation->refresh();
        $this->assertSame(ReservationStatusEnum::CheckedOut, $reservation->status);
    }

    public function test_check_out_fires_guest_checked_out_and_logs_activity(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation] = $this->confirmedReservationWithCleanRoom($boot);

        $stay       = $service->checkIn($reservation->id);
        $checkedOut = $service->checkOut($stay->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Stay::class,
            'subject_id'   => $checkedOut->id,
        ]);
    }

    public function test_check_out_listener_marks_room_dirty_and_vacant(): void
    {
        $boot    = $this->boot();
        $service = app(FrontDeskService::class);
        ['reservation' => $reservation, 'room' => $room] = $this->confirmedReservationWithCleanRoom($boot);

        $stay = $service->checkIn($reservation->id);
        $service->checkOut($stay->id);

        $room->refresh();
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty,  $room->cleanliness_status);
        $this->assertSame(RoomOccupancyStatusEnum::Vacant, $room->occupancy_status);
    }

    // ── Check-out: validation guards ──────────────────────────────────────────

    public function test_check_out_fails_when_stay_not_checked_in(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(FrontDeskService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);

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

    // ── Policy ────────────────────────────────────────────────────────────────

    public function test_staff_cannot_check_in(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('checkIn', $reservation)->denied());
    }

    public function test_cross_property_check_in_policy_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'FD-PB-X']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('checkIn', $reservation)->denied());
    }
}
