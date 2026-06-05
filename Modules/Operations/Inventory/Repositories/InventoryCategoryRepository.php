<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Shared\Exceptions\NotFoundException;

class InventoryCategoryRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryCategory::latest();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryCategory
    {
        $category = InventoryCategory::find($id);

        throw_if(! $category, new NotFoundException('InventoryCategory'));

        return $category;
    }

    public function findOrFail(string $id): InventoryCategory
    {
        return InventoryCategory::findOrFail($id);
    }

    public function create(array $data): InventoryCategory
    {
        return InventoryCategory::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryCategory
    {
        $category = $this->find($id);
        $category->update($data);

        return $category->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return InventoryCategory::where('is_active', true)->orderBy('name')->get();
    }
}
