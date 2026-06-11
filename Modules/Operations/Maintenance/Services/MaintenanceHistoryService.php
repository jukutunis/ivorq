<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenanceHistory;
use Modules\Operations\Maintenance\Models\MaintenanceExecution;

class MaintenanceHistoryService
{
    public function recordHistory(MaintenanceExecution $execution): MaintenanceHistory
    {
        return MaintenanceHistory::create([
            'property_id' => $execution->property_id,
            'asset_id' => $execution->asset_id,
            'maintenance_plan_id' => $execution->maintenance_plan_id,
            'maintenance_execution_id' => $execution->id,
            'status' => $execution->status,
            'notes' => $execution->notes,
            'executed_by' => $execution->executed_by,
            'executed_date' => $execution->executed_date ?? now(),
        ]);
    }
}
