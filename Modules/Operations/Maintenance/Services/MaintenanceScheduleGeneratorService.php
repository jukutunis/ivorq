<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\Maintenance\DTOs\MaintenanceExecutionDTO;
use Carbon\Carbon;

class MaintenanceScheduleGeneratorService
{
    public function __construct(protected MaintenanceExecutionService $executionService) {}

    public function generateForRollingWindow(int $daysAhead = 30): void
    {
        $windowEnd = now()->addDays($daysAhead);

        // Very basic implementation: Find plans due within window
        $plans = MaintenancePlan::where('status', 'Active')
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $windowEnd)
            ->get();

        foreach ($plans as $plan) {
            $dto = new MaintenanceExecutionDTO(
                property_id: $plan->property_id,
                maintenance_plan_id: $plan->id,
                asset_id: $plan->asset_id,
                status: 'Pending',
                scheduled_date: Carbon::parse($plan->next_due_date)->toDateString()
            );

            $this->executionService->generateExecution($dto);

            // Update plan next due date (e.g. +1 month)
            if ($plan->frequency === 'Monthly') {
                $plan->update(['next_due_date' => Carbon::parse($plan->next_due_date)->addMonth()]);
            }
        }
    }
}
