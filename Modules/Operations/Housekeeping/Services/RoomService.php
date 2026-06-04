<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Repositories\RoomRepository;

class RoomService
{
    public function __construct(
        private RoomRepository $roomRepository
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->roomRepository->paginate($perPage);
    }

    public function find(string $id): Room
    {
        return $this->roomRepository->find($id);
    }

    public function create(array $data): Room
    {
        $room = $this->roomRepository->create($data);

        event(new RoomCreated($room));

        return $room;
    }

    /**
     * Update room fields. Status dimensions are not allowed here —
     * use changeCleanlinessStatus() or changeOccupancyStatus() instead.
     * Both cleanliness_status and occupancy_status keys are stripped before persisting.
     */
    public function update(string $id, array $data): Room
    {
        unset($data['cleanliness_status'], $data['occupancy_status']);

        return $this->roomRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->roomRepository->delete($id);
    }

    public function assignToZone(string $roomId, string $zoneId): Room
    {
        return $this->roomRepository->update($roomId, ['zone_id' => $zoneId]);
    }

    /**
     * Transition the room's cleanliness dimension to a new status.
     *
     * Validates the transition against RoomCleanlinessStatusEnum::canTransitionTo().
     * Throws ValidationException for any invalid transition.
     * Fires RoomStatusChanged(statusField: 'cleanliness').
     */
    public function changeCleanlinessStatus(
        string                    $id,
        RoomCleanlinessStatusEnum $new,
        ?string                   $remarks = null
    ): Room {
        $room = $this->roomRepository->findOrFail($id);
        $from = $room->cleanliness_status;

        if (! $from->canTransitionTo($new)) {
            throw ValidationException::withMessages([
                'cleanliness_status' => [
                    "Cannot transition room cleanliness from {$from->label()} to {$new->label()}.",
                ],
            ]);
        }

        $room->update(['cleanliness_status' => $new]);

        event(new RoomStatusChanged($room->fresh(), 'cleanliness', $from->value, $new->value, $remarks));

        return $room->fresh();
    }

    /**
     * Transition the room's occupancy dimension to a new status.
     *
     * Accepts a nullable current occupancy (null = untracked, PMS not yet active).
     * Validates via RoomOccupancyStatusEnum::isValidTransition().
     * Fires RoomStatusChanged(statusField: 'occupancy').
     */
    public function changeOccupancyStatus(
        string                   $id,
        RoomOccupancyStatusEnum  $new,
        ?string                  $remarks = null
    ): Room {
        $room = $this->roomRepository->findOrFail($id);
        $from = $room->occupancy_status; // ?RoomOccupancyStatusEnum

        if (! RoomOccupancyStatusEnum::isValidTransition($from, $new)) {
            $fromLabel = $from?->label() ?? 'unset';
            throw ValidationException::withMessages([
                'occupancy_status' => [
                    "Cannot transition room occupancy from {$fromLabel} to {$new->label()}.",
                ],
            ]);
        }

        $room->update(['occupancy_status' => $new]);

        event(new RoomStatusChanged($room->fresh(), 'occupancy', $from?->value, $new->value, $remarks));

        return $room->fresh();
    }
}
