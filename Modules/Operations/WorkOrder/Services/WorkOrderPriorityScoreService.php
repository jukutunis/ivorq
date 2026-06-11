<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\DTOs\WorkOrderDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderPriorityEnum;

class WorkOrderPriorityScoreService
{
    public function calculate(WorkOrderDTO $dto): int
    {
        $score = 0;

        // Base score by priority
        $score += match($dto->priority) {
            WorkOrderPriorityEnum::Emergency => 100,
            WorkOrderPriorityEnum::High => 50,
            WorkOrderPriorityEnum::Medium => 20,
            WorkOrderPriorityEnum::Low => 5,
        };

        // Guest Impact bonus
        if ($dto->hasGuestImpact) {
            $score += 50;
        }

        return $score;
    }
}
