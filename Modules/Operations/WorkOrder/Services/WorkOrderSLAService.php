<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderSLA;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderSLAService
{
    public function calculateTarget(WorkOrder $wo): WorkOrderSLA
    {
        // Simple mock for target calculation based on priority
        $minutes = match($wo->priority->value) {
            'emergency' => 60,
            'high' => 240,
            'medium' => 1440, // 24 hours
            'low' => 4320, // 72 hours
            default => 1440,
        };

        return WorkOrderSLA::updateOrCreate(
            ['work_order_id' => $wo->id],
            [
                'target_resolution_at' => now()->addMinutes($minutes),
            ]
        );
    }
}
