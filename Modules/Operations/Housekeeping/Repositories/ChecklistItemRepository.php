<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Models\ChecklistItem;

class ChecklistItemRepository
{
    public function forChecklist(string $checklistId): Collection
    {
        return ChecklistItem::where('checklist_id', $checklistId)
            ->orderBy('sort_order')
            ->get();
    }

    public function create(array $data): ChecklistItem
    {
        return ChecklistItem::create($data)->fresh();
    }

    public function update(string $id, array $data): ChecklistItem
    {
        $item = ChecklistItem::findOrFail($id);
        $item->update($data);

        return $item->fresh();
    }

    public function delete(string $id): bool
    {
        return ChecklistItem::findOrFail($id)->delete();
    }

    /**
     * Update sort_order for a set of items in one batch.
     *
     * $orderedIds is an ordered array of item IDs. Each item receives
     * a sort_order equal to its position (0-indexed) in the array.
     */
    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $sortOrder => $id) {
            ChecklistItem::where('id', $id)->update(['sort_order' => $sortOrder]);
        }
    }
}
