<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
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

    public function persistDeferred(
        CostDeliveryDispositionDecision $decision,
    ): CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);

        if ($decision->processingState !== CostDeliveryProcessingState::Pending) {
            throw new RuntimeException('CC_P01E_DEFERRED_DISPOSITION_MUST_BEGIN_PENDING');
        }

        $existing = $this->findByEitherForUpdate(
            $decision->outboxMessageId,
            $decision->sourceInventoryTransactionId,
        );

        if ($existing !== null) {
            return $this->requireExactExistingDeferred($existing, $decision);
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
            'equivalent_cost_ledger_entry_id' => null,
            'classified_by' => $decision->classifiedBy,
            'classification_provenance' => $decision->classificationProvenance,
            'classified_at' => $timestamp,
            'attempt_count' => 0,
            'last_attempted_at' => null,
            'last_failure_code' => null,
            'is_recoverable' => null,
            'expected_sequence' => null,
            'historical_excluded_at' => null,
            'delivered_at' => null,
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
            return $this->requireExactExistingDeferred($concurrent, $decision);
        }

        throw new RuntimeException('CC_P01E_DISPOSITION_CONCURRENCY_RESOLUTION_FAILED');
    }

    public function markDelivered(
        CostDeliveryOutboxDisposition $disposition,
        \DateTimeInterface $completedAt,
    ): CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);
        if ($disposition->processing_state !== CostDeliveryProcessingState::Pending) {
            throw new RuntimeException('CC_P01E_DISPOSITION_NOT_PENDING_FOR_DELIVERY');
        }

        $disposition->processing_state = CostDeliveryProcessingState::Delivered;
        $disposition->attempt_count++;
        $disposition->last_attempted_at = $completedAt;
        $disposition->last_failure_code = null;
        $disposition->is_recoverable = null;
        $disposition->expected_sequence = null;
        $disposition->delivered_at = $completedAt;
        $disposition->updated_at = $completedAt;
        $disposition->save();

        return $disposition;
    }

    public function markFailed(
        CostDeliveryOutboxDisposition $disposition,
        string $failureCode,
        bool $recoverable,
        \DateTimeInterface $attemptedAt,
    ): CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);
        if ($disposition->processing_state !== CostDeliveryProcessingState::Pending) {
            throw new RuntimeException('CC_P01E_DISPOSITION_NOT_PENDING_FOR_FAILURE');
        }
        if (! preg_match('/^[A-Z0-9_]{1,96}$/', $failureCode)) {
            throw new RuntimeException('CC_P01E_FAILURE_CODE_INVALID');
        }

        $disposition->processing_state = CostDeliveryProcessingState::Failed;
        $disposition->attempt_count++;
        $disposition->last_attempted_at = $attemptedAt;
        $disposition->last_failure_code = $failureCode;
        $disposition->is_recoverable = $recoverable;
        $disposition->expected_sequence = null;
        $disposition->delivered_at = null;
        $disposition->updated_at = $attemptedAt;
        $disposition->save();

        return $disposition;
    }

    public function markBlockedSequence(
        CostDeliveryOutboxDisposition $disposition,
        int $expectedSequence,
        \DateTimeInterface $attemptedAt,
    ): CostDeliveryOutboxDisposition {
        $this->requireTransaction(__METHOD__);
        if ($disposition->processing_state !== CostDeliveryProcessingState::Pending) {
            throw new RuntimeException('CC_P01E_DISPOSITION_NOT_PENDING_FOR_BLOCKED_SEQUENCE');
        }
        if ($expectedSequence < 1) {
            throw new RuntimeException('CC_P01E_EXPECTED_SEQUENCE_INVALID');
        }

        $disposition->processing_state = CostDeliveryProcessingState::BlockedSequence;
        $disposition->attempt_count++;
        $disposition->last_attempted_at = $attemptedAt;
        $disposition->last_failure_code = 'BLOCKED_SEQUENCE';
        $disposition->is_recoverable = true;
        $disposition->expected_sequence = $expectedSequence;
        $disposition->delivered_at = null;
        $disposition->updated_at = $attemptedAt;
        $disposition->save();

        return $disposition;
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

    private function requireExactExistingDeferred(
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
            && $existing->cost_delivery_ownership_id === $decision->costDeliveryOwnershipId
            && $existing->cost_delivery_ownership_version === $decision->costDeliveryOwnershipVersion
            && $existing->cost_delivery_cutover_id === $decision->costDeliveryCutoverId
            && $existing->equivalent_cost_ledger_entry_id === null
            && $existing->classified_by === $decision->classifiedBy
            && $existing->classification_provenance === $decision->classificationProvenance;

        if (! $matches) {
            throw new RuntimeException('CC_P01E_EXISTING_DEFERRED_DISPOSITION_CONFLICT');
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
