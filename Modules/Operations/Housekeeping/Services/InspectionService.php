<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Events\InspectionCompleted;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;

class InspectionService
{
    public function __construct(
        private InspectionRepository $inspectionRepository,
        private RoomService          $roomService,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->inspectionRepository->paginate($perPage);
    }

    public function find(string $id): RoomInspection
    {
        return $this->inspectionRepository->find($id);
    }

    public function create(array $data): RoomInspection
    {
        return $this->inspectionRepository->create($data);
    }

    /**
     * Begin conducting an inspection — transitions status to in_progress.
     */
    public function conduct(string $id): RoomInspection
    {
        return $this->inspectionRepository->update($id, [
            'status' => InspectionStatusEnum::InProgress->value,
        ]);
    }

    /**
     * Mark an inspection as passed.
     *
     * Sets status to passed, records inspected_at, and optionally sets severity.
     * Transitions room cleanliness to inspected via RoomService.
     * Fires InspectionCompleted.
     */
    public function pass(
        string                  $id,
        ?string                 $remarks  = null,
        ?InspectionSeverityEnum $severity = null
    ): RoomInspection {
        $inspection = $this->inspectionRepository->find($id);

        $updated = $this->inspectionRepository->update($id, [
            'status'              => InspectionStatusEnum::Passed->value,
            'inspected_at'        => now(),
            'remarks'             => $remarks,
            'inspection_severity' => $severity?->value,
        ]);

        $this->roomService->changeCleanlinessStatus(
            $inspection->room_id,
            RoomCleanlinessStatusEnum::Inspected,
            'Inspection passed',
        );

        event(new InspectionCompleted($updated));

        return $updated;
    }

    /**
     * Mark an inspection as failed.
     *
     * Sets status to failed, records inspected_at, and optionally sets severity.
     * Transitions room cleanliness back to dirty via RoomService — the room
     * must be recleaned before another inspection attempt.
     * Fires InspectionCompleted.
     */
    public function fail(
        string                  $id,
        ?string                 $remarks  = null,
        ?InspectionSeverityEnum $severity = null
    ): RoomInspection {
        $inspection = $this->inspectionRepository->find($id);

        $updated = $this->inspectionRepository->update($id, [
            'status'              => InspectionStatusEnum::Failed->value,
            'inspected_at'        => now(),
            'remarks'             => $remarks,
            'inspection_severity' => $severity?->value,
        ]);

        $this->roomService->changeCleanlinessStatus(
            $inspection->room_id,
            RoomCleanlinessStatusEnum::Dirty,
            'Inspection failed — room requires recleaning',
        );

        event(new InspectionCompleted($updated));

        return $updated;
    }
}
