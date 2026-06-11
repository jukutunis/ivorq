<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderClosure;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\DTOs\WorkOrderClosureDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderClosureService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function close(WorkOrderClosureDTO $dto, string $userId): WorkOrderClosure
    {
        $wo = WorkOrder::findOrFail($dto->workOrderId);

        if ($wo->status === WorkOrderStatusEnum::Closed) {
            throw new \Exception("Work Order is already closed.");
        }

        $closure = WorkOrderClosure::create([
            'work_order_id' => $wo->id,
            'closed_by_user_id' => $userId,
            'closed_at' => now(),
            'resolution_notes' => $dto->resolutionNotes,
            'root_cause' => $dto->rootCause,
            'has_signature' => $dto->hasSignature,
            'snapshot_data' => $wo->toArray(), // Immutable snapshot
            'created_by' => $userId,
        ]);

        $wo->update(['status' => WorkOrderStatusEnum::Closed]);

        $this->historyService->log($wo->id, $userId, 'closed');

        event(new \Modules\Operations\WorkOrder\Events\WorkOrderClosed($wo));

        return $closure;
    }
}
