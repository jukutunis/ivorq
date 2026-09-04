<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Services\InventoryDocumentMutationGate;
use Shared\Exceptions\NotFoundException;

class InventoryAdjustmentRepository
{
    public function __construct(private readonly InventoryDocumentMutationGate $mutationGate) {}

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
        return DB::transaction(function () use ($data): InventoryAdjustment {
            $this->mutationGate->lock(
                (string) $data['property_id'],
                collect($data['lines'] ?? [])->pluck('item_id')->all(),
            );

            return InventoryAdjustment::create($data)->fresh();
        });
    }

    public function update(string $id, array $data, bool $ownershipAlreadyLocked = false): InventoryAdjustment
    {
        return DB::transaction(function () use ($id, $data, $ownershipAlreadyLocked): InventoryAdjustment {
            $candidate = $this->find($id);
            $itemIds = $candidate->lines->pluck('item_id')
                ->merge(collect($data['lines'] ?? [])->pluck('item_id'))->all();
            if (! $ownershipAlreadyLocked) {
                $this->mutationGate->lock((string) $candidate->property_id, $itemIds);
            }
            $adjustment = InventoryAdjustment::whereKey($id)->lockForUpdate()->firstOrFail();
            $adjustment->update($data);

            return $adjustment->fresh();
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $candidate = $this->find($id);
            $this->mutationGate->lock((string) $candidate->property_id, $candidate->lines->pluck('item_id')->all());

            return (bool) InventoryAdjustment::whereKey($id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function byStatus(AdjustmentStatusEnum $status): Collection
    {
        return InventoryAdjustment::where('status', $status)
            ->with('location')
            ->latest()
            ->get();
    }
}
