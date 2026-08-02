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

    public function conduct(string $id, User|string|null $actorReference = null): RoomInspection
    {
        return $this->lifecycle->conductInspection($this->actor($actorReference), $id);
    }

    public function pass(
        string $id,
        ?string $remarks = null,
        ?InspectionSeverityEnum $severity = null,
        User|string|null $actorReference = null,
    ): RoomInspection {
        return $this->lifecycle->passInspection(
            $this->actor($actorReference),
            $id,
            (string) $remarks,
            $severity,
        );
    }

    public function fail(
        string $id,
        ?string $remarks = null,
        ?InspectionSeverityEnum $severity = null,
        User|string|null $actorReference = null,
    ): RoomInspection {
        return $this->lifecycle->failInspection(
            $this->actor($actorReference),
            $id,
            (string) $remarks,
            $severity,
        );
    }

    private function actor(User|string|null $actorReference = null): User
    {
        $actor = $actorReference instanceof User ? $actorReference : auth()->user();
        if (! $actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED');
        }
        if (is_string($actorReference) && $actorReference !== $actor->id) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED');
        }

        return $actor;
    }
}
