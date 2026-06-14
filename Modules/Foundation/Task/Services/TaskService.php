<?php

namespace Modules\Foundation\Task\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\Task\Models\TaskAssignment;
use Modules\Foundation\Task\Repositories\TaskRepository;
use Modules\Foundation\Task\Events\TaskAssigned;
use Modules\Foundation\Task\Events\TaskCompleted;
use Modules\Foundation\Task\Events\TaskCancelled;

class TaskService
{
    public function __construct(
        private TaskRepository $taskRepository
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->taskRepository->paginate($filters, $perPage);
    }

    public function find(string $id): Task
    {
        return $this->taskRepository->find($id);
    }

    public function create(array $data): Task
    {
        return $this->taskRepository->create($data);
    }

    public function update(string $id, array $data): Task
    {
        return $this->taskRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->taskRepository->delete($id);
    }

    public function assignTask(string $taskId, string $assigneeType, string $assigneeId): TaskAssignment
    {
        $task = $this->find($taskId);
        $assignment = $task->assignments()->create([
            'property_id' => $task->property_id,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
        ]);
        
        TaskAssigned::dispatch($task, $assignment);
        
        return $assignment;
    }

    public function completeTask(string $taskId): Task
    {
        $task = $this->find($taskId);
        $this->update($taskId, ['status' => 'completed']);
        $task->refresh();
        
        TaskCompleted::dispatch($task);
        
        return $task;
    }

    public function cancelTask(string $taskId): Task
    {
        $task = $this->find($taskId);
        $this->update($taskId, ['status' => 'cancelled']);
        $task->refresh();
        
        TaskCancelled::dispatch($task);
        
        return $task;
    }
}
