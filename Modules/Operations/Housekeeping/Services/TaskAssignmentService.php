<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Repositories\TaskAssignmentRepository;

class TaskAssignmentService
{
    public function __construct(
        private TaskAssignmentRepository $repository
    ) {}

    public function find(string $id): TaskAssignment
    {
        return $this->repository->find($id);
    }

    /**
     * Mark an assignment as completed and record the completion timestamp.
     */
    public function complete(string $id): TaskAssignment
    {
        return $this->repository->update($id, [
            'status'       => AssignmentStatusEnum::Completed->value,
            'completed_at' => now(),
        ]);
    }

    /**
     * Cancel an assignment.
     */
    public function cancel(string $id): TaskAssignment
    {
        return $this->repository->update($id, [
            'status' => AssignmentStatusEnum::Cancelled->value,
        ]);
    }
}
