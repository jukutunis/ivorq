<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Models\InventoryStockCard;

class InventoryStockCardRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryStockCard::with(['item.unit', 'location'])
            ->orderBy('posted_at', 'desc');

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('posted_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('posted_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function forItem(string $itemId, int $limit = 20): Collection
    {
        return InventoryStockCard::where('item_id', $itemId)
            ->with(['location', 'postedBy'])
            ->orderBy('posted_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function recent(int $limit = 20): Collection
    {
        return InventoryStockCard::with(['item.unit', 'location', 'postedBy'])
            ->orderBy('posted_at', 'desc')
            ->limit($limit)
            ->get();
    }

    // Append-only: the model guards against mass assignment.
    // forceFill() is used here because only StockMovementService is the
    // sanctioned writer — guarding at the model layer prevents accidental
    // writes elsewhere in the codebase.
    public function create(array $data): InventoryStockCard
    {
        $card = (new InventoryStockCard())->forceFill($data);
        $card->save();

        return $card;
    }
}
