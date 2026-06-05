<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Shared\Exceptions\NotFoundException;

class InventoryAdjustmentRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryAdjustment::with('location')->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['adjustment_type'])) {
            $query->where('adjustment_type', $filters['adjustment_type']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryAdjustment
    {
        $adjustment = InventoryAdjustment::with([
            'location',
            'lines.item.unit',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
        ])->find($id);

        throw_if(! $adjustment, new NotFoundException('InventoryAdjustment'));

        return $adjustment;
    }

    public function findOrFail(string $id): InventoryAdjustment
    {
        return InventoryAdjustment::findOrFail($id);
    }

    public function create(array $data): InventoryAdjustment
    {
        return InventoryAdjustment::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryAdjustment
    {
        $adjustment = $this->find($id);
        $adjustment->update($data);

        return $adjustment->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byStatus(AdjustmentStatusEnum $status): Collection
    {
        return InventoryAdjustment::where('status', $status)
            ->with('location')
            ->latest()
            ->get();
    }
}
