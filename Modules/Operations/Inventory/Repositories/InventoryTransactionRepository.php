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

    public function findById(string $id): ?InventoryTransaction
    {
        return InventoryTransaction::find($id);
    }

    public function appendControlled(\Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent $intent): InventoryTransaction
    {
        $transaction = new InventoryTransaction();

        $transaction->property_id = $intent->propertyId;
        $transaction->item_id = $intent->itemId;
        $transaction->location_id = $intent->locationId;

        // Controlled fields
        $transaction->business_date = $intent->businessDate;
        $transaction->occurred_at = $intent->occurredAt;
        $transaction->source_document_type = $intent->sourceDocumentType;
        $transaction->source_document_id = $intent->sourceDocumentId;
        $transaction->source_line_type = $intent->sourceLineType;
        $transaction->source_line_id = $intent->sourceLineId;
        $transaction->movement_role = $intent->movementRole;
        $transaction->idempotency_key = $intent->idempotencyKey;

        // General ledger fields
        $transaction->transaction_type = $intent->transactionType;
        $transaction->quantity_change = $intent->quantityChange;

        if ($intent->reference !== null) {
            $transaction->reference = $intent->reference;
        }

        if ($intent->notes !== null) {
            $transaction->notes = $intent->notes;
        }

        if ($intent->reversesInventoryTransactionId !== null) {
            $transaction->reverses_inventory_transaction_id = $intent->reversesInventoryTransactionId;
        }

        if ($intent->correctsInventoryTransactionId !== null) {
            $transaction->corrects_inventory_transaction_id = $intent->correctsInventoryTransactionId;
        }

        $transaction->save();

        return $transaction;
    }
}
