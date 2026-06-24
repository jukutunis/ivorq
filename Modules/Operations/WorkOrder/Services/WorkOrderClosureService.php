<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderClosure;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\DTOs\WorkOrderClosureDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

use Illuminate\Support\Facades\DB;

class WorkOrderClosureService
{
    public function __construct(protected WorkOrderHistoryService $historyService) {}

    public function close(WorkOrderClosureDTO $dto, string $userId): WorkOrderClosure
    {
        return DB::transaction(function () use ($dto, $userId) {
            $wo = WorkOrder::findOrFail($dto->workOrderId);

            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($wo->property_id !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

            if ($wo->status === WorkOrderStatusEnum::Closed) {
                throw new \Exception("Work Order is already closed.");
            }

            if ($wo->status !== WorkOrderStatusEnum::Resolved) {
                throw new \Exception("Work Order must be resolved before it can be closed.");
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
        });
    }
}
