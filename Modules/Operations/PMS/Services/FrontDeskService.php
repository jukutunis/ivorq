<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Events\GuestCheckedIn;
use Modules\Operations\PMS\Events\GuestCheckedOut;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Repositories\ReservationRepository;
use Modules\Operations\PMS\Repositories\RoomBlockRepository;
use Modules\Operations\PMS\Repositories\StayRepository;

class FrontDeskService
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private StayRepository        $stayRepository,
        private RoomBlockRepository   $roomBlockRepository,
    ) {}

    /**
     * Check in a guest.
     *
     * Guards (in order):
     *   1. Reservation must be confirmed.
     *   2. An assigned room must exist on the reservation.
     *   3. Room cleanliness must be clean or inspected.
     *   4. Room occupancy must be vacant or null (PMS not yet active).
     *   5. No active room block on the room.
     *   6. No active stay for the room.
     *
     * Side effects:
     *   - Creates a Stay record (status: checked_in).
     *   - Transitions the reservation to checked_in.
     *   - Fires GuestCheckedIn (UpdateRoomStatusToOccupied + CreateFolioIfMissing listen).
     */
    public function checkIn(string $reservationId): Stay
    {
        $reservation = $this->reservationRepository->findOrFail($reservationId);

        if ($reservation->status !== ReservationStatusEnum::Confirmed) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation must be confirmed before check-in.'],
            ]);
        }

        $room = $reservation->assignedRoom;

        if (! $room) {
            throw ValidationException::withMessages([
                'room' => ['No room has been assigned to this reservation.'],
            ]);
        }

        if (! in_array($room->cleanliness_status, [
            RoomCleanlinessStatusEnum::Clean,
            RoomCleanlinessStatusEnum::Inspected,
        ], strict: true)) {
            throw ValidationException::withMessages([
                'room' => ['Room must be clean or inspected before check-in.'],
            ]);
        }

        if ($room->occupancy_status !== null && $room->occupancy_status !== RoomOccupancyStatusEnum::Vacant) {
            throw ValidationException::withMessages([
                'room' => ['Room is not vacant. Cannot check in.'],
            ]);
        }

        if ($this->roomBlockRepository->activeForRoom($room->id)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'room' => ['Room has an active block. Cannot check in.'],
            ]);
        }

        if ($this->stayRepository->activeForRoom($room->id) !== null) {
            throw ValidationException::withMessages([
                'room' => ['Room already has an active stay. Cannot check in.'],
            ]);
        }

        $stay = $this->stayRepository->create([
            'property_id'           => $reservation->property_id,
            'reservation_id'        => $reservation->id,
            'room_id'               => $room->id,
            'guest_id'              => $reservation->primary_guest_id,
            'status'                => StayStatusEnum::CheckedIn,
            'check_in_at'           => now(),
            'expected_departure_at' => $reservation->departure_date->startOfDay(),
        ]);

        $reservation->update(['status' => ReservationStatusEnum::CheckedIn]);
        $reservation = $reservation->fresh();

        event(new GuestCheckedIn($reservation, $stay));

        return $stay;
    }

    /**
     * Check out a guest.
     *
     * Guards:
     *   1. Stay must be checked_in.
     *
     * Side effects:
     *   - Sets stay status to checked_out with check_out_at timestamp.
     *   - Transitions the reservation to checked_out.
     *   - Fires GuestCheckedOut (UpdateRoomStatusToDirty listens).
     */
    public function checkOut(string $stayId): Stay
    {
        $stay = $this->stayRepository->findOrFail($stayId);

        if ($stay->status !== StayStatusEnum::CheckedIn) {
            throw ValidationException::withMessages([
                'stay' => ['Stay must be in checked_in status to perform check-out.'],
            ]);
        }

        $stay->update([
            'status'       => StayStatusEnum::CheckedOut,
            'check_out_at' => now(),
        ]);

        $stay = $stay->fresh();

        $reservation = $this->reservationRepository->findOrFail($stay->reservation_id);
        $reservation->update(['status' => ReservationStatusEnum::CheckedOut]);
        $reservation = $reservation->fresh();

        event(new GuestCheckedOut($reservation, $stay));

        return $stay;
    }
}
