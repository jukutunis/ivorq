<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Exceptions\NotFoundException;

class RoomRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $query = Room::with('zone')->orderBy('room_number');
        if ($propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId()) {
            $query->where('property_id', $propertyId);
        }
        return $query->paginate($perPage);
    }

    public function find(string $id): Room
    {
        $room = Room::with(['zone', 'statusHistories', 'inspections'])->find($id);

        throw_if(! $room, new NotFoundException('Room'));

        return $room;
    }

    public function findOrFail(string $id): Room
    {
        return Room::findOrFail($id);
    }

    public function create(array $data): Room
    {
        return Room::create($data)->fresh();
    }

    public function update(string $id, array $data): Room
    {
        $room = $this->find($id);
        $room->update($data);

        return $room->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byZone(string $zoneId): Collection
    {
        return Room::where('zone_id', $zoneId)
            ->orderBy('room_number')
            ->get();
    }

    public function byCleanlinessStatus(RoomCleanlinessStatusEnum $status): Collection
    {
        return Room::where('cleanliness_status', $status)
            ->with('zone')
            ->orderBy('room_number')
            ->get();
    }

    public function byOccupancyStatus(RoomOccupancyStatusEnum $status): Collection
    {
        return Room::where('occupancy_status', $status)
            ->with('zone')
            ->orderBy('room_number')
            ->get();
    }

    public function activeRooms(): Collection
    {
        return Room::where('is_active', true)
            ->with('zone')
            ->orderBy('room_number')
            ->get();
    }
}
