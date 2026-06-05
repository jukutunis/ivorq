<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Shared\Exceptions\NotFoundException;

class InventoryUnitRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryUnit::latest();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryUnit
    {
        $unit = InventoryUnit::find($id);

        throw_if(! $unit, new NotFoundException('InventoryUnit'));

        return $unit;
    }

    public function findOrFail(string $id): InventoryUnit
    {
        return InventoryUnit::findOrFail($id);
    }

    public function create(array $data): InventoryUnit
    {
        return InventoryUnit::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryUnit
    {
        $unit = $this->find($id);
        $unit->update($data);

        return $unit->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return InventoryUnit::where('is_active', true)->orderBy('name')->get();
    }
}
