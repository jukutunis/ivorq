<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionPlan;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use RuntimeException;

/**
 * Atomic apply coordinator that coordinates checking enrollment status,
 * validating receipt evidence against inventory transaction facts, running
 * the state transition planner, appending to the Cost Ledger, and updating
 * the locked CostAvcoState row.
 */
class ControlledValuationApplyCoordinator
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly ControlledValuationStateTransitionPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter,
        private readonly InventoryTransactionRepository $transactionRepository,
        private readonly ControlledAdjustmentValuationPlanner $adjustmentPlanner,
        private readonly ControlledReversalValuationPlanner $reversalPlanner,
        private readonly CostLedgerRepository $costLedgerRepository,
    ) {}

    public function applyDeferred(DeferredCostDeliveryEligibleContext $context): string
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if ($context->requiresPairedApplication) {
            throw new RuntimeException('CC_P01E_SINGLE_HANDLER_RECEIVED_TRANSFER_PAIR');
        }
        if ($context->processingState !== 'PENDING') {
            throw new RuntimeException('CC_P01E_SINGLE_DISPOSITION_NOT_PENDING');
        }

        $source = InventoryTransaction::whereKey($context->sourceInventoryTransactionId)
            ->lockForUpdate()
            ->first();
        if ($source === null) {
            throw new RuntimeException('CC_P01E_INVENTORY_SOURCE_NOT_FOUND');
        }

        $lockedState = $this->stateRepository->lockExistingSeededStateForScope(
            $context->propertyId,
            $context->locationId,
            $context->itemId,
        );
        $this->assertDeferredContextMatchesSourceAndState($context, $source, $lockedState);

        $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
        if ($equivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
            if ($lockedState->last_valuation_sequence === null
                || $lockedState->last_valuation_sequence < $source->valuation_sequence) {
                throw new RuntimeException('COST_LEDGER_AVCO_STATE_DIVERGENCE');
            }

            return (string) $equivalence->costLedgerEntryId;
        }
        if ($equivalence->status === CostLedgerSourceEquivalence::CONFLICTING_EFFECT) {
            throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_CONFLICT');
        }
        if ($equivalence->status === CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION) {
            throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION');
        }

        $expectedSequence = $lockedState->last_valuation_sequence === null
            ? 1
            : $lockedState->last_valuation_sequence + 1;
        if ($source->valuation_sequence !== $expectedSequence
            || $context->expectedSequence !== $expectedSequence) {
            throw new RuntimeException('CC_P01E_STRICT_SEQUENCE_CHANGED_BEFORE_PLAN');
        }

        $costLedgerIntent = $this->deferredCostLedgerIntent($source);
        $priorSequence = $this->priorSequence($lockedState);
        $transactionType = $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);

        if (in_array($transactionType, [TransactionTypeEnum::PurchaseReceipt, TransactionTypeEnum::Issue], true)) {
            $transitionIntent = new ControlledValuationStateTransitionIntent(
                propertyId: $source->property_id,
                locationId: $source->location_id,
                itemId: $source->item_id,
                currentLastAppliedValuationSequence: $priorSequence,
                currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
                currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
                costLedgerIntent: $costLedgerIntent,
            );
            $plan = $this->planner->plan($transitionIntent);
            $entry = $this->ledgerAdapter->append($costLedgerIntent);
            $this->stateRepository->persistPlannedControlledTransition($lockedState, $plan);

            return $entry->id;
        }

        if (in_array($transactionType, [TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut], true)) {
            $adjustmentIntent = new ControlledAdjustmentValuationIntent(
                propertyId: $source->property_id,
                locationId: $source->location_id,
                itemId: $source->item_id,
                currentLastAppliedValuationSequence: $priorSequence,
                currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
                currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
                costLedgerIntent: $costLedgerIntent,
            );
            $plan = $this->adjustmentPlanner->plan($adjustmentIntent);
            $entry = $this->ledgerAdapter->append($costLedgerIntent);
            $this->stateRepository->persistPlannedAdjustmentTransition($lockedState, $plan);

            return $entry->id;
        }

        if ($transactionType === TransactionTypeEnum::Reversal) {
            $transitionIntent = new ControlledValuationStateTransitionIntent(
                propertyId: $source->property_id,
                locationId: $source->location_id,
                itemId: $source->item_id,
                currentLastAppliedValuationSequence: $priorSequence,
                currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
                currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
                costLedgerIntent: $costLedgerIntent,
            );
            $plan = $this->reversalPlanner->plan($transitionIntent);
            $entry = $this->ledgerAdapter->append($costLedgerIntent);
            $this->stateRepository->persistPlannedReversalTransition($lockedState, $plan);

            return $entry->id;
        }

        throw new RuntimeException('CC_P01E_SINGLE_TRANSACTION_TYPE_UNSUPPORTED');
    }

    /**
     * Coordinate and atomically apply a single approved controlled receipt valuation.
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public function apply(
        string $propertyId,
        string $locationId,
        string $itemId,
        ControlledValuationCostLedgerIntent $costLedgerIntent
    ): ControlledValuationStateTransitionPlan {
        return DB::transaction(function () use ($propertyId, $locationId, $itemId, $costLedgerIntent) {
            $lockedState = $this->stateRepository->lockExistingSeededStateForScope($propertyId, $locationId, $itemId);

            return $this->applyUsingLockedState($lockedState, $costLedgerIntent);
        });
    }

    /**
     * Coordinate and atomically apply controlled valuation using an already-locked CostAvcoState.
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public function applyUsingLockedState(
        CostAvcoState $lockedState,
        ControlledValuationCostLedgerIntent $costLedgerIntent
    ): ControlledValuationStateTransitionPlan {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('applyUsingLockedState requires an active transaction.');
        }

        $propertyId = $lockedState->property_id;
        $locationId = $lockedState->location_id;
        $itemId = $lockedState->item_id;

        // Confirm seed provenance
        if (empty($lockedState->enrollment_group_id) || empty($lockedState->enrollment_scope_snapshot_id)) {
            throw new RuntimeException(
                sprintf('Seed provenance missing for CostAvcoState on scope "%s".', $lockedState->valuation_scope)
            );
        }

        // Confirm the state enrollment group status is ENROLLED
        $isEnrolled = $this->enrollmentRepository->isEnrolledGroupForPropertyItem(
            $lockedState->enrollment_group_id,
            $propertyId,
            $itemId
        );

        if (! $isEnrolled) {
            throw new RuntimeException(
                sprintf('Enrollment group "%s" is not ENROLLED.', $lockedState->enrollment_group_id)
            );
        }

        // 3. Resolve the immutable InventoryTransaction
        $tx = $this->transactionRepository->findById($costLedgerIntent->sourceInventoryTransactionId);
        if ($tx === null) {
            throw new RuntimeException(
                sprintf('InventoryTransaction "%s" not found.', $costLedgerIntent->sourceInventoryTransactionId)
            );
        }

        // 4. Validate exact transaction evidence matchups
        if ($tx->property_id !== $propertyId || $tx->property_id !== $costLedgerIntent->propertyId) {
            throw new InvalidArgumentException('Property mismatch on transaction evidence.');
        }

        if ($tx->location_id !== $locationId) {
            throw new InvalidArgumentException('Location mismatch on transaction evidence.');
        }

        if ($tx->item_id !== $itemId) {
            throw new InvalidArgumentException('Item mismatch on transaction evidence.');
        }

        // Enforce correct transaction type
        if ($costLedgerIntent->entryType === 'receipt') {
            if ($tx->transaction_type->value !== 'purchase_receipt') {
                throw new InvalidArgumentException('Transaction type is not purchase_receipt for receipt entry.');
            }
        } elseif ($costLedgerIntent->entryType === 'issue') {
            if ($tx->transaction_type->value !== 'issue') {
                throw new InvalidArgumentException('Transaction type is not issue for issue entry.');
            }
        } else {
            throw new InvalidArgumentException(sprintf('Unsupported entry type "%s".', $costLedgerIntent->entryType));
        }

        if ($tx->valuation_sequence !== $costLedgerIntent->entrySequence) {
            throw new InvalidArgumentException('Valuation sequence mismatch on transaction evidence.');
        }

        if ($tx->id !== $costLedgerIntent->sourceInventoryTransactionId) {
            throw new InvalidArgumentException('Source transaction ID mismatch on transaction evidence.');
        }

        if ($tx->valuation_scope !== $lockedState->valuation_scope) {
            throw new InvalidArgumentException('Valuation scope mismatch on transaction evidence.');
        }

        $txQty = new AvcoDecimal((string) $tx->quantity_change);
        if ($txQty->compareTo($costLedgerIntent->quantityDelta) !== 0) {
            throw new InvalidArgumentException('Quantity delta mismatch on transaction evidence.');
        }

        $txUnitCost = new AvcoDecimal((string) $tx->unit_cost);
        if ($txUnitCost->compareTo($costLedgerIntent->unitCost) !== 0) {
            throw new InvalidArgumentException('Unit cost mismatch on transaction evidence.');
        }

        $txTotalCost = new AvcoDecimal((string) $tx->total_cost);
        if ($txTotalCost->compareTo($costLedgerIntent->valueDelta) !== 0) {
            throw new InvalidArgumentException('Value delta mismatch on transaction evidence.');
        }

        $txBusinessDate = $tx->business_date->format('Y-m-d');
        if ($txBusinessDate !== $costLedgerIntent->businessDate) {
            throw new InvalidArgumentException('Business date mismatch on transaction evidence.');
        }

        if ($tx->occurred_at->getTimestamp() !== strtotime($costLedgerIntent->occurredAt)) {
            throw new InvalidArgumentException('Occurred at timestamp mismatch on transaction evidence.');
        }

        if ($tx->currency_code !== $costLedgerIntent->currencyCode) {
            throw new InvalidArgumentException('Currency code mismatch on transaction evidence.');
        }

        // 5. Construct ControlledValuationStateTransitionIntent
        $priorSequence = null;
        if ($lockedState->last_valuation_sequence !== null && $lockedState->last_valuation_business_date !== null) {
            $priorSequence = new ValuationSequence(
                propertyId: $propertyId,
                itemId: $itemId,
                valuationScope: $lockedState->valuation_scope,
                businessDate: $lockedState->last_valuation_business_date->format('Y-m-d'),
                ledgerSequence: (int) $lockedState->last_valuation_sequence
            );
        }

        $transitionIntent = new ControlledValuationStateTransitionIntent(
            propertyId: $propertyId,
            locationId: $locationId,
            itemId: $itemId,
            currentLastAppliedValuationSequence: $priorSequence,
            currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
            currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
            costLedgerIntent: $costLedgerIntent
        );

        // 6. Plan the transition
        $plan = $this->planner->plan($transitionIntent);

        // 7. Append the ledger entry
        $this->ledgerAdapter->append($costLedgerIntent);

        // 8. Persist the updated state
        $this->stateRepository->persistPlannedControlledTransition($lockedState, $plan);

        // 9. Return the plan
        return $plan;
    }

    private function assertDeferredContextMatchesSourceAndState(
        DeferredCostDeliveryEligibleContext $context,
        InventoryTransaction $source,
        CostAvcoState $lockedState,
    ): void {
        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($source->id !== $context->sourceInventoryTransactionId
            || $source->property_id !== $context->propertyId
            || $source->location_id !== $context->locationId
            || $source->item_id !== $context->itemId
            || $source->valuation_scope !== $context->valuationScope
            || $source->valuation_scope !== $expectedScope
            || $source->valuation_sequence !== $context->valuationSequence
            || $source->cost_delivery_mode !== 'DEFERRED'
            || $source->cost_delivery_ownership_id !== $context->ownershipId
            || $source->cost_delivery_ownership_version !== $context->ownershipVersion
            || $source->cost_delivery_cutover_id !== $context->cutoverId
            || $lockedState->property_id !== $context->propertyId
            || $lockedState->location_id !== $context->locationId
            || $lockedState->item_id !== $context->itemId
            || $lockedState->valuation_scope !== $context->valuationScope) {
            throw new RuntimeException('CC_P01E_SINGLE_ELIGIBLE_CONTEXT_CHANGED');
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

    private function deferredCostLedgerIntent(InventoryTransaction $source): ControlledValuationCostLedgerIntent
    {
        $transactionType = $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
        $entryType = match ($transactionType) {
            TransactionTypeEnum::PurchaseReceipt => 'receipt',
            TransactionTypeEnum::Issue => 'issue',
            TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
            TransactionTypeEnum::Reversal => 'reversal',
            default => throw new RuntimeException('CC_P01E_SINGLE_TRANSACTION_TYPE_UNSUPPORTED'),
        };
        $idempotencyKey = $transactionType === TransactionTypeEnum::Reversal
            ? "reversal_ledger:{$source->id}"
            : trim((string) $source->idempotency_key);
        if ($idempotencyKey === '') {
            throw new RuntimeException('CC_P01E_SOURCE_IDEMPOTENCY_KEY_MISSING');
        }

        $originalBusinessDate = null;
        $metadata = null;
        if ($transactionType === TransactionTypeEnum::Reversal) {
            [$originalBusinessDate, $metadata] = $this->deferredReversalEvidence($source);
        }

        return new ControlledValuationCostLedgerIntent(
            propertyId: $source->property_id,
            sourceInventoryTransactionId: $source->id,
            priorCostLedgerEntryId: null,
            entryType: $entryType,
            idempotencyKey: $idempotencyKey,
            entrySequence: $source->valuation_sequence,
            currencyCode: $source->currency_code,
            quantityDelta: new AvcoDecimal((string) $source->quantity_change),
            unitCost: new AvcoDecimal((string) $source->unit_cost),
            valueDelta: new AvcoDecimal((string) $source->total_cost),
            businessDate: $source->business_date->format('Y-m-d'),
            occurredAt: $source->occurred_at->format('Y-m-d H:i:s'),
            originalBusinessDate: $originalBusinessDate,
            metadata: $metadata,
        );
    }

    /** @return array{0:string,1:array<string,string>} */
    private function deferredReversalEvidence(InventoryTransaction $source): array
    {
        $original = InventoryTransaction::find($source->reverses_inventory_transaction_id);
        $auditRows = AuditLog::where('auditable_type', $source->getMorphClass())
            ->where('auditable_id', $source->id)
            ->where('event', 'reversal')
            ->orderBy('id')
            ->get();
        if ($original === null || $auditRows->count() !== 1) {
            throw new RuntimeException('CC_P01E_REVERSAL_PROVENANCE_MISSING');
        }

        $audit = $auditRows->first();
        $values = is_array($audit->new_values) ? $audit->new_values : [];
        $reason = $values['reversal_reason'] ?? null;
        $approvalReference = $values['approval_reference'] ?? null;
        $exact = $source->transaction_type === TransactionTypeEnum::Reversal
            && $source->reverses_inventory_transaction_id === $original->id
            && $source->property_id === $original->property_id
            && $source->item_id === $original->item_id
            && $source->location_id === $original->location_id
            && $source->valuation_scope === $original->valuation_scope
            && $source->currency_code === $original->currency_code
            && $source->valuation_approval_reference === $approvalReference
            && $audit->property_id === $source->property_id
            && ($values['original_transaction_id'] ?? null) === $original->id
            && is_string($reason)
            && trim($reason) !== ''
            && is_string($approvalReference)
            && trim($approvalReference) !== ''
            && bccomp((string) $source->quantity_change, bcmul((string) $original->quantity_change, '-1', 4), 4) === 0
            && bccomp((string) $source->unit_cost, (string) $original->unit_cost, 4) === 0
            && bccomp((string) $source->total_cost, bcmul((string) $original->total_cost, '-1', 2), 4) === 0;
        if (! $exact) {
            throw new RuntimeException('CC_P01E_REVERSAL_SOURCE_EVIDENCE_CONFLICT');
        }

        return [
            $original->business_date->format('Y-m-d'),
            [
                'original_transaction_id' => $original->id,
                'reversal_reason' => $reason,
                'approval_reference' => $approvalReference,
            ],
        ];
    }
}
