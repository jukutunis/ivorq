<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;

class InspectionService
{
    public function __construct(
        private InspectionRepository $inspectionRepository,
        private HousekeepingCleaningInspectionReadinessLifecycleService $lifecycle,
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

    public function conduct(string $id): RoomInspection
    {
        return $this->lifecycle->conductInspection($this->actor(), $id);
    }

    public function pass(
        string $id,
        ?string $remarks = null,
        ?InspectionSeverityEnum $severity = null,
        ?string $supervisorId = null,
    ): RoomInspection {
        return $this->lifecycle->passInspection(
            $this->actor($supervisorId),
            $id,
            (string) $remarks,
            $severity,
        );
    }

    public function fail(
        string $id,
        ?string $remarks = null,
        ?InspectionSeverityEnum $severity = null,
        ?string $supervisorId = null,
    ): RoomInspection {
        return $this->lifecycle->failInspection(
            $this->actor($supervisorId),
            $id,
            (string) $remarks,
            $severity,
        );
    }

    private function actor(?string $userId = null): User
    {
        $actor = $userId ? User::withoutGlobalScopes()->find($userId) : auth()->user();
        if (! $actor instanceof User) {
            throw new \DomainException('An authenticated Housekeeping actor is required.');
        }

        return $actor;
    }
}
