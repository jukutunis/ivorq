<?php

namespace Modules\Operations\Engineering\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\EngineeringChecklistItem;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistItemRepository;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistRepository;

class EngineeringChecklistService
{
    public function __construct(
        private EngineeringChecklistRepository     $checklistRepository,
        private EngineeringChecklistItemRepository $itemRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->checklistRepository->paginate($filters, $perPage);
    }

    public function find(string $id): EngineeringChecklist
    {
        return $this->checklistRepository->find($id);
    }

    public function create(array $data): EngineeringChecklist
    {
        return $this->checklistRepository->create($data);
    }

    public function update(string $id, array $data): EngineeringChecklist
    {
        return $this->checklistRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->checklistRepository->delete($id);
    }

    /**
     * Add an item to a checklist.
     *
     * Verifies the checklist exists (throws NotFoundException if not),
     * then creates the item with engineering_checklist_id injected.
     */
    public function addItem(string $checklistId, array $data): EngineeringChecklistItem
    {
        $this->checklistRepository->find($checklistId);

        return $this->itemRepository->create(
            array_merge($data, ['engineering_checklist_id' => $checklistId])
        );
    }

    public function updateItem(string $itemId, array $data): EngineeringChecklistItem
    {
        return $this->itemRepository->update($itemId, $data);
    }

    public function deleteItem(string $itemId): bool
    {
        return $this->itemRepository->delete($itemId);
    }

    /**
     * Reorder checklist items.
     *
     * $orderedIds is an ordered array of EngineeringChecklistItem IDs.
     * Each item receives a sort_order equal to its position in the array.
     */
    public function reorderItems(array $orderedIds): void
    {
        $this->itemRepository->reorder($orderedIds);
    }
}
