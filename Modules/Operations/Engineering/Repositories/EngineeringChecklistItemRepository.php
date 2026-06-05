<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Models\EngineeringChecklistItem;

class EngineeringChecklistItemRepository
{
    public function forChecklist(string $checklistId): Collection
    {
        return EngineeringChecklistItem::where('engineering_checklist_id', $checklistId)
            ->orderBy('sort_order')
            ->get();
    }

    public function create(array $data): EngineeringChecklistItem
    {
        return EngineeringChecklistItem::create($data)->fresh();
    }

    public function update(string $id, array $data): EngineeringChecklistItem
    {
        $item = EngineeringChecklistItem::findOrFail($id);
        $item->update($data);

        return $item->fresh();
    }

    public function delete(string $id): bool
    {
        return EngineeringChecklistItem::findOrFail($id)->delete();
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
            EngineeringChecklistItem::where('id', $id)->update(['sort_order' => $sortOrder]);
        }
    }
}
