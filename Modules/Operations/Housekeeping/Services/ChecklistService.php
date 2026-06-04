<?php

namespace Modules\Operations\Housekeeping\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Housekeeping\Models\ChecklistItem;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Repositories\ChecklistItemRepository;
use Modules\Operations\Housekeeping\Repositories\ChecklistRepository;

class ChecklistService
{
    public function __construct(
        private ChecklistRepository     $checklistRepository,
        private ChecklistItemRepository $itemRepository,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->checklistRepository->paginate($perPage);
    }

    public function find(string $id): CleaningChecklist
    {
        return $this->checklistRepository->find($id);
    }

    public function create(array $data): CleaningChecklist
    {
        return $this->checklistRepository->create($data);
    }

    public function update(string $id, array $data): CleaningChecklist
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
     * then creates the item with checklist_id injected.
     */
    public function addItem(string $checklistId, array $data): ChecklistItem
    {
        $this->checklistRepository->find($checklistId);

        return $this->itemRepository->create(
            array_merge($data, ['checklist_id' => $checklistId])
        );
    }

    public function updateItem(string $itemId, array $data): ChecklistItem
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
     * $orderedIds is an ordered array of ChecklistItem IDs.
     * Each item receives a sort_order equal to its position in the array.
     */
    public function reorderItems(array $orderedIds): void
    {
        $this->itemRepository->reorder($orderedIds);
    }
}
