<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class EngineeringDashboardService
{
    public function getDashboard(User $user): array
    {
        return [
            'open_work_orders' => WorkOrder::where('property_id', $user->property_id)
                ->whereNotIn('status', ['Closed', 'Completed', 'Cancelled'])
                ->count(),
            'pm_compliance' => 95, // Mocked for aggregator
            'critical_incidents' => 0,
        ];
    }

    public function getMyTasks(User $user): array
    {
        return [
            'assigned_work_orders' => WorkOrder::where('property_id', $user->property_id)
                ->whereNotIn('status', ['Closed', 'Completed', 'Cancelled'])
                ->limit(5)
                ->get(),
            'assigned_pms' => [],
        ];
    }
}
