<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use RuntimeException;

/**
 * final transfer apply coordinator to coordinate paired transfer valuation transitions.
 */
final class ControlledTransferValuationApplyCoordinator
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly ControlledTransferValuationPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter,
        private readonly CostLedgerRepository $costLedgerRepository,
    ) {}

    /** @return array{outbound:string,inbound:string} */
    public function applyDeferred(DeferredCostDeliveryEligibleContext $context): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if (! $context->requiresPairedApplication || $context->pairedLeg === null) {
            throw new RuntimeException('CC_P01E_TRANSFER_HANDLER_REQUIRES_COMPLETE_PAIR');
        }
        if ($context->sourceLeg->processingState !== 'PENDING'
            || $context->pairedLeg->processingState !== 'PENDING') {
            throw new RuntimeException('CC_P01E_TRANSFER_DISPOSITION_NOT_PENDING');
        }

        $sourceIds = [
            $context->sourceLeg->sourceInventoryTransactionId,
            $context->pairedLeg->sourceInventoryTransactionId,
        ];
        sort($sourceIds, SORT_STRING);
        $sources = InventoryTransaction::whereIn('id', $sourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($sources->count() !== 2) {
            throw new RuntimeException('CC_P01E_TRANSFER_PAIR_SOURCE_MISSING');
        }

        $outbound = $sources->first(fn (InventoryTransaction $source): bool => $source->transaction_type === TransactionTypeEnum::TransferOut);
        $inbound = $sources->first(fn (InventoryTransaction $source): bool => $source->transaction_type === TransactionTypeEnum::TransferIn);
        if ($outbound === null || $inbound === null) {
            throw new RuntimeException('CC_P01E_TRANSFER_PAIR_TYPE_CONTRADICTION');
        }
        $this->assertExactDeferredPair($context, $outbound, $inbound);

        [$sourceState, $destinationState] = $this->stateRepository->lockExistingSeededStatePair(
            $outbound->property_id,
            $outbound->item_id,
            $outbound->location_id,
            $inbound->location_id,
        );

        $outboundEquivalence = $this->costLedgerRepository->resolveInventoryTransaction($outbound, true);
        $inboundEquivalence = $this->costLedgerRepository->resolveInventoryTransaction($inbound, true);
        $outboundExact = $outboundEquivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT;
        $inboundExact = $inboundEquivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT;

        foreach ([$outboundEquivalence, $inboundEquivalence] as $equivalence) {
            if ($equivalence->status === CostLedgerSourceEquivalence::CONFLICTING_EFFECT) {
                throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_CONFLICT');
            }
            if ($equivalence->status === CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION) {
                throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION');
            }
        }

        if ($outboundExact xor $inboundExact) {
            throw new RuntimeException('TRANSFER_PARTIAL_MONETARY_EFFECT_CONTRADICTION');
        }
        if ($outboundExact && $inboundExact) {
            if ($sourceState->last_valuation_sequence === null
                || $sourceState->last_valuation_sequence < $outbound->valuation_sequence
                || $destinationState->last_valuation_sequence === null
                || $destinationState->last_valuation_sequence < $inbound->valuation_sequence) {
                throw new RuntimeException('COST_LEDGER_AVCO_STATE_DIVERGENCE');
            }

            return [
                'outbound' => (string) $outboundEquivalence->costLedgerEntryId,
                'inbound' => (string) $inboundEquivalence->costLedgerEntryId,
            ];
        }

        $outboundExpected = $sourceState->last_valuation_sequence === null
            ? 1
            : $sourceState->last_valuation_sequence + 1;
        $inboundExpected = $destinationState->last_valuation_sequence === null
            ? 1
            : $destinationState->last_valuation_sequence + 1;
        if ($outbound->valuation_sequence !== $outboundExpected
            || $inbound->valuation_sequence !== $inboundExpected) {
            throw new RuntimeException('CC_P01E_TRANSFER_STRICT_SEQUENCE_CHANGED_BEFORE_PLAN');
        }

        $requestedIntent = new ControlledTransferValuationIntent(
            propertyId: $outbound->property_id,
            itemId: $outbound->item_id,
            sourceLocationId: $outbound->location_id,
            destinationLocationId: $inbound->location_id,
            sourceCurrentLastValuationSequence: $this->priorSequence($sourceState),
            sourceCurrentQuantity: new AvcoDecimal((string) $sourceState->on_hand_quantity),
            sourceCurrentCarryingValue: new AvcoDecimal((string) $sourceState->carrying_value),
            destinationCurrentLastValuationSequence: $this->priorSequence($destinationState),
            destinationCurrentQuantity: new AvcoDecimal((string) $destinationState->on_hand_quantity),
            destinationCurrentCarryingValue: new AvcoDecimal((string) $destinationState->carrying_value),
            outboundIntent: $this->deferredTransferLedgerIntent($outbound),
            inboundIntent: $this->deferredTransferLedgerIntent($inbound),
        );

        $this->applyUsingLockedStates($sourceState, $destinationState, $requestedIntent);

        $outboundApplied = $this->costLedgerRepository->resolveInventoryTransaction($outbound, true);
        $inboundApplied = $this->costLedgerRepository->resolveInventoryTransaction($inbound, true);
        if ($outboundApplied->status !== CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT
            || $inboundApplied->status !== CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
            throw new RuntimeException('CC_P01E_TRANSFER_POST_APPLY_EQUIVALENCE_FAILED');
        }

        return [
            'outbound' => (string) $outboundApplied->costLedgerEntryId,
            'inbound' => (string) $inboundApplied->costLedgerEntryId,
        ];
    }

    /**
     * Coordinate and atomically apply a single approved controlled transfer pair.
     */
    public function apply(
        ControlledTransferValuationIntent $requestedIntent
    ): ControlledTransferValuationPlan {
        return DB::transaction(function () use ($requestedIntent) {
            // Lock states in canonical lock order
            [$sourceState, $destState] = $this->stateRepository->lockExistingSeededStatePair(
                $requestedIntent->propertyId,
                $requestedIntent->itemId,
                $requestedIntent->sourceLocationId,
                $requestedIntent->destinationLocationId
            );

            return $this->applyUsingLockedStates($sourceState, $destState, $requestedIntent);
        });
    }

    /**
     * Coordinate and atomically apply controlled valuation using already-locked states.
     */
    public function applyUsingLockedStates(
        CostAvcoState $lockedSourceState,
        CostAvcoState $lockedDestinationState,
        ControlledTransferValuationIntent $requestedIntent
    ): ControlledTransferValuationPlan {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('applyUsingLockedStates requires an active transaction.');
        }

        $propertyId = $requestedIntent->propertyId;
        $itemId = $requestedIntent->itemId;
        $sourceLoc = $requestedIntent->sourceLocationId;
        $destLoc = $requestedIntent->destinationLocationId;

        // 1. Validate scope matching
        if ($lockedSourceState->property_id !== $propertyId ||
            $lockedSourceState->location_id !== $sourceLoc ||
            $lockedSourceState->item_id !== $itemId) {
            throw new InvalidArgumentException('Source state scope mismatch.');
        }

        if ($lockedDestinationState->property_id !== $propertyId ||
            $lockedDestinationState->location_id !== $destLoc ||
            $lockedDestinationState->item_id !== $itemId) {
            throw new InvalidArgumentException('Destination state scope mismatch.');
        }

        // 2. Validate seed provenance
        if (empty($lockedSourceState->enrollment_group_id) || empty($lockedSourceState->enrollment_scope_snapshot_id) ||
            empty($lockedDestinationState->enrollment_group_id) || empty($lockedDestinationState->enrollment_scope_snapshot_id)) {
            throw new RuntimeException('Seed provenance missing for one or both locked states.');
        }

        // 3. Confirm both enrollment groups are ENROLLED
        $isSourceEnrolled = $this->enrollmentRepository->isEnrolledGroupForPropertyItem(
            $lockedSourceState->enrollment_group_id,
            $propertyId,
            $itemId
        );

        $isDestEnrolled = $this->enrollmentRepository->isEnrolledGroupForPropertyItem(
            $lockedDestinationState->enrollment_group_id,
            $propertyId,
            $itemId
        );

        if (! $isSourceEnrolled || ! $isDestEnrolled) {
            throw new RuntimeException('Source and/or destination scopes are not ENROLLED.');
        }

        // 4. Resolve and validate InventoryTransaction records using container
        $txRepo = app(InventoryTransactionRepository::class);

        $outboundTx = $txRepo->findById($requestedIntent->outboundIntent->sourceInventoryTransactionId);
        $inboundTx = $txRepo->findById($requestedIntent->inboundIntent->sourceInventoryTransactionId);

        if ($outboundTx === null || $inboundTx === null) {
            throw new RuntimeException('One or both inventory transaction records not found.');
        }

        // Validate outbound leg matching
        if ($outboundTx->property_id !== $propertyId ||
            $outboundTx->location_id !== $sourceLoc ||
            $outboundTx->item_id !== $itemId ||
            $outboundTx->transaction_type->value !== 'transfer_out' ||
            (int) $outboundTx->valuation_sequence !== $requestedIntent->outboundIntent->entrySequence ||
            $outboundTx->idempotency_key !== $requestedIntent->outboundIntent->idempotencyKey) {
            throw new InvalidArgumentException('Outbound transaction mismatch.');
        }

        $outboundQty = new AvcoDecimal((string) $outboundTx->quantity_change);
        if ($outboundQty->compareTo($requestedIntent->outboundIntent->quantityDelta) !== 0) {
            throw new InvalidArgumentException('Outbound quantity mismatch.');
        }

        $outboundUnitCost = new AvcoDecimal((string) $outboundTx->unit_cost);
        if ($outboundUnitCost->compareTo($requestedIntent->outboundIntent->unitCost) !== 0) {
            throw new InvalidArgumentException('Outbound unit cost mismatch.');
        }

        $outboundTotal = new AvcoDecimal((string) $outboundTx->total_cost);
        if ($outboundTotal->compareTo($requestedIntent->outboundIntent->valueDelta) !== 0) {
            throw new InvalidArgumentException('Outbound value mismatch.');
        }

        if ($outboundTx->business_date->format('Y-m-d') !== $requestedIntent->outboundIntent->businessDate ||
            $outboundTx->occurred_at->format('Y-m-d H:i:s') !== $requestedIntent->outboundIntent->occurredAt ||
            $outboundTx->currency_code !== $requestedIntent->outboundIntent->currencyCode) {
            throw new InvalidArgumentException('Outbound metadata mismatch.');
        }

        // Validate inbound leg matching
        if ($inboundTx->property_id !== $propertyId ||
            $inboundTx->location_id !== $destLoc ||
            $inboundTx->item_id !== $itemId ||
            $inboundTx->transaction_type->value !== 'transfer_in' ||
            (int) $inboundTx->valuation_sequence !== $requestedIntent->inboundIntent->entrySequence ||
            $inboundTx->idempotency_key !== $requestedIntent->inboundIntent->idempotencyKey) {
            throw new InvalidArgumentException('Inbound transaction mismatch.');
        }

        $inboundQty = new AvcoDecimal((string) $inboundTx->quantity_change);
        if ($inboundQty->compareTo($requestedIntent->inboundIntent->quantityDelta) !== 0) {
            throw new InvalidArgumentException('Inbound quantity mismatch.');
        }

        $inboundUnitCost = new AvcoDecimal((string) $inboundTx->unit_cost);
        if ($inboundUnitCost->compareTo($requestedIntent->inboundIntent->unitCost) !== 0) {
            throw new InvalidArgumentException('Inbound unit cost mismatch.');
        }

        $inboundTotal = new AvcoDecimal((string) $inboundTx->total_cost);
        if ($inboundTotal->compareTo($requestedIntent->inboundIntent->valueDelta) !== 0) {
            throw new InvalidArgumentException('Inbound value mismatch.');
        }

        if ($inboundTx->business_date->format('Y-m-d') !== $requestedIntent->inboundIntent->businessDate ||
            $inboundTx->occurred_at->format('Y-m-d H:i:s') !== $requestedIntent->inboundIntent->occurredAt ||
            $inboundTx->currency_code !== $requestedIntent->inboundIntent->currencyCode) {
            throw new InvalidArgumentException('Inbound metadata mismatch.');
        }

        // 5. Reconstruct the authoritative intent using locked state facts
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
        if ($lockedDestinationState->last_valuation_sequence !== null && $lockedDestinationState->last_valuation_business_date !== null) {
            $destSeq = new ValuationSequence(
                propertyId: $propertyId,
                itemId: $itemId,
                valuationScope: $lockedDestinationState->valuation_scope,
                businessDate: $lockedDestinationState->last_valuation_business_date->format('Y-m-d'),
                ledgerSequence: (int) $lockedDestinationState->last_valuation_sequence
            );
        }

        $authoritativeIntent = new ControlledTransferValuationIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            sourceLocationId: $sourceLoc,
            destinationLocationId: $destLoc,
            sourceCurrentLastValuationSequence: $sourceSeq,
            sourceCurrentQuantity: new AvcoDecimal((string) $lockedSourceState->on_hand_quantity),
            sourceCurrentCarryingValue: new AvcoDecimal((string) $lockedSourceState->carrying_value),
            destinationCurrentLastValuationSequence: $destSeq,
            destinationCurrentQuantity: new AvcoDecimal((string) $lockedDestinationState->on_hand_quantity),
            destinationCurrentCarryingValue: new AvcoDecimal((string) $lockedDestinationState->carrying_value),
            outboundIntent: $requestedIntent->outboundIntent,
            inboundIntent: $requestedIntent->inboundIntent
        );

        // 6. Plan the transition using the authoritative intent
        $plan = $this->planner->plan($authoritativeIntent);

        // 7. Append both Cost Ledger legs
        $this->ledgerAdapter->append($requestedIntent->outboundIntent);
        $this->ledgerAdapter->append($requestedIntent->inboundIntent);

        // 8. Persist both transitions
        $this->stateRepository->persistPairedTransferTransition($lockedSourceState, $lockedDestinationState, $plan);

        return $plan;
    }

    private function assertExactDeferredPair(
        DeferredCostDeliveryEligibleContext $context,
        InventoryTransaction $outbound,
        InventoryTransaction $inbound,
    ): void {
        $eligibleIds = [
            $context->sourceLeg->sourceInventoryTransactionId,
            $context->pairedLeg?->sourceInventoryTransactionId,
        ];
        $exact = in_array($outbound->id, $eligibleIds, true)
            && in_array($inbound->id, $eligibleIds, true)
            && $outbound->property_id === $context->propertyId
            && $inbound->property_id === $context->propertyId
            && $outbound->item_id === $context->itemId
            && $inbound->item_id === $context->itemId
            && $outbound->source_document_id === $inbound->source_document_id
            && $outbound->source_line_id === $inbound->source_line_id
            && $outbound->location_id !== $inbound->location_id
            && $outbound->currency_code === $inbound->currency_code
            && $outbound->business_date?->format('Y-m-d') === $inbound->business_date?->format('Y-m-d')
            && $outbound->occurred_at?->getTimestamp() === $inbound->occurred_at?->getTimestamp()
            && $outbound->cost_delivery_mode === 'DEFERRED'
            && $inbound->cost_delivery_mode === 'DEFERRED'
            && $outbound->cost_delivery_ownership_id === $context->ownershipId
            && $inbound->cost_delivery_ownership_id === $context->ownershipId
            && $outbound->cost_delivery_ownership_version === $context->ownershipVersion
            && $inbound->cost_delivery_ownership_version === $context->ownershipVersion
            && $outbound->cost_delivery_cutover_id === $context->cutoverId
            && $inbound->cost_delivery_cutover_id === $context->cutoverId
            && bccomp((string) $outbound->quantity_change, bcmul((string) $inbound->quantity_change, '-1', 4), 4) === 0
            && bccomp((string) $outbound->unit_cost, (string) $inbound->unit_cost, 4) === 0
            && bccomp((string) $outbound->total_cost, bcmul((string) $inbound->total_cost, '-1', 4), 4) === 0;
        if (! $exact) {
            throw new RuntimeException('CC_P01E_TRANSFER_PAIR_EVIDENCE_CONFLICT');
        }
    }

    private function priorSequence(CostAvcoState $state): ?ValuationSequence
    {
        if ($state->last_valuation_sequence === null) {
            if ($state->last_valuation_business_date !== null) {
                throw new RuntimeException('CC_P01E_AVCO_SEQUENCE_DATE_DIVERGENCE');
            }

            return null;
        }
        if ($state->last_valuation_business_date === null) {
            throw new RuntimeException('CC_P01E_AVCO_SEQUENCE_DATE_DIVERGENCE');
        }

        return new ValuationSequence(
            propertyId: $state->property_id,
            itemId: $state->item_id,
            valuationScope: $state->valuation_scope,
            businessDate: $state->last_valuation_business_date->format('Y-m-d'),
            ledgerSequence: $state->last_valuation_sequence,
        );
    }

    private function deferredTransferLedgerIntent(InventoryTransaction $source): ControlledValuationCostLedgerIntent
    {
        $idempotencyKey = trim((string) $source->idempotency_key);
        if ($idempotencyKey === '') {
            throw new RuntimeException('CC_P01E_SOURCE_IDEMPOTENCY_KEY_MISSING');
        }

        return new ControlledValuationCostLedgerIntent(
            propertyId: $source->property_id,
            sourceInventoryTransactionId: $source->id,
            priorCostLedgerEntryId: null,
            entryType: 'transfer',
            idempotencyKey: $idempotencyKey,
            entrySequence: $source->valuation_sequence,
            currencyCode: $source->currency_code,
            quantityDelta: new AvcoDecimal((string) $source->quantity_change),
            unitCost: new AvcoDecimal((string) $source->unit_cost),
            valueDelta: new AvcoDecimal((string) $source->total_cost),
            businessDate: $source->business_date->format('Y-m-d'),
            occurredAt: $source->occurred_at->format('Y-m-d H:i:s'),
        );
    }
}
