<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Events\ReservationCancelled;
use Modules\Operations\PMS\Events\ReservationConfirmed;
use Modules\Operations\PMS\Events\ReservationCreated;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Repositories\ReservationRepository;

class ReservationService
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private AvailabilityService   $availabilityService,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservationRepository->paginate($filters, $perPage);
    }

    public function find(string $id): Reservation
    {
        return $this->reservationRepository->find($id);
    }

    public function create(array $data): Reservation
    {
        $reservation = $this->reservationRepository->create($data);

        event(new ReservationCreated($reservation));

        return $reservation;
    }

    /**
     * Update reservation fields. Status changes must use confirm/cancel/noShow.
     * Any 'status' key in $data is stripped.
     */
    public function update(string $id, array $data): Reservation
    {
        unset($data['status']);

        return $this->reservationRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->reservationRepository->delete($id);
    }

    public function confirm(string $id): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($id);

        if (! $reservation->status->canTransitionTo(ReservationStatusEnum::Confirmed)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot confirm a reservation in {$reservation->status->label()} status."],
            ]);
        }

        $this->validateAvailabilityBeforeConfirm($reservation);

        $reservation->update(['status' => ReservationStatusEnum::Confirmed]);
        $reservation = $reservation->fresh();

        event(new ReservationConfirmed($reservation));

        return $reservation;
    }

    public function cancel(string $id, ?string $reason = null): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($id);

        if (! $reservation->status->canTransitionTo(ReservationStatusEnum::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel a reservation in {$reservation->status->label()} status."],
            ]);
        }

        $reservation->update(['status' => ReservationStatusEnum::Cancelled]);
        $reservation = $reservation->fresh();

        event(new ReservationCancelled($reservation, $reason));

        return $reservation;
    }

    public function noShow(string $id): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($id);

        if (! $reservation->status->canTransitionTo(ReservationStatusEnum::NoShow)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot mark a reservation as no-show in {$reservation->status->label()} status."],
            ]);
        }

        $reservation->update(['status' => ReservationStatusEnum::NoShow]);

        return $reservation->fresh();
    }

    public function assignRoom(string $id, string $roomId): Reservation
    {
        $room        = Room::findOrFail($roomId);
        $reservation = $this->reservationRepository->findOrFail($id);

        if (
            $reservation->reserved_room_type !== null &&
            $room->room_type !== $reservation->reserved_room_type
        ) {
            throw ValidationException::withMessages([
                'room_id' => [
                    sprintf(
                        'Room type "%s" does not match the reserved room type "%s".',
                        $room->room_type->label(),
                        $reservation->reserved_room_type->label(),
                    ),
                ],
            ]);
        }

        return $this->reservationRepository->update($id, [
            'assigned_room_id' => $roomId,
        ]);
    }

    /**
     * Validate that at least one room of the reserved type is available
     * for the reservation's dates. Excludes the reservation itself from the
     * active-reservation count (it is tentative, so it already occupies a slot;
     * we don't want to penalise it twice).
     */
    public function validateAvailabilityBeforeConfirm(Reservation $reservation): void
    {
        if ($reservation->reserved_room_type === null) {
            return;
        }

        $available = $this->availabilityService->isAvailable(
            $reservation->reserved_room_type->value,
            $reservation->arrival_date->toDateString(),
            $reservation->departure_date->toDateString(),
            $reservation->id,
        );

        if (! $available) {
            throw ValidationException::withMessages([
                'availability' => ['No rooms available for the requested type and dates.'],
            ]);
        }
    }
}
