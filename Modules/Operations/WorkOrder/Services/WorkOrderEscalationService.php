<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderEscalation;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderEscalationService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function escalate(WorkOrder $wo, string $reason, string $userId, ?string $departmentId = null): WorkOrderEscalation
    {
        $escalation = WorkOrderEscalation::create([
            'work_order_id' => $wo->id,
            'escalated_to_department_id' => $departmentId,
            'reason' => $reason,
            'created_by' => $userId,
        ]);

        $this->historyService->log($wo->id, $userId, 'escalated', null, null, null, "Reason: {$reason}");

        event(new \Modules\Operations\WorkOrder\Events\WorkOrderEscalated($wo, $escalation));

        return $escalation;
    }
}
