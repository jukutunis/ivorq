<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderAssignment;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\DTOs\WorkOrderAssignmentDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderAssignmentService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function assign(WorkOrderAssignmentDTO $dto, string $actorId): WorkOrderAssignment
    {
        $wo = WorkOrder::findOrFail($dto->workOrderId);

        // Previous assignments to reassigned
        WorkOrderAssignment::where('work_order_id', $wo->id)
            ->where('status', 'active')
            ->update(['status' => 'reassigned']);

        $assignment = WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $dto->userId,
            'department_id' => $dto->departmentId,
            'status' => 'active',
            'created_by' => $actorId,
        ]);

        if ($wo->status === WorkOrderStatusEnum::Open || $wo->status === WorkOrderStatusEnum::Draft) {
            $wo->update(['status' => WorkOrderStatusEnum::Assigned]);
        }

        $this->historyService->log($wo->id, $actorId, 'assigned', 'user_id', null, $dto->userId);

        event(new \Modules\Operations\WorkOrder\Events\WorkOrderAssigned($wo, $assignment));

        return $assignment;
    }
}
