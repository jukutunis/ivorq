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
     * is_void, posted_at, posted_by, created_by, source identity) are set
     * via forceFill.
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
     * Resolve the minimum parent identity for a folio item without locking.
     *
     * Returns (item_id, folio_id, property_id) scoped to the current
     * property. Unknown and cross-property identifiers produce the same
     * non-disclosing NotFoundException.
     *
     * @return object{id: string, folio_id: string, property_id: string}
     *
     * @internal Called only by GuestLedgerFolioAggregateService::voidItem.
     */
    public function findIdentityForProperty(string $itemId, string $propertyId): object
    {
        $identity = FolioItem::withoutGlobalScope('property')
            ->where('id', $itemId)
            ->where('property_id', $propertyId)
            ->select('id', 'folio_id', 'property_id')
            ->first();

        throw_if(! $identity, new NotFoundException('FolioItem'));

        return (object) $identity->only('id', 'folio_id', 'property_id');
    }

    /**
     * Lock a folio item row FOR UPDATE scoped by property and parent folio.
     *
     * @internal Called only by GuestLedgerFolioAggregateService::voidItem.
     */
    public function lockForUpdateInFolio(string $itemId, string $folioId, string $propertyId): FolioItem
    {
        $item = FolioItem::withoutGlobalScope('property')
            ->where('id', $itemId)
            ->where('folio_id', $folioId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        throw_if(! $item, new NotFoundException('FolioItem'));

        return $item;
    }

    /**
     * Mark an already-locked FolioItem as void without re-querying.
     *
     * The caller MUST hold a row lock on the passed instance.
     *
     * @internal Called only by GuestLedgerFolioAggregateService::voidItem.
     */
    public function voidLocked(FolioItem $item): FolioItem
    {
        $item->forceFill(['is_void' => true])->save();

        return $item->fresh();
    }
}
