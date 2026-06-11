<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderApproval;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderApprovalService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function requestApproval(WorkOrder $wo, string $approverId, string $mode, string $userId): WorkOrderApproval
    {
        $wo->update(['status' => WorkOrderStatusEnum::PendingApproval]);

        $approval = WorkOrderApproval::create([
            'work_order_id' => $wo->id,
            'approver_id' => $approverId,
            'status' => 'pending',
            'mode' => $mode,
            'created_by' => $userId,
        ]);

        $this->historyService->log($wo->id, $userId, 'approval_requested', null, null, null, "Approval requested from {$approverId}");

        event(new \Modules\Operations\WorkOrder\Events\WorkOrderApprovalRequested($wo, $approval));

        return $approval;
    }

    public function grantApproval(WorkOrderApproval $approval, string $userId, ?string $comments = null): void
    {
        $approval->update([
            'status' => 'approved',
            'approved_at' => now(),
            'comments' => $comments,
            'updated_by' => $userId,
        ]);

        $wo = $approval->workOrder;
        
        // In a real implementation, check if parallel/linear approvals are fully met.
        // For MVP, we assume a single approval fulfills it.
        $wo->update(['status' => WorkOrderStatusEnum::Open]);

        $this->historyService->log($wo->id, $userId, 'approval_granted');

        event(new \Modules\Operations\WorkOrder\Events\WorkOrderApprovalGranted($wo, $approval));
    }
}
