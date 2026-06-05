<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Shared\Exceptions\NotFoundException;

class InventoryItemRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryItem::with(['category', 'unit'])->latest();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryItem
    {
        $item = InventoryItem::with([
            'category',
            'unit',
            'stockBalances.location',
        ])->find($id);

        throw_if(! $item, new NotFoundException('InventoryItem'));

        return $item;
    }

    public function findOrFail(string $id): InventoryItem
    {
        return InventoryItem::findOrFail($id);
    }

    public function create(array $data): InventoryItem
    {
        return InventoryItem::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryItem
    {
        $item = $this->find($id);
        $item->update($data);

        return $item->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return InventoryItem::with(['category', 'unit'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function lowStock(): Collection
    {
        return InventoryItem::where('is_active', true)
            ->whereHas('stockBalances', function ($q) {
                $q->where('status', ItemStatusEnum::LowStock);
            })
            ->with(['category', 'unit', 'stockBalances.location'])
            ->orderBy('name')
            ->get();
    }

    public function outOfStock(): Collection
    {
        // Items with no balance record or all balances at zero quantity
        return InventoryItem::where('is_active', true)
            ->whereDoesntHave('stockBalances', function ($q) {
                $q->where('quantity', '>', 0);
            })
            ->with(['category', 'unit'])
            ->orderBy('name')
            ->get();
    }
}
