<?php

namespace Modules\Foundation\Task\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Task\Models\Task;
use Shared\Exceptions\NotFoundException;

class TaskRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Task::query()->latest();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['taskable_type']) && isset($filters['taskable_id'])) {
            $query->where('taskable_type', $filters['taskable_type'])
                  ->where('taskable_id', $filters['taskable_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Task
    {
        $task = Task::find($id);

        throw_if(!$task, new NotFoundException('Task'));

        return $task;
    }

    public function create(array $data): Task
    {
        return Task::create($data);
    }

    public function update(string $id, array $data): Task
    {
        $task = $this->find($id);
        $task->update($data);

        return $task->fresh();
    }

    public function delete(string $id): bool
    {
        $task = $this->find($id);

        return $task->delete();
    }
}
