<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;

class HousekeepingReadinessDependencyService
{
    public function __construct(private readonly HousekeepingRoomReadinessProjectionService $projectionService) {}

    /**
     * @return array<string, mixed>
     */
    public function roomReadiness(User $actor, string $roomId): array
    {
        return $this->projectionService->forFrontDesk($actor, $roomId);
    }
}
