<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Services\AvailabilityService;
use Modules\Operations\PMS\Services\ReservationService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class ReservationModuleTest extends TestCase
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

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_reservation_defaults_to_tentative(): void
    {
        ['property' => $property] = $this->boot();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->assertSame(ReservationStatusEnum::Tentative, $reservation->status);
    }

    public function test_create_reservation_fires_event_and_logs_activity(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(ReservationService::class);
        $guest   = $this->makePmsGuest($property);

        $reservation = $service->create([
            'property_id'        => $property->id,
            'reservation_number' => 'RES-MOD-001',
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'children'           => 0,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_reservation_changes_adults_and_strips_status(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $updated = $service->update($reservation->id, [
            'adults' => 3,
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->assertSame(3, $updated->adults);
        $this->assertSame(ReservationStatusEnum::Tentative, $updated->status);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_reservation_soft_deletes(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->assertTrue($service->delete($reservation->id));
        $this->assertSoftDeleted('reservations', ['id' => $reservation->id]);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_reservation_number_must_be_unique_per_property(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);

        $this->makePmsReservation($property, $guest, ['reservation_number' => 'RES-DUPE']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makePmsReservation($property, $guest, ['reservation_number' => 'RES-DUPE']);
    }

    public function test_reservation_number_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-B01']);
        $guestA    = $this->makePmsGuest($propertyA);
        $guestB    = $this->makePmsGuest($propertyB);

        $this->makePmsReservation($propertyA, $guestA, ['reservation_number' => 'RES-SHARED']);
        $this->makePmsReservation($propertyB, $guestB, ['reservation_number' => 'RES-SHARED']);

        $this->assertDatabaseHas('reservations', ['property_id' => $propertyA->id, 'reservation_number' => 'RES-SHARED']);
        $this->assertDatabaseHas('reservations', ['property_id' => $propertyB->id, 'reservation_number' => 'RES-SHARED']);
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    public function test_confirm_transitions_status_to_confirmed_and_logs_activity(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        // Provide a room so availability check passes
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $confirmed = $service->confirm($reservation->id);

        $this->assertSame(ReservationStatusEnum::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    public function test_confirm_fails_when_no_rooms_available(): void
    {
        ['property' => $property] = $this->boot();
        $service    = app(ReservationService::class);
        $guest      = $this->makePmsGuest($property);
        $otherGuest = $this->makePmsGuest($property);

        // 1 room; already taken by another confirmed reservation
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsReservation($property, $otherGuest, [
            'status'             => ReservationStatusEnum::Confirmed->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $reservation = $this->makePmsReservation($property, $guest, [
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->confirm($reservation->id);
    }

    public function test_confirm_fails_when_room_blocked_for_full_date_range(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(ReservationService::class);
        $guest   = $this->makePmsGuest($property);
        $room    = $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        // Block the only room for the same period
        RoomBlock::create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'start_at'    => today()->addDay(),
            'end_at'      => today()->addDays(4),
        ]);

        $reservation = $this->makePmsReservation($property, $guest, [
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->confirm($reservation->id);
    }

    public function test_confirm_invalid_transition_throws(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->confirm($reservation->id);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_transitions_status_and_logs_activity(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $cancelled = $service->cancel($reservation->id, 'Guest request');

        $this->assertSame(ReservationStatusEnum::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Reservation::class,
            'subject_id'   => $reservation->id,
        ]);
    }

    public function test_cancel_fails_from_terminal_status(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->cancel($reservation->id);
    }

    // ── NoShow ────────────────────────────────────────────────────────────────

    public function test_no_show_transitions_from_confirmed(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $result = $service->noShow($reservation->id);

        $this->assertSame(ReservationStatusEnum::NoShow, $result->status);
    }

    public function test_no_show_fails_from_tentative(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->expectException(ValidationException::class);
        $service->noShow($reservation->id);
    }

    // ── Assign room ───────────────────────────────────────────────────────────

    public function test_assign_room_sets_assigned_room_id(): void
    {
        ['property' => $property] = $this->boot();
        $service     = app(ReservationService::class);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $updated = $service->assignRoom($reservation->id, $room->id);

        $this->assertSame($room->id, $updated->assigned_room_id);
        $this->assertDatabaseHas('reservations', [
            'id'               => $reservation->id,
            'assigned_room_id' => $room->id,
        ]);
    }

    // ── Availability ──────────────────────────────────────────────────────────

    public function test_availability_counts_total_rooms_when_none_reserved(): void
    {
        ['property' => $property] = $this->boot();
        $avail = app(AvailabilityService::class);

        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Deluxe->value]);

        $count = $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(2, $count);
    }

    public function test_availability_deducts_tentative_and_confirmed_reservations(): void
    {
        ['property' => $property] = $this->boot();
        $avail = app(AvailabilityService::class);
        $g1    = $this->makePmsGuest($property);
        $g2    = $this->makePmsGuest($property);

        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $this->makePmsReservation($property, $g1, ['status' => ReservationStatusEnum::Tentative->value, 'reserved_room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsReservation($property, $g2, ['status' => ReservationStatusEnum::Confirmed->value, 'reserved_room_type' => RoomTypeEnum::Standard->value]);

        $count = $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(1, $count);
    }

    public function test_availability_deducts_active_room_blocks(): void
    {
        ['property' => $property] = $this->boot();
        $avail = app(AvailabilityService::class);
        $room  = $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        RoomBlock::create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'start_at'    => today()->addDay(),
            'end_at'      => today()->addDays(4),
        ]);

        $count = $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(1, $count);
    }

    public function test_availability_excludes_reservation_being_confirmed(): void
    {
        ['property' => $property] = $this->boot();
        $avail = app(AvailabilityService::class);
        $guest = $this->makePmsGuest($property);

        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);

        $reservation = $this->makePmsReservation($property, $guest, [
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        // Without exclusion: 0 available (1 room - 1 tentative)
        $this->assertSame(0, $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ));

        // With self-exclusion during confirm: 1 available
        $this->assertSame(1, $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
            $reservation->id,
        ));
    }

    public function test_checked_out_reservation_not_counted_as_active(): void
    {
        ['property' => $property] = $this->boot();
        $avail = app(AvailabilityService::class);
        $guest = $this->makePmsGuest($property);

        $this->makePmsRoom($property, ['room_type' => RoomTypeEnum::Standard->value]);
        $this->makePmsReservation($property, $guest, [
            'status'             => ReservationStatusEnum::CheckedOut->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ]);

        // Checked-out is not an active status — room should be available again
        $count = $avail->availableCount(
            RoomTypeEnum::Standard->value,
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        );

        $this->assertSame(1, $count);
    }

    // ── Policy & cross-property ────────────────────────────────────────────────

    public function test_cross_property_policy_denies_reservation_management(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-PB-X']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view',         $reservation)->denied());
        $this->assertTrue(Gate::inspect('update',       $reservation)->denied());
        $this->assertTrue(Gate::inspect('delete',       $reservation)->denied());
        $this->assertTrue(Gate::inspect('checkIn',      $reservation)->denied());
        $this->assertTrue(Gate::inspect('changeStatus', $reservation)->denied());
    }

    public function test_staff_cannot_create_reservations(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', Reservation::class)->denied());
    }
}
