<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Finance\CostControl\Services\ControlledTransferValuationApplyCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;

/**
 * final invocation service to coordinate locking state, reading WAUC,
 * posting transfer transactions, and applying controlled transfer valuations atomically.
 */
final class ControlledTransferValuationInvocationService
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly ControlledTransferValuationApplyCoordinator $applyCoordinator
    ) {}

    /**
     * Lock all scopes, resolve line-by-line logical states, post transaction legs,
     * and apply valuation plans.
     */
    public function invokeTransferDocument(
        string $propertyId,
        string $fromLocationId,
        string $toLocationId,
        array $documentData,
        array $linesData,
        ?string $actorId = null
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('ControlledTransferValuationInvocationService::invokeTransferDocument requires an active transaction.');
        }

        // 1. Build list of requested scopes for locking
        $requestedScopes = [];
        foreach ($linesData as $line) {
            $itemId = $line['itemId'];
            $requestedScopes[] = ['itemId' => $itemId, 'locationId' => $fromLocationId];
            $requestedScopes[] = ['itemId' => $itemId, 'locationId' => $toLocationId];
        }

        // 2. Call lockExistingSeededStateSetForTransferScopes exactly once
        $lockedStatesMap = $this->stateRepository->lockExistingSeededStateSetForTransferScopes(
            $propertyId,
            $requestedScopes
        );

        // 3. Process transfer lines in the pre-existing source-proven document line order
        foreach ($linesData as $line) {
            $itemId = $line['itemId'];
            $qtyStr = $line['quantityRequested'];

            $sourceKey = "property:{$propertyId}:location:{$fromLocationId}:item:{$itemId}";
            $destKey = "property:{$propertyId}:location:{$toLocationId}:item:{$itemId}";

            $lockedSourceState = $lockedStatesMap[$sourceKey] ?? null;
            $lockedDestState = $lockedStatesMap[$destKey] ?? null;

            if ($lockedSourceState === null || $lockedDestState === null) {
                throw new RuntimeException("Failed to find locked states in map for item {$itemId}.");
            }

            // Validate locked source state facts
            $qtyBefore = new AvcoDecimal((string) $lockedSourceState->on_hand_quantity);
            if ($qtyBefore->isZero() || $qtyBefore->isNegative()) {
                throw new RuntimeException("Cannot transfer from zero or negative stock.");
            }

            if ($lockedSourceState->weighted_average_unit_cost === null) {
                throw new RuntimeException("Weighted average unit cost is null for locked source state.");
            }

            $wac = new AvcoDecimal((string) $lockedSourceState->weighted_average_unit_cost);
            if ($wac->isZero() || $wac->isNegative()) {
                throw new RuntimeException("Weighted average unit cost is zero or negative for locked source state.");
            }

            // Validate sufficient quantity
            $reqQty = new AvcoDecimal(abs((float) $qtyStr));
            if ($qtyBefore->compareTo($reqQty) < 0) {
                throw new RuntimeException("Sufficient source quantity is not available.");
            }

            // Derive unit cost and total value
            $qtyOut = new AvcoDecimal((string) (-1 * abs((float) $qtyStr)));
            $valueOut = AvcoDecimal::zero()->sub($reqQty->mul($wac));

            $qtyIn = $reqQty;
            $valueIn = $reqQty->mul($wac);

            // Create canonical outbound transfer transaction
            $outIntent = new InventoryLedgerPostingIntent(
                propertyId: $propertyId,
                itemId: $itemId,
                locationId: $fromLocationId,
                businessDate: $documentData['businessDate'],
                occurredAt: $documentData['occurredAt'],
                sourceDocumentType: 'inventory_transfer',
                sourceDocumentId: $documentData['documentId'],
                sourceLineType: 'inventory_transfer_line',
                sourceLineId: $line['lineId'],
                movementRole: TransactionTypeEnum::TransferOut->value,
                idempotencyKey: $line['outboundIdempotencyKey'],
                transactionType: TransactionTypeEnum::TransferOut,
                quantityChange: $qtyOut->getValue(),
                unitCost: $wac->getValue(),
                totalCost: $valueOut->getValue(),
                reference: $documentData['reference'],
                notes: $documentData['notes'] ?? 'Inventory Transfer Posting'
            );

            $txOut = $this->postingCoordinator->post($outIntent, $actorId);

            // Create canonical inbound transfer transaction
            $inIntent = new InventoryLedgerPostingIntent(
                propertyId: $propertyId,
                itemId: $itemId,
                locationId: $toLocationId,
                businessDate: $documentData['businessDate'],
                occurredAt: $documentData['occurredAt'],
                sourceDocumentType: 'inventory_transfer',
                sourceDocumentId: $documentData['documentId'],
                sourceLineType: 'inventory_transfer_line',
                sourceLineId: $line['lineId'],
                movementRole: TransactionTypeEnum::TransferIn->value,
                idempotencyKey: $line['inboundIdempotencyKey'],
                transactionType: TransactionTypeEnum::TransferIn,
                quantityChange: $qtyIn->getValue(),
                unitCost: $wac->getValue(),
                totalCost: $valueIn->getValue(),
                reference: $documentData['reference'],
                notes: $documentData['notes'] ?? 'Inventory Transfer Posting'
            );

            $txIn = $this->postingCoordinator->post($inIntent, $actorId);

            // Reconstruct authoritative transfer intent from current locked state values and transaction facts
            $sourceSeq = null;
            if ($lockedSourceState->last_valuation_sequence !== null && $lockedSourceState->last_valuation_business_date !== null) {
                $sourceSeq = new ValuationSequence(
                    propertyId: $propertyId,
                    itemId: $itemId,
                    valuationScope: $lockedSourceState->valuation_scope,
                    businessDate: $lockedSourceState->last_valuation_business_date->format('Y-m-d'),
                    ledgerSequence: (int) $lockedSourceState->last_valuation_sequence
                );
            }

            $destSeq = null;
            if ($lockedDestState->last_valuation_sequence !== null && $lockedDestState->last_valuation_business_date !== null) {
                $destSeq = new ValuationSequence(
                    propertyId: $propertyId,
                    itemId: $itemId,
                    valuationScope: $lockedDestState->valuation_scope,
                    businessDate: $lockedDestState->last_valuation_business_date->format('Y-m-d'),
                    ledgerSequence: (int) $lockedDestState->last_valuation_sequence
                );
            }

            $outboundLedgerIntent = new ControlledValuationCostLedgerIntent(
                propertyId: $txOut->property_id,
                sourceInventoryTransactionId: $txOut->id,
                priorCostLedgerEntryId: null,
                entryType: 'transfer',
                idempotencyKey: $txOut->idempotency_key,
                entrySequence: (int) $txOut->valuation_sequence,
                currencyCode: $txOut->currency_code,
                quantityDelta: $qtyOut,
                unitCost: $wac,
                valueDelta: $valueOut,
                businessDate: $txOut->business_date->format('Y-m-d'),
                occurredAt: $txOut->occurred_at->format('Y-m-d H:i:s')
            );

            $inboundLedgerIntent = new ControlledValuationCostLedgerIntent(
                propertyId: $txIn->property_id,
                sourceInventoryTransactionId: $txIn->id,
                priorCostLedgerEntryId: null,
                entryType: 'transfer',
                idempotencyKey: $txIn->idempotency_key,
                entrySequence: (int) $txIn->valuation_sequence,
                currencyCode: $txIn->currency_code,
                quantityDelta: $qtyIn,
                unitCost: $wac,
                valueDelta: $valueIn,
                businessDate: $txIn->business_date->format('Y-m-d'),
                occurredAt: $txIn->occurred_at->format('Y-m-d H:i:s')
            );

            $requestedIntent = new ControlledTransferValuationIntent(
                propertyId: $propertyId,
                itemId: $itemId,
                sourceLocationId: $fromLocationId,
                destinationLocationId: $toLocationId,
                sourceCurrentLastValuationSequence: $sourceSeq,
                sourceCurrentQuantity: new AvcoDecimal((string) $lockedSourceState->on_hand_quantity),
                sourceCurrentCarryingValue: new AvcoDecimal((string) $lockedSourceState->carrying_value),
                destinationCurrentLastValuationSequence: $destSeq,
                destinationCurrentQuantity: new AvcoDecimal((string) $lockedDestState->on_hand_quantity),
                destinationCurrentCarryingValue: new AvcoDecimal((string) $lockedDestState->carrying_value),
                outboundIntent: $outboundLedgerIntent,
                inboundIntent: $inboundLedgerIntent
            );

            // Apply using the apply coordinator
            $this->applyCoordinator->applyUsingLockedStates(
                $lockedSourceState,
                $lockedDestState,
                $requestedIntent
            );
        }
    }
}
