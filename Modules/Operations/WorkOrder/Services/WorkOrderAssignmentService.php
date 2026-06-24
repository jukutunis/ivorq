<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderAssignment;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\DTOs\WorkOrderAssignmentDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

use Illuminate\Support\Facades\DB;

class WorkOrderAssignmentService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function assign(WorkOrderAssignmentDTO $dto, string $actorId): WorkOrderAssignment
    {
        return DB::transaction(function () use ($dto, $actorId) {
            $wo = WorkOrder::findOrFail($dto->workOrderId);

            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($wo->property_id !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

            if ($wo->status !== WorkOrderStatusEnum::Draft && $wo->status !== WorkOrderStatusEnum::Open) {
                throw new \Exception("Work Order must be in Draft or Open status to be assigned.");
            }

            if ($dto->userId) {
                $tech = \Modules\Foundation\User\Models\User::findOrFail($dto->userId);
                if (!$tech->properties()->where('properties.id', $wo->property_id)->exists()) {
                    throw new \Exception("Technician must belong to the same property context.");
                }
            }

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

            $wo->update(['status' => WorkOrderStatusEnum::Assigned]);

            $this->historyService->log($wo->id, $actorId, 'assigned', 'user_id', null, $dto->userId);

            event(new \Modules\Operations\WorkOrder\Events\WorkOrderAssigned($wo, $assignment));

            return $assignment;
        });
    }
}
