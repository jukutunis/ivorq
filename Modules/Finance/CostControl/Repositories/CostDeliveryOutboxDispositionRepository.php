<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryDispositionDecision;
use RuntimeException;

class CostDeliveryOutboxDispositionRepository
{
    public function findByOutboxMessageId(string $outboxMessageId): ?CostDeliveryOutboxDisposition
    {
        return CostDeliveryOutboxDisposition::where('outbox_message_id', $outboxMessageId)->first();
    }

    public function findBySourceInventoryTransactionId(string $sourceId): ?CostDeliveryOutboxDisposition
    {
        return CostDeliveryOutboxDisposition::where('source_inventory_transaction_id', $sourceId)->first();
    }

    public function findByEitherForUpdate(
        string $outboxMessageId,
        string $sourceId,
    ): ?CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryOutboxDisposition::where('outbox_message_id', $outboxMessageId)
            ->orWhere('source_inventory_transaction_id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    public function persistHistorical(
        CostDeliveryDispositionDecision $decision,
    ): CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);

        $existing = $this->findByEitherForUpdate(
            $decision->outboxMessageId,
            $decision->sourceInventoryTransactionId,
        );

        if ($existing !== null) {
            return $this->requireExactExisting($existing, $decision);
        }

        $id = (string) Str::ulid();
        $timestamp = $decision->classifiedAt;
        $inserted = DB::table('cost_delivery_outbox_dispositions')->insertOrIgnore([
            'id' => $id,
            'outbox_message_id' => $decision->outboxMessageId,
            'source_inventory_transaction_id' => $decision->sourceInventoryTransactionId,
            'property_id' => $decision->propertyId,
            'location_id' => $decision->locationId,
            'item_id' => $decision->itemId,
            'valuation_scope' => $decision->valuationScope,
            'valuation_sequence' => $decision->valuationSequence,
            'classification' => $decision->classification->value,
            'processing_state' => $decision->processingState->value,
            'cost_delivery_ownership_id' => $decision->costDeliveryOwnershipId,
            'cost_delivery_ownership_version' => $decision->costDeliveryOwnershipVersion,
            'cost_delivery_cutover_id' => $decision->costDeliveryCutoverId,
            'equivalent_cost_ledger_entry_id' => $decision->equivalentCostLedgerEntryId,
            'classified_by' => $decision->classifiedBy,
            'classification_provenance' => $decision->classificationProvenance,
            'classified_at' => $timestamp,
            'attempt_count' => 0,
            'historical_excluded_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($inserted === 1) {
            return CostDeliveryOutboxDisposition::findOrFail($id);
        }

        $concurrent = $this->findByEitherForUpdate(
            $decision->outboxMessageId,
            $decision->sourceInventoryTransactionId,
        );

        if ($concurrent !== null) {
            return $this->requireExactExisting($concurrent, $decision);
        }

        throw new RuntimeException('CC_P01B_DISPOSITION_CONCURRENCY_RESOLUTION_FAILED');
    }

    private function requireExactExisting(
        CostDeliveryOutboxDisposition $existing,
        CostDeliveryDispositionDecision $decision,
    ): CostDeliveryOutboxDisposition {
        $matches = $existing->outbox_message_id === $decision->outboxMessageId
            && $existing->source_inventory_transaction_id === $decision->sourceInventoryTransactionId
            && $existing->property_id === $decision->propertyId
            && $existing->location_id === $decision->locationId
            && $existing->item_id === $decision->itemId
            && $existing->valuation_scope === $decision->valuationScope
            && $existing->valuation_sequence === $decision->valuationSequence
            && $existing->classification === $decision->classification
            && $existing->processing_state === $decision->processingState
            && $existing->cost_delivery_ownership_id === $decision->costDeliveryOwnershipId
            && $existing->cost_delivery_ownership_version === $decision->costDeliveryOwnershipVersion
            && $existing->cost_delivery_cutover_id === $decision->costDeliveryCutoverId
            && $existing->equivalent_cost_ledger_entry_id === $decision->equivalentCostLedgerEntryId
            && $existing->classified_by === $decision->classifiedBy
            && $existing->classification_provenance === $decision->classificationProvenance;

        if (! $matches) {
            throw new RuntimeException('CC_P01B_EXISTING_DISPOSITION_CONFLICT');
        }

        return $existing;
    }

    private function requireTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException("{$method} requires an active outer transaction.");
        }
    }
}
