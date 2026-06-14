<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Models\InventoryTransaction;

class InventoryTransactionRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryTransaction::with(['item.unit', 'location'])
            ->orderBy('posted_at', 'desc');

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
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
        return InventoryTransaction::where('item_id', $itemId)
            ->with(['location', 'postedBy'])
            ->orderBy('posted_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function recent(int $limit = 20): Collection
    {
        return InventoryTransaction::with(['item.unit', 'location', 'postedBy'])
            ->orderBy('posted_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function create(array $data): InventoryTransaction
    {
        $transaction = (new InventoryTransaction())->forceFill($data);
        $transaction->save();

        return $transaction;
    }
}
