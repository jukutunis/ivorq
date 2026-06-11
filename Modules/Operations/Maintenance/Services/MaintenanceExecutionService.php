<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenanceExecution;
use Modules\Operations\Maintenance\DTOs\MaintenanceExecutionDTO;
use Modules\Operations\Maintenance\Events\MaintenanceExecutionGenerated;
use Modules\Operations\Maintenance\Events\MaintenanceExecutionCompleted;

class MaintenanceExecutionService
{
    public function generateExecution(MaintenanceExecutionDTO $dto): MaintenanceExecution
    {
        $execution = MaintenanceExecution::create([
            'property_id' => $dto->property_id,
            'maintenance_plan_id' => $dto->maintenance_plan_id,
            'asset_id' => $dto->asset_id,
            'status' => $dto->status,
            'scheduled_date' => $dto->scheduled_date,
        ]);

        MaintenanceExecutionGenerated::dispatch($execution);

        return $execution;
    }

    public function completeExecution(MaintenanceExecution $execution, array $checklist_snapshot, string $executed_by, ?string $notes = null): MaintenanceExecution
    {
        $execution->update([
            'status' => 'Completed',
            'executed_date' => now(),
            'executed_by' => $executed_by,
            'checklist_snapshot' => $checklist_snapshot,
            'notes' => $notes,
        ]);

        MaintenanceExecutionCompleted::dispatch($execution);

        return $execution;
    }
}
