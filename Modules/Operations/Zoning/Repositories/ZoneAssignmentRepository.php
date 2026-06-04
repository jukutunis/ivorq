<?php

namespace Modules\Operations\Zoning\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Operations\Zoning\Enums\ZoneAssignmentStatusEnum;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Shared\Exceptions\NotFoundException;

class ZoneAssignmentRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return ZoneAssignment::with(['zone', 'user', 'department'])->latest()->paginate($perPage);
    }

    public function find(string $id): ZoneAssignment
    {
        $assignment = ZoneAssignment::with(['zone', 'user', 'department'])->find($id);

        throw_if(! $assignment, new NotFoundException('ZoneAssignment'));

        return $assignment;
    }

    public function create(array $data): ZoneAssignment
    {
        return ZoneAssignment::create($data)->fresh();
    }

    public function update(string $id, array $data): ZoneAssignment
    {
        $assignment = $this->find($id);
        $assignment->update($data);

        return $assignment->fresh();
    }

    public function delete(string $id): bool
    {
        $assignment = $this->find($id);

        return $assignment->delete();
    }

    public function activeForZone(string $zoneId): Collection
    {
        return ZoneAssignment::where('zone_id', $zoneId)
            ->where('status', ZoneAssignmentStatusEnum::Active)
            ->with(['user', 'department'])
            ->get();
    }

    public function activeForUser(string $userId): Collection
    {
        return ZoneAssignment::where('user_id', $userId)
            ->where('status', ZoneAssignmentStatusEnum::Active)
            ->with(['zone'])
            ->get();
    }

    /**
     * Returns true if an active assignment already exists for the same zone + user
     * with a date range that overlaps the proposed range.
     *
     * Overlap condition (A = existing, B = proposed):
     *   A.start_date <= B.end_date (or B is open-ended)
     *   AND (A.end_date IS NULL OR A.end_date >= B.start_date)
     *
     * $excludeId skips one record — used when updating an existing assignment so
     * the record being updated does not count as its own overlap.
     */
    public function hasOverlap(
        string  $zoneId,
        string  $userId,
        Carbon  $startDate,
        ?Carbon $endDate,
        ?string $excludeId = null
    ): bool {
        return ZoneAssignment::where('zone_id', $zoneId)
            ->where('user_id', $userId)
            ->where('status', ZoneAssignmentStatusEnum::Active)
            ->when(
                // If proposed range has an end date, existing must start on or before it.
                // If proposed range is open-ended, any existing start date overlaps.
                $endDate !== null,
                fn($q) => $q->where('start_date', '<=', $endDate->toDateString())
            )
            ->where(function ($q) use ($startDate) {
                // Existing is open-ended (always overlaps) OR ends on/after proposed start.
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate->toDateString());
            })
            ->when($excludeId !== null, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
