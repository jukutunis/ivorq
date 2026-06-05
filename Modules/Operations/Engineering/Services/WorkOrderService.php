<?php

namespace Modules\Operations\Engineering\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Repositories\TechnicianAssignmentRepository;
use Modules\Operations\Engineering\Repositories\WorkOrderRepository;

class WorkOrderService
{
    public function __construct(
        private WorkOrderRepository            $workOrderRepository,
        private TechnicianAssignmentRepository $assignmentRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->workOrderRepository->paginate($filters, $perPage);
    }

    public function find(string $id): WorkOrder
    {
        return $this->workOrderRepository->find($id);
    }

    public function create(array $data): WorkOrder
    {
        $workOrder = $this->workOrderRepository->create($data);

        event(new WorkOrderCreated($workOrder));

        return $workOrder;
    }

    /**
     * Update work order fields. Direct status changes are not allowed here —
     * use changeStatus() instead. Any 'status' key in $data is stripped.
     */
    public function update(string $id, array $data): WorkOrder
    {
        unset($data['status']);

        return $this->workOrderRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->workOrderRepository->delete($id);
    }

    /**
     * Transition a work order to a new status.
     *
     * Side effects by target status:
     *   in_progress → sets started_at (if null) — fires WorkOrderStarted
     *   on_hold     → sets on_hold_reason — fires WorkOrderOnHold
     *   completed   → sets completed_at, completed_by, actual_hours (if started_at set)
     *                 fires WorkOrderCompleted
     *   cancelled   → sets cancelled_at, cancelled_by, cancellation_reason
     *                 fires WorkOrderCancelled
     *
     * The assigned transition is ONLY reached through assign() to ensure a
     * TechnicianAssignment record always accompanies the status change.
     */
    public function changeStatus(
        string              $id,
        WorkOrderStatusEnum $new,
        ?string             $remarks = null
    ): WorkOrder {
        $workOrder = $this->workOrderRepository->findOrFail($id);
        $from      = $workOrder->status;

        if (! $from->canTransitionTo($new)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition work order from {$from->label()} to {$new->label()}.",
                ],
            ]);
        }

        $updates = ['status' => $new];

        if ($new === WorkOrderStatusEnum::InProgress && $workOrder->started_at === null) {
            $updates['started_at'] = now();
        }

        if ($new === WorkOrderStatusEnum::OnHold) {
            $updates['on_hold_reason'] = $remarks;
        }

        if ($new === WorkOrderStatusEnum::Completed) {
            $updates['completed_at'] = now();
            $updates['completed_by'] = auth()->id();

            if ($workOrder->started_at !== null) {
                $updates['actual_hours'] = round(
                    $workOrder->started_at->diffInMinutes(now()) / 60,
                    2
                );
            }
        }

        if ($new === WorkOrderStatusEnum::Cancelled) {
            $updates['cancelled_at']        = now();
            $updates['cancelled_by']        = auth()->id();
            $updates['cancellation_reason'] = $remarks;
        }

        $workOrder->update($updates);
        $workOrder = $workOrder->fresh();

        match ($new) {
            WorkOrderStatusEnum::InProgress => event(new WorkOrderStarted($workOrder)),
            WorkOrderStatusEnum::OnHold     => event(new WorkOrderOnHold($workOrder, $remarks)),
            WorkOrderStatusEnum::Completed  => event(new WorkOrderCompleted($workOrder)),
            WorkOrderStatusEnum::Cancelled  => event(new WorkOrderCancelled($workOrder, $remarks)),
            default                         => null,
        };

        return $workOrder;
    }

    /**
     * Assign a technician to a work order.
     *
     * Creates a TechnicianAssignment record, transitions the work order from
     * pending → assigned (only when still pending), and fires WorkOrderAssigned.
     *
     * Expected keys in $data: user_id, role (optional), department_id (optional).
     * property_id and assigned_at are always injected from context.
     */
    public function assign(string $workOrderId, array $data): TechnicianAssignment
    {
        $workOrder  = $this->workOrderRepository->findOrFail($workOrderId);

        $assignment = $this->assignmentRepository->create(array_merge($data, [
            'work_order_id' => $workOrderId,
            'property_id'   => $workOrder->property_id,
            'assigned_at'   => now(),
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
        ]));

        if ($workOrder->status === WorkOrderStatusEnum::Pending) {
            $workOrder->update(['status' => WorkOrderStatusEnum::Assigned]);
            $workOrder = $workOrder->fresh();
        }

        event(new WorkOrderAssigned($workOrder, $assignment));

        return $assignment;
    }

    /**
     * Record management approval for a work order.
     *
     * Approval is orthogonal to the status lifecycle — it records who authorised
     * the work without forcing a status transition.
     */
    public function approve(string $id, ?string $approverId = null): WorkOrder
    {
        return $this->workOrderRepository->update($id, [
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => now(),
        ]);
    }
}
