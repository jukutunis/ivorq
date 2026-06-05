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

    public function create(array $data): FolioItem
    {
        return FolioItem::create($data)->fresh();
    }

    /**
     * Void a folio line item.
     * FolioItems are immutable — we mark is_void rather than delete.
     * The folio balance recalculation is the responsibility of FolioService.
     */
    public function voidItem(string $id): FolioItem
    {
        $item = $this->findOrFail($id);
        $item->update(['is_void' => true]);

        return $item->fresh();
    }
}
