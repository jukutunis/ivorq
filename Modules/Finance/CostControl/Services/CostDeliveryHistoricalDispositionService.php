<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Repositories\CostDeliveryOutboxDispositionRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryDispositionDecision;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
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
        private readonly CostLedgerRepository $costLedgerRepository,
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

            $sourceEquivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);

            if ($sourceEquivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
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
                    $sourceEquivalence->costLedgerEntryId,
                    $actorId,
                    now(),
                );

                return $this->dispositionRepository->persistHistorical($decision);
            }

            if ($sourceEquivalence->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
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
