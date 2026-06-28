<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\ControlledTransferValuationPlanner;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\Models\CostAvcoState;

/**
 * final transfer apply coordinator to coordinate paired transfer valuation transitions.
 */
final class ControlledTransferValuationApplyCoordinator
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly ControlledTransferValuationPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter
    ) {}

    /**
     * Coordinate and atomically apply a single approved controlled transfer pair.
     *
     * @param ControlledTransferValuationIntent $requestedIntent
     * @return ControlledTransferValuationPlan
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
     *
     * @param CostAvcoState $lockedSourceState
     * @param CostAvcoState $lockedDestinationState
     * @param ControlledTransferValuationIntent $requestedIntent
     * @return ControlledTransferValuationPlan
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

        if (!$isSourceEnrolled || !$isDestEnrolled) {
            throw new RuntimeException('Source and/or destination scopes are not ENROLLED.');
        }

        // 4. Resolve and validate InventoryTransaction records using container
        $txRepo = app(\Modules\Operations\Inventory\Repositories\InventoryTransactionRepository::class);

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
}
