<?php

namespace Modules\Operations\WorkOrder\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\DTOs\WorkOrderDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderService
{
    public function __construct(
        protected WorkOrderNumberGeneratorService $numberGenerator,
        protected WorkOrderHistoryService $historyService,
        protected WorkOrderPriorityScoreService $priorityScoreService
    ) {}

    public function create(WorkOrderDTO $dto, string $userId): WorkOrder
    {
        return DB::transaction(function () use ($dto, $userId) {
            $number = $this->numberGenerator->generate($dto->propertyId);
            $score = $this->priorityScoreService->calculate($dto);

            $status = $dto->priority === \Modules\Operations\WorkOrder\Enums\WorkOrderPriorityEnum::Emergency 
                ? WorkOrderStatusEnum::Open 
                : WorkOrderStatusEnum::Draft;

            $wo = WorkOrder::create([
                'property_id' => $dto->propertyId,
                'wo_number' => $number,
                'asset_id' => $dto->assetId,
                'title' => $dto->title,
                'description' => $dto->description,
                'status' => $status,
                'priority' => $dto->priority,
                'type' => $dto->type,
                'source_type' => $dto->sourceType,
                'source_id' => $dto->sourceId,
                'has_guest_impact' => $dto->hasGuestImpact,
                'priority_score' => $score,
                'target_resolution_at' => $dto->targetResolutionAt,
                'created_by' => $userId,
            ]);

         

            $this->historyService->log($wo->id, $userId, 'created');

            event(new \Modules\Operations\WorkOrder\Events\WorkOrderCreated($wo));

            return $wo;
        });
    }

    public function updateStatus(WorkOrder $wo, WorkOrderStatusEnum $newStatus, string $userId): WorkOrder
    {
        if ($wo->status === WorkOrderStatusEnum::Closed) {
            throw new \Exception("Cannot update a closed Work Order");
        }

        $oldStatus = $wo->status;
        $workOrderId = $wo->id;

        $wo->update([
            'status' => $newStatus,
            'updated_by' => $userId
        ]);

        $oldValue = $oldStatus instanceof \BackedEnum ? $oldStatus->value : (string) $oldStatus;
         

        $this->historyService->log($workOrderId, $userId, 'status_changed', 'status', $oldValue, $newStatus->value);

        return $wo;
    }
}
