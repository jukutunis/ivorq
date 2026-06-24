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
            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($dto->propertyId !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

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

    public function updateStatus(WorkOrder $wo, WorkOrderStatusEnum $newStatus, string $userId, ?string $resolutionNotes = null): WorkOrder
    {
        return DB::transaction(function () use ($wo, $newStatus, $userId, $resolutionNotes) {
            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($wo->property_id !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

            if ($wo->status === WorkOrderStatusEnum::Closed) {
                throw new \Exception("Cannot update a closed Work Order");
            }

            $oldStatus = $wo->status;

            if ($newStatus === WorkOrderStatusEnum::InProgress) {
                if ($oldStatus !== WorkOrderStatusEnum::Assigned) {
                    throw new \Exception("Work Order must be in Assigned status to start work.");
                }

                $hasAssignment = \Modules\Operations\WorkOrder\Models\WorkOrderAssignment::where('work_order_id', $wo->id)
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->exists();
                if (!$hasAssignment) {
                    throw new \Exception("Only the active assigned technician can start this Work Order.");
                }

                $wo->update([
                    'status' => $newStatus,
                    'updated_by' => $userId
                ]);

                $this->historyService->log($wo->id, $userId, 'started', 'status', $oldStatus->value, $newStatus->value);

                event(new \Modules\Operations\WorkOrder\Events\WorkOrderStarted($wo));

            } elseif ($newStatus === WorkOrderStatusEnum::Resolved) {
                if ($oldStatus !== WorkOrderStatusEnum::InProgress) {
                    throw new \Exception("Work Order must be in In Progress status to resolve.");
                }

                $hasAssignment = \Modules\Operations\WorkOrder\Models\WorkOrderAssignment::where('work_order_id', $wo->id)
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->exists();
                if (!$hasAssignment) {
                    throw new \Exception("Only the active assigned technician can resolve this Work Order.");
                }

                if (empty($resolutionNotes) || trim($resolutionNotes) === '') {
                    throw new \Exception("Resolution notes are required.");
                }

                $wo->update([
                    'status' => $newStatus,
                    'updated_by' => $userId
                ]);

                $this->historyService->log($wo->id, $userId, 'resolved', 'status', $oldStatus->value, $newStatus->value, $resolutionNotes);

                event(new \Modules\Operations\WorkOrder\Events\WorkOrderCompleted($wo));

            } else {
                throw new \Exception("Arbitrary status transition to {$newStatus->value} is not allowed.");
            }

            return $wo;
        });
    }
}
