<?php

namespace Modules\Operations\Zoning\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Zoning\Enums\ZoneAssignmentStatusEnum;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Events\ZoneAssigned;
use Modules\Operations\Zoning\Events\ZoneAssignmentEnded;
use Modules\Operations\Zoning\Events\ZoneReassigned;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Modules\Operations\Zoning\Repositories\ZoneAssignmentRepository;
use Modules\Operations\Zoning\Repositories\ZoneRepository;

class ZoneAssignmentService
{
    public function __construct(
        private ZoneAssignmentRepository $repo,
        private ZoneRepository           $zoneRepository,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repo->paginate($perPage);
    }

    public function find(string $id): ZoneAssignment
    {
        return $this->repo->find($id);
    }

    /**
     * Assign an employee to a zone.
     *
     * Preconditions (both throw ValidationException on failure):
     *   1. The zone must be in Active status.
     *   2. No active assignment for the same zone + user overlaps the proposed dates.
     */
    public function assign(array $data): ZoneAssignment
    {
        $zone = $this->zoneRepository->findOrFail($data['zone_id']);

        if ($zone->status !== ZoneStatusEnum::Active) {
            throw ValidationException::withMessages([
                'zone_id' => ['Zone must be active to accept assignments.'],
            ]);
        }

        $startDate = \Illuminate\Support\Carbon::parse($data['start_date']);
        $endDate   = isset($data['end_date']) ? \Illuminate\Support\Carbon::parse($data['end_date']) : null;

        if ($this->repo->hasOverlap($data['zone_id'], $data['user_id'], $startDate, $endDate)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'This employee already has an active assignment in this zone that overlaps with the requested dates.',
                ],
            ]);
        }

        $assignment = $this->repo->create($data);

        event(new ZoneAssigned($assignment));

        return $assignment;
    }

    /**
     * Update an existing assignment's dates or other fields.
     *
     * Re-runs the overlap check, excluding the assignment being updated so it
     * does not conflict with itself.
     */
    public function update(string $id, array $data): ZoneAssignment
    {
        $existing  = $this->repo->find($id);
        $startDate = \Illuminate\Support\Carbon::parse($data['start_date'] ?? $existing->start_date);
        $endDate   = isset($data['end_date'])
            ? \Illuminate\Support\Carbon::parse($data['end_date'])
            : $existing->end_date;

        if ($this->repo->hasOverlap($existing->zone_id, $existing->user_id, $startDate, $endDate, $id)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'This employee already has an active assignment in this zone that overlaps with the requested dates.',
                ],
            ]);
        }

        return $this->repo->update($id, $data);
    }

    /**
     * End an active assignment: sets status = inactive and end_date = today.
     */
    public function end(string $id): ZoneAssignment
    {
        $assignment = $this->repo->find($id);

        $assignment->update([
            'status'   => ZoneAssignmentStatusEnum::Inactive,
            'end_date' => now()->toDateString(),
        ]);

        $fresh = $assignment->fresh();

        event(new ZoneAssignmentEnded($fresh));

        return $fresh;
    }

    /**
     * Reassign an employee: ends the old assignment and creates a new one.
     *
     * Fires ZoneAssigned (from inside assign()) and ZoneReassigned.
     * Does not fire ZoneAssignmentEnded — the old record is silently closed.
     */
    public function reassign(string $oldId, array $newData): ZoneAssignment
    {
        $old = $this->repo->find($oldId);

        $old->update([
            'status'   => ZoneAssignmentStatusEnum::Inactive,
            'end_date' => now()->toDateString(),
        ]);

        $new = $this->assign($newData);

        event(new ZoneReassigned($old->fresh(), $new));

        return $new;
    }
}
