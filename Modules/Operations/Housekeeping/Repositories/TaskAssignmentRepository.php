<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Shared\Exceptions\NotFoundException;

class TaskAssignmentRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TaskAssignment::with(['task', 'user', 'department'])
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): TaskAssignment
    {
        $assignment = TaskAssignment::with(['task', 'user', 'department', 'assignedBy'])->find($id);

        throw_if(! $assignment, new NotFoundException('TaskAssignment'));

        return $assignment;
    }

    public function activeForTask(string $taskId): Collection
    {
        return TaskAssignment::where('cleaning_task_id', $taskId)
            ->where('status', AssignmentStatusEnum::Active)
            ->with(['user', 'department'])
            ->get();
    }

    public function activeForUser(string $userId): Collection
    {
        return TaskAssignment::where('user_id', $userId)
            ->where('status', AssignmentStatusEnum::Active)
            ->with(['task.room', 'task.zone'])
            ->latest('assigned_at')
            ->get();
    }
}
