<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingInspectionClaimResult;

class InspectionService
{
    public function __construct(
        private InspectionRepository $inspectionRepository,
        private HousekeepingCleaningInspectionReadinessLifecycleService $lifecycle,
        private HousekeepingInspectionClaimService $claimService,
    ) {}

    /** @param array{inspection_type?: string, status?: string} $filters */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->inspectionRepository->paginate($perPage, $filters);
    }

    public function find(string $id): RoomInspection
    {
        return $this->inspectionRepository->find($id);
    }

    public function create(array $data): RoomInspection
    {
        $type = $data['inspection_type'] ?? null;
        $type = $type instanceof \BackedEnum ? $type->value : (string) $type;
        if ($type === 'post_cleaning') {
            throw new \DomainException('Post-cleaning Inspections are created only by the canonical cleaning-completion lifecycle.');
        }

        return $this->inspectionRepository->create($data);
    }

    public function claim(
        string $id,
        string $idempotencyKey,
        User|string|null $actorReference = null,
    ): HousekeepingInspectionClaimResult
    {
        return $this->claimService->claim($this->actor($actorReference), $id, $idempotencyKey);
    }

    public function conduct(string $id, User|string|null $actorReference = null): RoomInspection
    {
        $actor = $this->actor($actorReference);

        return $this->claimService->claim(
            $actor,
            $id,
            'p17-compat:' . $id . ':' . $actor->id,
        )->inspection;
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
