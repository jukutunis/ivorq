<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Models\FolioItem;
use Shared\Exceptions\NotFoundException;

class FolioItemRepository
{
    public function forFolio(string $folioId, bool $includeVoided = false): Collection
    {
        $query = FolioItem::where('folio_id', $folioId)
            ->with('postedBy')
            ->orderBy('posted_at');

        if (! $includeVoided) {
            $query->where('is_void', false);
        }

        return $query->get();
    }

    public function find(string $id): FolioItem
    {
        $item = FolioItem::with(['folio', 'postedBy'])->find($id);

        throw_if(! $item, new NotFoundException('FolioItem'));

        return $item;
    }

    public function findOrFail(string $id): FolioItem
    {
        return FolioItem::findOrFail($id);
    }

    /**
     * Controlled folio item creation.
     *
     * Business-input fields (item_type, description, quantity, amount)
     * may use normal fill. Server-owned fields (property_id, folio_id,
     * is_void, posted_at, posted_by, created_by) are set via forceFill.
     *
     * @internal Called only by GuestLedgerFolioAggregateService.
     */
    public function createControlled(array $businessInput, array $serverFields): FolioItem
    {
        $item = new FolioItem($businessInput);
        $item->forceFill($serverFields)->save();

        return $item->fresh();
    }

    /**
     * Lock a folio item row FOR UPDATE.
     */
    public function lockForUpdate(string $id): FolioItem
    {
        $item = FolioItem::where('id', $id)->lockForUpdate()->first();

        throw_if(! $item, new NotFoundException('FolioItem'));

        return $item;
    }

    /**
     * Void a folio line item.
     * FolioItems are immutable — we mark is_void rather than delete.
     */
    public function voidItem(string $id): FolioItem
    {
        $item = $this->findOrFail($id);
        // is_void is guarded — must use forceFill to set it
        $item->forceFill(['is_void' => true])->save();

        return $item->fresh();
    }
}
