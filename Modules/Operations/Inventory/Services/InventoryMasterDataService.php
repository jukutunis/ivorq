<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Repositories\InventoryCategoryRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryLocationRepository;
use Modules\Operations\Inventory\Repositories\InventoryUnitRepository;

class InventoryMasterDataService
{
    public function __construct(
        private InventoryItemRepository $itemRepository,
        private InventoryCategoryRepository $categoryRepository,
        private InventoryUnitRepository $unitRepository,
        private InventoryLocationRepository $locationRepository
    ) {}

    // --- Items ---

    public function paginateItems(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->itemRepository->paginate($filters, $perPage);
    }

    public function findItem(string $id): InventoryItem
    {
        return $this->itemRepository->find($id);
    }

    public function createItem(array $data): InventoryItem
    {
        return $this->itemRepository->create($data);
    }

    public function updateItem(string $id, array $data): InventoryItem
    {
        return $this->itemRepository->update($id, $data);
    }

    public function deleteItem(string $id): bool
    {
        return $this->itemRepository->delete($id);
    }

    // --- Categories ---

    public function paginateCategories(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->categoryRepository->paginate($filters, $perPage);
    }

    public function findCategory(string $id): InventoryCategory
    {
        return $this->categoryRepository->findOrFail($id);
    }

    public function createCategory(array $data): InventoryCategory
    {
        return $this->categoryRepository->create($data);
    }

    public function updateCategory(string $id, array $data): InventoryCategory
    {
        return $this->categoryRepository->update($id, $data);
    }

    public function deleteCategory(string $id): bool
    {
        return $this->categoryRepository->delete($id);
    }

    // --- Units ---

    public function paginateUnits(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->unitRepository->paginate($filters, $perPage);
    }

    public function findUnit(string $id): InventoryUnit
    {
        return $this->unitRepository->findOrFail($id);
    }

    public function createUnit(array $data): InventoryUnit
    {
        return $this->unitRepository->create($data);
    }

    public function updateUnit(string $id, array $data): InventoryUnit
    {
        return $this->unitRepository->update($id, $data);
    }

    public function deleteUnit(string $id): bool
    {
        return $this->unitRepository->delete($id);
    }

    // --- Locations ---

    public function paginateLocations(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->locationRepository->paginate($filters, $perPage);
    }

    public function findLocation(string $id): InventoryLocation
    {
        return $this->locationRepository->findOrFail($id);
    }

    public function createLocation(array $data): InventoryLocation
    {
        return $this->locationRepository->create($data);
    }

    public function updateLocation(string $id, array $data): InventoryLocation
    {
        return $this->locationRepository->update($id, $data);
    }

    public function deleteLocation(string $id): bool
    {
        return $this->locationRepository->delete($id);
    }
}
