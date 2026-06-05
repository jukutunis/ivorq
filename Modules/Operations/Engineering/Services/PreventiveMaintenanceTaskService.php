<?php

namespace Modules\Operations\Engineering\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskOverdue;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Repositories\PreventiveMaintenanceTaskRepository;
use Modules\Operations\Engineering\Repositories\WorkOrderRepository;

class PreventiveMaintenanceTaskService
{
    public function __construct(
        private PreventiveMaintenanceTaskRepository $taskRepository,
        private WorkOrderRepository                 $workOrderRepository,
    ) {}

    public function find(string $id): PreventiveMaintenanceTask
    {
        return $this->taskRepository->find($id);
    }

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->taskRepository->paginate($filters, $perPage);
    }

    /**
     * Transition a PM task to a new status.
     *
     * Side effects by target status:
     *   completed → sets completed_at, completed_by — fires PreventiveMaintenanceTaskCompleted
     *               (which triggers UpdatePreventiveMaintenanceSchedule)
     */
    public function changeStatus(
        string         $id,
        PmTaskStatusEnum $new,
        ?string        $remarks = null
    ): PreventiveMaintenanceTask {
        $task = $this->taskRepository->find($id);
        $from = $task->status;

        if (! $from->canTransitionTo($new)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition PM task from {$from->label()} to {$new->label()}.",
                ],
            ]);
        }

        $updates = ['status' => $new];

        if ($remarks !== null) {
            $updates['remarks'] = $remarks;
        }

        if ($new === PmTaskStatusEnum::Completed) {
            $updates['completed_at'] = now();
            $updates['completed_by'] = auth()->id();
        }

        $task->update($updates);
        $task = $task->fresh();

        if ($new === PmTaskStatusEnum::Completed) {
            event(new PreventiveMaintenanceTaskCompleted($task));
        }

        return $task;
    }

    /**
     * Generate a work order from a PM task.
     *
     * Creates a WorkOrder of type 'preventive' linked to the PM task, updates
     * the task's work_order_id, and fires WorkOrderCreated so history and
     * activity listeners run as normal.
     *
     * Required keys in $data: work_order_number, title.
     * Optional: priority, due_date, estimated_hours, asset_description.
     */
    public function createWorkOrderFromTask(string $taskId, array $data): WorkOrder
    {
        $task = $this->taskRepository->find($taskId);

        $workOrder = $this->workOrderRepository->create(array_merge($data, [
            'property_id'     => $task->property_id,
            'work_order_type' => WorkOrderTypeEnum::Preventive->value,
        ]));

        $this->taskRepository->update($taskId, ['work_order_id' => $workOrder->id]);

        event(new WorkOrderCreated($workOrder));

        return $workOrder;
    }

    /**
     * Bulk-mark non-terminal PM tasks with a past scheduled_date as overdue.
     *
     * This is a system-level batch operation called by a scheduled command.
     * It bypasses the normal status transition guard — overdue is set directly
     * by the system, not triggered by a user action.
     *
     * Fires PreventiveMaintenanceTaskOverdue for each affected task.
     *
     * @return int number of tasks marked overdue
     */
    public function markOverdue(): int
    {
        $tasks = PreventiveMaintenanceTask::whereIn('status', [
            PmTaskStatusEnum::Scheduled->value,
            PmTaskStatusEnum::Assigned->value,
            PmTaskStatusEnum::InProgress->value,
        ])
            ->where('scheduled_date', '<', now())
            ->get();

        foreach ($tasks as $task) {
            $task->update(['status' => PmTaskStatusEnum::Overdue]);
            event(new PreventiveMaintenanceTaskOverdue($task->fresh()));
        }

        return $tasks->count();
    }
}
