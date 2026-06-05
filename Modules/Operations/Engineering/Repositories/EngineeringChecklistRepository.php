<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Shared\Exceptions\NotFoundException;

class EngineeringChecklistRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = EngineeringChecklist::withCount('items')->latest();

        if (! empty($filters['checklist_type'])) {
            $query->where('checklist_type', $filters['checklist_type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): EngineeringChecklist
    {
        $checklist = EngineeringChecklist::with('items')->find($id);

        throw_if(! $checklist, new NotFoundException('EngineeringChecklist'));

        return $checklist;
    }

    public function create(array $data): EngineeringChecklist
    {
        return EngineeringChecklist::create($data)->fresh();
    }

    public function update(string $id, array $data): EngineeringChecklist
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
        return EngineeringChecklist::where('is_active', true)
            ->withCount('items')
            ->latest()
            ->get();
    }

    public function byType(EngineeringChecklistTypeEnum $type): Collection
    {
        return EngineeringChecklist::where('checklist_type', $type)
            ->where('is_active', true)
            ->with('items')
            ->latest()
            ->get();
    }
}
