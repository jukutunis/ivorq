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

    public function createCategory(array $data): InventoryCategory
    {
        return $this->categoryRepository->create($data);
    }

    // --- Units ---

    public function createUnit(array $data): InventoryUnit
    {
        return $this->unitRepository->create($data);
    }

    // --- Locations ---

    public function createLocation(array $data): InventoryLocation
    {
        return $this->locationRepository->create($data);
    }
}
