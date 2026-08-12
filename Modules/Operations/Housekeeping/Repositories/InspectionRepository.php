<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;

class InspectionRepository
{
    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    /** @param array{inspection_type?: string, status?: string} $filters */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = RoomInspection::with(['room', 'task.completedBy', 'inspector'])
            ->where('property_id', $this->propertyId());
        if (($filters['inspection_type'] ?? '') !== '') {
            $query->where('inspection_type', $filters['inspection_type']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        return $query
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): RoomInspection
    {
        $inspection = RoomInspection::with(['room', 'task.completedBy', 'inspector', 'photos'])
            ->where('property_id', $this->propertyId())
            ->find($id);

        throw_if(! $inspection, new NotFoundException('RoomInspection'));

        return $inspection;
    }

    public function create(array $data): RoomInspection
    {
        $type = $data['inspection_type'] ?? null;
        $type = $type instanceof \BackedEnum ? $type->value : (string) $type;
        if ($type === 'post_cleaning') {
            throw new \DomainException('Post-cleaning Inspections are created only by the canonical cleaning-completion lifecycle.');
        }

        $data['property_id'] = $this->propertyId();

        return RoomInspection::create($data)->fresh(['room', 'task.completedBy', 'inspector']);
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
            ->where('property_id', $this->propertyId())
            ->with(['room', 'inspector'])
            ->latest()
            ->get();
    }

    public function failedCritical(): Collection
    {
        return RoomInspection::where('status', InspectionStatusEnum::Failed)
            ->where('property_id', $this->propertyId())
            ->where('inspection_severity', InspectionSeverityEnum::Critical)
            ->with(['room', 'task', 'inspector'])
            ->latest()
            ->get();
    }

    public function byRoom(string $roomId): Collection
    {
        return RoomInspection::where('room_id', $roomId)
            ->where('property_id', $this->propertyId())
            ->with(['task', 'inspector', 'photos'])
            ->latest()
            ->get();
    }

    private function propertyId(): string
    {
        return $this->currentProperty->resolveOrFail();
    }
}
