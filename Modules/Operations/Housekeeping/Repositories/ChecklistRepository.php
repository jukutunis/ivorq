<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Shared\Exceptions\NotFoundException;

class ChecklistRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return CleaningChecklist::withCount('items')
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): CleaningChecklist
    {
        $checklist = CleaningChecklist::with('items')->find($id);

        throw_if(! $checklist, new NotFoundException('CleaningChecklist'));

        return $checklist;
    }

    public function create(array $data): CleaningChecklist
    {
        return CleaningChecklist::create($data)->fresh();
    }

    public function update(string $id, array $data): CleaningChecklist
    {
        $checklist = $this->find($id);
        $checklist->update($data);

        return $checklist->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return CleaningChecklist::where('is_active', true)
            ->withCount('items')
            ->latest()
            ->get();
    }

    public function byTaskType(string $taskType): Collection
    {
        return CleaningChecklist::where('task_type', $taskType)
            ->where('is_active', true)
            ->with('items')
            ->latest()
            ->get();
    }
}
