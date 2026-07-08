<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;

class EngineeringAvailabilityDependencyService
{
    public function __construct(private readonly EngineeringRoomAvailabilityProjectionService $projectionService) {}

    /**
     * @return array<string, mixed>
     */
    public function roomAvailability(User $actor, string $roomId): array
    {
        return $this->projectionService->forFrontDesk($actor, $roomId);
    }
}
