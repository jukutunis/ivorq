<?php

namespace Modules\Operations\PMS\Services;

use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;

class AvailabilityService
{
    /**
     * Count rooms of a given type available for the requested date range.
     *
     * Formula:
     *   total active rooms of type
     *   minus active reservations (tentative + confirmed + checked_in) overlapping the period
     *   minus distinct rooms blocked by active room blocks overlapping the period
     *
     * Pass $excludeReservationId to exclude the reservation itself when confirming
     * (the reservation is already counted as tentative, so we ignore it to avoid
     * penalising the slot twice).
     */
    public function availableCount(
        string  $roomType,
        string  $arrivalDate,
        string  $departureDate,
        ?string $excludeReservationId = null
    ): int {
        $totalRooms = Room::where('room_type', $roomType)
            ->where('is_active', true)
            ->count();

        $activeReservationCount = Reservation::where('reserved_room_type', $roomType)
            ->whereIn('status', [
                ReservationStatusEnum::Tentative->value,
                ReservationStatusEnum::Confirmed->value,
                ReservationStatusEnum::CheckedIn->value,
            ])
            ->where('arrival_date', '<', $departureDate)
            ->where('departure_date', '>', $arrivalDate)
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->count();

        $roomIdsOfType = Room::where('room_type', $roomType)
            ->where('is_active', true)
            ->pluck('id');

        $blockedRoomCount = RoomBlock::where('status', RoomBlockStatusEnum::Active)
            ->where('start_at', '<', $departureDate)
            ->where(function ($q) use ($arrivalDate) {
                $q->whereNull('end_at')->orWhere('end_at', '>', $arrivalDate);
            })
            ->whereIn('room_id', $roomIdsOfType)
            ->distinct()
            ->count('room_id');

        return max(0, $totalRooms - $activeReservationCount - $blockedRoomCount);
    }

    public function isAvailable(
        string  $roomType,
        string  $arrivalDate,
        string  $departureDate,
        ?string $excludeReservationId = null
    ): bool {
        return $this->availableCount($roomType, $arrivalDate, $departureDate, $excludeReservationId) > 0;
    }
}
