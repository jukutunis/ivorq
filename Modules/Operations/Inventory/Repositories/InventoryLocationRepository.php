<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Shared\Exceptions\NotFoundException;

class InventoryLocationRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryLocation::latest();

        if (! empty($filters['location_type'])) {
            $query->where('location_type', $filters['location_type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryLocation
    {
        $location = InventoryLocation::with([
            'stockBalances.item.unit',
            'stockBalances.item.category',
        ])->find($id);

        throw_if(! $location, new NotFoundException('InventoryLocation'));

        return $location;
    }

    public function findOrFail(string $id): InventoryLocation
    {
        return InventoryLocation::findOrFail($id);
    }

    public function create(array $data): InventoryLocation
    {
        return InventoryLocation::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryLocation
    {
        $location = $this->find($id);
        $location->update($data);

        return $location->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return InventoryLocation::where('is_active', true)->orderBy('name')->get();
    }

    public function byType(LocationTypeEnum $type): Collection
    {
        return InventoryLocation::where('location_type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
