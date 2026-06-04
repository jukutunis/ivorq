<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Exceptions\NotFoundException;

class InspectionRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return RoomInspection::with(['room', 'inspector'])
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): RoomInspection
    {
        $inspection = RoomInspection::with(['room', 'task', 'inspector', 'photos'])->find($id);

        throw_if(! $inspection, new NotFoundException('RoomInspection'));

        return $inspection;
    }

    public function create(array $data): RoomInspection
    {
        return RoomInspection::create($data)->fresh();
    }

    public function update(string $id, array $data): RoomInspection
    {
        $inspection = $this->find($id);
        $inspection->update($data);

        return $inspection->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function pending(): Collection
    {
        return RoomInspection::where('status', InspectionStatusEnum::Pending)
            ->with(['room', 'inspector'])
            ->latest()
            ->get();
    }

    public function failedCritical(): Collection
    {
        return RoomInspection::where('status', InspectionStatusEnum::Failed)
            ->where('inspection_severity', InspectionSeverityEnum::Critical)
            ->with(['room', 'task', 'inspector'])
            ->latest()
            ->get();
    }

    public function byRoom(string $roomId): Collection
    {
        return RoomInspection::where('room_id', $roomId)
            ->with(['task', 'inspector', 'photos'])
            ->latest()
            ->get();
    }
}
