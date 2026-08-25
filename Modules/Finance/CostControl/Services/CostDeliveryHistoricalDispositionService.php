<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Repositories\CostDeliveryOutboxDispositionRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryDispositionDecision;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use RuntimeException;

final class CostDeliveryHistoricalDispositionService
{
    public function __construct(
        private readonly OutboxRepository $outboxRepository,
        private readonly InventoryTransactionRepository $inventoryTransactionRepository,
        private readonly CostDeliveryOutboxDispositionRepository $dispositionRepository,
    ) {}

    public function classify(
        string $outboxMessageId,
        string $actorId,
    ): CostDeliveryOutboxDisposition {
        if (trim($outboxMessageId) === '') {
            throw new InvalidArgumentException('CC_P01B_OUTBOX_ID_REQUIRED');
        }

        if (trim($actorId) === '') {
            throw new InvalidArgumentException('CC_P01B_CLASSIFICATION_ACTOR_REQUIRED');
        }

        return DB::transaction(function () use ($outboxMessageId, $actorId): CostDeliveryOutboxDisposition {
            if (! User::whereKey($actorId)->exists()) {
                throw new RuntimeException('CC_P01B_CLASSIFICATION_ACTOR_NOT_FOUND');
            }

            $outbox = $this->outboxRepository->findForUpdate($outboxMessageId);

            if ($outbox === null) {
                throw new RuntimeException('CC_P01B_OUTBOX_NOT_FOUND');
            }

            if ($outbox->topic !== 'inventory.transaction.posted') {
                throw new RuntimeException('CC_P01B_OUTBOX_TOPIC_INVALID');
            }

            $sourceId = trim((string) $outbox->source_inventory_transaction_id);
            if ($sourceId === '') {
                throw new RuntimeException('CC_P01B_OUTBOX_SOURCE_ID_MISSING');
            }

            $payload = $outbox->payload;
            if (! is_array($payload)
                || count($payload) !== 1
                || ! array_key_exists('transactionId', $payload)
                || ! is_string($payload['transactionId'])
                || $payload['transactionId'] !== $sourceId) {
                throw new RuntimeException('CC_P01B_OUTBOX_PAYLOAD_SOURCE_MISMATCH');
            }

            if ($outbox->idempotency_key !== "inventory_transaction:{$sourceId}:cost_ledger") {
                throw new RuntimeException('CC_P01B_OUTBOX_SOURCE_IDENTITY_MISMATCH');
            }

            $source = $this->inventoryTransactionRepository->findAndLock($sourceId);
            if ($source === null) {
                throw new RuntimeException('CC_P01B_INVENTORY_SOURCE_NOT_FOUND');
            }

            $this->assertCanonicalSourceScope($source, $sourceId);

            if ($source->cost_delivery_mode === 'DEFERRED') {
                throw new RuntimeException('CC_P01B_HISTORICAL_CLASSIFIER_DEFERRED_PROHIBITED');
            }

            if ($source->cost_delivery_mode !== null && $source->cost_delivery_mode !== 'SYNCHRONOUS') {
                throw new RuntimeException('CC_P01B_SOURCE_DELIVERY_MODE_INVALID');
            }

            $ledgerEntries = CostLedgerEntry::where('source_inventory_transaction_id', $source->id)
                ->orderBy('id')
                ->get();

            if ($ledgerEntries->count() === 1
                && $this->isExactCostLedgerEquivalent($source, $ledgerEntries->first())) {
                $decision = CostDeliveryDispositionDecision::synchronouslySatisfied(
                    $outbox->id,
                    $source->id,
                    $source->property_id,
                    $source->location_id,
                    $source->item_id,
                    $source->valuation_scope,
                    $source->valuation_sequence,
                    $source->cost_delivery_ownership_id,
                    $source->cost_delivery_ownership_version,
                    $ledgerEntries->first()->id,
                    $actorId,
                    now(),
                );

                return $this->dispositionRepository->persistHistorical($decision);
            }

            if ($ledgerEntries->isNotEmpty()) {
                throw new RuntimeException('CC_P01B_AMBIGUOUS_COST_LEDGER_EQUIVALENCE');
            }

            if ($source->cost_delivery_mode === null
                && $this->expectedEntryType($source) === null) {
                $decision = CostDeliveryDispositionDecision::nonCostControlEligible(
                    $outbox->id,
                    $source->id,
                    $source->property_id,
                    $source->location_id,
                    $source->item_id,
                    $source->valuation_scope,
                    $source->valuation_sequence,
                    $actorId,
                    now(),
                );

                return $this->dispositionRepository->persistHistorical($decision);
            }

            throw new RuntimeException('CC_P01B_AMBIGUOUS_HISTORICAL_ELIGIBILITY');
        });
    }

    private function assertCanonicalSourceScope(
        InventoryTransaction $source,
        string $expectedSourceId,
    ): void {
        if ($source->id !== $expectedSourceId
            || trim((string) $source->property_id) === ''
            || trim((string) $source->item_id) === ''
            || trim((string) $source->location_id) === ''
            || trim((string) $source->valuation_scope) === ''
            || $source->valuation_sequence === null
            || $source->valuation_sequence < 1) {
            throw new RuntimeException('CC_P01B_INVENTORY_SOURCE_EVIDENCE_INCOMPLETE');
        }

        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($source->valuation_scope !== $expectedScope) {
            throw new RuntimeException('CC_P01B_INVENTORY_SOURCE_SCOPE_MISMATCH');
        }

        $item = InventoryItem::find($source->item_id);
        $location = InventoryLocation::find($source->location_id);
        if ($item === null
            || $location === null
            || $item->property_id !== $source->property_id
            || $location->property_id !== $source->property_id) {
            throw new RuntimeException('CC_P01B_INVENTORY_SOURCE_PROPERTY_MISMATCH');
        }
    }

    private function isExactCostLedgerEquivalent(
        InventoryTransaction $source,
        CostLedgerEntry $entry,
    ): bool {
        $expectedEntryType = $this->expectedEntryType($source);
        if ($expectedEntryType === null || $source->corrects_inventory_transaction_id !== null) {
            return false;
        }

        $expectedIdempotencyKey = $source->transaction_type === TransactionTypeEnum::Reversal
            ? "reversal_ledger:{$source->id}"
            : (string) $source->idempotency_key;

        if ($expectedIdempotencyKey === '') {
            return false;
        }

        $coreMatches = $entry->property_id === $source->property_id
            && $entry->source_inventory_transaction_id === $source->id
            && $entry->entry_type === $expectedEntryType
            && $entry->idempotency_key === $expectedIdempotencyKey
            && $entry->entry_sequence === $source->valuation_sequence
            && $entry->currency_code === $source->currency_code
            && bccomp((string) $entry->quantity_delta, (string) $source->quantity_change, 4) === 0
            && bccomp((string) $entry->unit_cost, (string) $source->unit_cost, 4) === 0
            && bccomp((string) $entry->value_delta, (string) $source->total_cost, 4) === 0
            && $entry->business_date?->format('Y-m-d') === $source->business_date?->format('Y-m-d')
            && $entry->occurred_at?->format('Y-m-d H:i:s') === $source->occurred_at?->format('Y-m-d H:i:s')
            && $entry->prior_cost_ledger_entry_id === null;

        if (! $coreMatches) {
            return false;
        }

        if ($source->transaction_type !== TransactionTypeEnum::Reversal) {
            return $source->reverses_inventory_transaction_id === null
                && $entry->original_business_date === null;
        }

        if ($source->reverses_inventory_transaction_id === null) {
            return false;
        }

        $original = InventoryTransaction::find($source->reverses_inventory_transaction_id);
        $metadata = $entry->metadata;

        return $original !== null
            && $original->property_id === $source->property_id
            && $original->item_id === $source->item_id
            && $original->location_id === $source->location_id
            && $entry->original_business_date?->format('Y-m-d') === $original->business_date?->format('Y-m-d')
            && is_array($metadata)
            && ($metadata['original_transaction_id'] ?? null) === $original->id
            && ($metadata['approval_reference'] ?? null) === $source->valuation_approval_reference
            && is_string($metadata['reversal_reason'] ?? null)
            && trim($metadata['reversal_reason']) !== '';
    }

    private function expectedEntryType(InventoryTransaction $source): ?string
    {
        return match ($source->transaction_type) {
            TransactionTypeEnum::PurchaseReceipt => 'receipt',
            TransactionTypeEnum::Issue => 'issue',
            TransactionTypeEnum::TransferOut, TransactionTypeEnum::TransferIn => 'transfer',
            TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
            TransactionTypeEnum::Reversal => 'reversal',
            default => null,
        };
    }
}
