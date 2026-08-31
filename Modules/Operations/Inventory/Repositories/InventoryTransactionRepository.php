<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;

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
        $transaction = (new InventoryTransaction)->forceFill($data);
        $transaction->save();

        return $transaction;
    }

    public function findById(string $id): ?InventoryTransaction
    {
        return InventoryTransaction::find($id);
    }

    public function appendControlled(
        InventoryLedgerPostingIntent $intent,
        string $quantityBefore,
        string $quantityAfter,
        string $valuationApprovalStatus,
        string $valuationApprovalReference,
        ?string $actorId = null,
        ?string $currencyCode = null,
        ?string $financialPeriodId = null,
        ?string $valuationScope = null,
        ?int $valuationSequence = null,
        ?CostDeliveryPostingDecision $costDeliveryDecision = null
    ): InventoryTransaction {
        if ($costDeliveryDecision !== null
            && ($costDeliveryDecision->propertyId !== $intent->propertyId
                || $costDeliveryDecision->itemId !== $intent->itemId)) {
            throw new InvalidArgumentException(
                'Cost delivery posting decision Property/Item identity does not match the Inventory intent.'
            );
        }

        if ($costDeliveryDecision !== null
            && $costDeliveryDecision->outcome !== CostDeliveryPostingDecision::NOT_ENROLLED
            && ($costDeliveryDecision->locationId !== $intent->locationId
                || $valuationScope === null
                || $costDeliveryDecision->valuationScope !== $valuationScope)) {
            throw new InvalidArgumentException(
                'Cost delivery posting decision scope does not match the Inventory intent.'
            );
        }

        $transaction = new InventoryTransaction;

        $transaction->property_id = $intent->propertyId;
        $transaction->item_id = $intent->itemId;
        $transaction->location_id = $intent->locationId;
        $transaction->currency_code = $currencyCode;
        $transaction->financial_period_id = $financialPeriodId;
        $transaction->valuation_scope = $valuationScope;
        $transaction->valuation_sequence = $valuationSequence;
        $transaction->valuation_approval_status = $valuationApprovalStatus;
        $transaction->valuation_approval_reference = $valuationApprovalReference;
        $transaction->cost_delivery_mode = $costDeliveryDecision?->deliveryMode;
        $transaction->cost_delivery_ownership_id = $costDeliveryDecision?->ownershipId;
        $transaction->cost_delivery_ownership_version = $costDeliveryDecision?->ownershipVersion;
        $transaction->cost_delivery_cutover_id = $costDeliveryDecision?->cutoverId;

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
        $transaction->quantity_before = $quantityBefore;
        $transaction->quantity_change = $intent->quantityChange;
        $transaction->quantity_after = $quantityAfter;
        $transaction->posted_by = $actorId;
        $transaction->unit_cost = $intent->unitCost;
        $transaction->total_cost = $intent->totalCost;
        $transaction->posted_at = $intent->occurredAt;

        if ($intent->reference !== null) {
            $transaction->reference_id = $intent->reference;
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

    public function findAndLock(string $id): ?InventoryTransaction
    {
        return InventoryTransaction::where('id', $id)->lockForUpdate()->first();
    }

    public function hasReversal(string $id): bool
    {
        return InventoryTransaction::where('reverses_inventory_transaction_id', $id)->exists();
    }

    public function appendReversal(
        InventoryTransaction $original,
        string $businessDate,
        string $financialPeriodId,
        int $valuationSequence,
        string $quantityBefore,
        string $quantityAfter,
        string $valuationApprovalReference,
        string $idempotencyKey,
        ?string $actorId,
        CostDeliveryPostingDecision $costDeliveryDecision,
        Carbon $occurredAt,
    ): InventoryTransaction {
        $expectedScope = "property:{$original->property_id}:location:{$original->location_id}:item:{$original->item_id}";

        if ($original->valuation_scope !== $expectedScope
            || $costDeliveryDecision->outcome === CostDeliveryPostingDecision::NOT_ENROLLED
            || $costDeliveryDecision->propertyId !== $original->property_id
            || $costDeliveryDecision->itemId !== $original->item_id
            || $costDeliveryDecision->locationId !== $original->location_id
            || $costDeliveryDecision->valuationScope !== $expectedScope) {
            throw new InvalidArgumentException(
                'Cost delivery posting decision scope does not match the reversal source.'
            );
        }

        $transaction = new InventoryTransaction;

        $transaction->id = (string) Str::ulid();
        $transaction->property_id = $original->property_id;
        $transaction->item_id = $original->item_id;
        $transaction->location_id = $original->location_id;
        $transaction->currency_code = $original->currency_code;
        $transaction->financial_period_id = $financialPeriodId;
        $transaction->valuation_scope = $original->valuation_scope;
        $transaction->valuation_sequence = $valuationSequence;
        $transaction->valuation_approval_status = 'approved';
        $transaction->valuation_approval_reference = $valuationApprovalReference;
        $transaction->cost_delivery_mode = $costDeliveryDecision->deliveryMode;
        $transaction->cost_delivery_ownership_id = $costDeliveryDecision->ownershipId;
        $transaction->cost_delivery_ownership_version = $costDeliveryDecision->ownershipVersion;
        $transaction->cost_delivery_cutover_id = $costDeliveryDecision->cutoverId;

        // Reversal control fields
        $transaction->business_date = $businessDate;
        $transaction->occurred_at = $occurredAt;
        $transaction->source_document_type = $original->source_document_type;
        $transaction->source_document_id = $original->source_document_id;
        $transaction->source_line_type = $original->source_line_type;
        $transaction->source_line_id = $original->source_line_id;
        $transaction->movement_role = $original->movement_role;
        $transaction->idempotency_key = $idempotencyKey;

        // Cost and values negated
        $transaction->transaction_type = TransactionTypeEnum::Reversal;
        $transaction->quantity_before = $quantityBefore;
        $transaction->quantity_change = bcmul((string) $original->quantity_change, '-1', 4);
        $transaction->quantity_after = $quantityAfter;
        $transaction->posted_by = $actorId;
        $transaction->unit_cost = $original->unit_cost;
        $transaction->total_cost = bcmul((string) $original->total_cost, '-1', 2);
        $transaction->posted_at = $occurredAt;

        $transaction->reverses_inventory_transaction_id = $original->id;

        $transaction->save();

        return $transaction;
    }

    public function findByIdempotency(
        string $propertyId,
        string $idempotencyKey,
        bool $forUpdate = false,
    ): ?InventoryTransaction {
        $query = InventoryTransaction::where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
