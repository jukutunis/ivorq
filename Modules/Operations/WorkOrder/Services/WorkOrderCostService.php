<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderCostService
{
    public function aggregateCosts(WorkOrder $wo): array
    {
        $laborCost = $wo->labors()->sum('total_cost');
        $materialCost = $wo->materials()->sum('total_cost');
        $totalCost = $laborCost + $materialCost;

        return [
            'labor_cost' => $laborCost,
            'material_cost' => $materialCost,
            'total_cost' => $totalCost,
        ];
    }
}
