<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Shared\Exceptions\NotFoundException;

class InventoryIssueRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryIssue::with('department')->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryIssue
    {
        $issue = InventoryIssue::with([
            'lines.item.unit',
            'lines.location',
            'department',
            'postedBy',
            'cancelledBy',
        ])->find($id);

        throw_if(! $issue, new NotFoundException('InventoryIssue'));

        return $issue;
    }

    public function findOrFail(string $id): InventoryIssue
    {
        return InventoryIssue::findOrFail($id);
    }

    public function create(array $data): InventoryIssue
    {
        return InventoryIssue::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryIssue
    {
        $issue = $this->find($id);
        $issue->update($data);

        return $issue->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byStatus(IssueStatusEnum $status): Collection
    {
        return InventoryIssue::where('status', $status)
            ->with('department')
            ->latest()
            ->get();
    }
}
