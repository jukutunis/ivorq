<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderHistory;

class WorkOrderHistoryService
{
    public function log(string $workOrderId, string $userId, string $action, ?string $field = null, ?string $old = null, ?string $new = null, ?string $description = null): WorkOrderHistory
    {
        return WorkOrderHistory::create([
            'work_order_id' => $workOrderId,
            'user_id' => $userId,
            'action' => $action,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }
}
