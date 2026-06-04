<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Repositories\CleaningTaskRepository;
use Modules\Operations\Housekeeping\Repositories\TaskAssignmentRepository;

class CleaningTaskService
{
    public function __construct(
        private CleaningTaskRepository   $taskRepository,
        private TaskAssignmentRepository $assignmentRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->taskRepository->paginate($filters, $perPage);
    }

    public function find(string $id): CleaningTask
    {
        return $this->taskRepository->find($id);
    }

    public function create(array $data): CleaningTask
    {
        $task = $this->taskRepository->create($data);

        event(new CleaningTaskCreated($task));

        return $task;
    }

    /**
     * Update task fields. Status changes are not allowed here — use changeStatus().
     * Any 'status' key in $data is stripped before persisting.
     */
    public function update(string $id, array $data): CleaningTask
    {
        unset($data['status']);

        return $this->taskRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->taskRepository->delete($id);
    }

    /**
     * Transition a task to a new status.
     *
     * Side effects by target status:
     *   in_progress → sets started_at (if null) + fires CleaningTaskStarted
     *   completed   → sets completed_at + completed_by + fires CleaningTaskCompleted
     *   cancelled   → fires CleaningTaskCancelled (with optional reason)
     */
    public function changeStatus(
        string         $id,
        TaskStatusEnum $new,
        ?string        $remarks = null
    ): CleaningTask {
        $task = $this->taskRepository->findOrFail($id);
        $from = $task->status;

        if (! $from->canTransitionTo($new)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition task from {$from->label()} to {$new->label()}.",
                ],
            ]);
        }

        $updates = ['status' => $new];

        if ($new === TaskStatusEnum::InProgress && $task->started_at === null) {
            $updates['started_at'] = now();
        }

        if ($new === TaskStatusEnum::Completed) {
            $updates['completed_at'] = now();
            $updates['completed_by'] = auth()->id();
        }

        $task->update($updates);
        $task = $task->fresh();

        match ($new) {
            TaskStatusEnum::InProgress => event(new CleaningTaskStarted($task)),
            TaskStatusEnum::Completed  => event(new CleaningTaskCompleted($task)),
            TaskStatusEnum::Cancelled  => event(new CleaningTaskCancelled($task, $remarks)),
            default                    => null,
        };

        return $task;
    }

    /**
     * Assign a task to a user/department.
     *
     * Creates a TaskAssignment record, transitions the task to 'assigned'
     * if it is currently 'pending', and fires CleaningTaskAssigned.
     *
     * Expected keys in $data: user_id, department_id (+ any optional assignment fields).
     * property_id and assigned_at are always set from context.
     */
    public function assign(string $taskId, array $data): TaskAssignment
    {
        $task = $this->taskRepository->findOrFail($taskId);

        $assignment = $this->assignmentRepository->create(array_merge($data, [
            'cleaning_task_id' => $taskId,
            'property_id'      => $task->property_id,
            'assigned_at'      => now(),
            'status'           => AssignmentStatusEnum::Active->value,
        ]));

        // Transition task from pending → assigned only when still unassigned.
        if ($task->status === TaskStatusEnum::Pending) {
            $task->update(['status' => TaskStatusEnum::Assigned]);
            $task = $task->fresh();
        }

        event(new CleaningTaskAssigned($task, $assignment));

        return $assignment;
    }
}
