<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationPlanner;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\Models\CostAvcoState;

/**
 * Coordinate and atomically apply controlled adjustment valuations.
 */
final class ControlledAdjustmentValuationApplyCoordinator
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly InventoryTransactionRepository $transactionRepository,
        private readonly ControlledAdjustmentValuationPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter
    ) {}

    /**
     * Coordinate and atomically apply a single approved controlled adjustment valuation.
     */
    public function apply(
        ControlledAdjustmentValuationIntent $requestedIntent
    ): ControlledAdjustmentValuationPlan {
        return DB::transaction(function () use ($requestedIntent) {
            $lockedState = $this->stateRepository->lockExistingSeededStateForScope(
                $requestedIntent->propertyId,
                $requestedIntent->locationId,
                $requestedIntent->itemId
            );

            return $this->applyUsingLockedState($lockedState, $requestedIntent);
        });
    }

    /**
     * Coordinate and apply controlled adjustment valuation using an already-locked CostAvcoState.
     */
    public function applyUsingLockedState(
        CostAvcoState $lockedState,
        ControlledAdjustmentValuationIntent $requestedIntent
    ): ControlledAdjustmentValuationPlan {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('applyUsingLockedState requires an active transaction.');
        }

        $propertyId = $lockedState->property_id;
        $locationId = $lockedState->location_id;
        $itemId = $lockedState->item_id;

        // 1. Confirm canonical scope and seeded provenance of locked state
        $expectedScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
        if ($lockedState->valuation_scope !== $expectedScope) {
            throw new InvalidArgumentException('Valuation scope mismatch on locked state.');
        }

        if (empty($lockedState->enrollment_group_id) || empty($lockedState->enrollment_scope_snapshot_id)) {
            throw new RuntimeException(
                sprintf('Seed provenance missing for CostAvcoState on scope "%s".', $lockedState->valuation_scope)
            );
        }

        // 2. Verify exact active ENROLLED authority for the locked state
        $isEnrolled = $this->enrollmentRepository->isEnrolledGroupForPropertyItem(
            $lockedState->enrollment_group_id,
            $propertyId,
            $itemId
        );

        if (!$isEnrolled) {
            throw new RuntimeException(
                sprintf('Enrollment group "%s" is not ENROLLED.', $lockedState->enrollment_group_id)
            );
        }

        $costLedgerIntent = $requestedIntent->costLedgerIntent;

        // 3. Reject stale caller snapshot details before any write
        if ($requestedIntent->propertyId !== $propertyId ||
            $requestedIntent->locationId !== $locationId ||
            $requestedIntent->itemId !== $itemId) {
            throw new InvalidArgumentException('Scope facts mismatch between intent and locked state.');
        }

        // 4. Resolve and validate the immutable InventoryTransaction corresponding to the original Cost Ledger intent
        $tx = $this->transactionRepository->findById($costLedgerIntent->sourceInventoryTransactionId);
        if ($tx === null) {
            throw new RuntimeException(
                sprintf('InventoryTransaction "%s" not found.', $costLedgerIntent->sourceInventoryTransactionId)
            );
        }

        // 5. Validate exact transaction evidence matchups
        if ($tx->property_id !== $propertyId || $tx->property_id !== $costLedgerIntent->propertyId) {
            throw new InvalidArgumentException('Property mismatch on transaction evidence.');
        }

        if ($tx->location_id !== $locationId) {
            throw new InvalidArgumentException('Location mismatch on transaction evidence.');
        }

        if ($tx->item_id !== $itemId) {
            throw new InvalidArgumentException('Item mismatch on transaction evidence.');
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

        // Direction-specific validation
        if ($costLedgerIntent->quantityDelta->isPositive()) {
            // AdjustmentIn
            if ($tx->transaction_type->value !== 'adjustment_in') {
                throw new InvalidArgumentException('Transaction type is not adjustment_in for AdjustmentIn entry.');
            }
            if ($costLedgerIntent->valueDelta->isNegative()) {
                throw new InvalidArgumentException('Positive adjustment value delta cannot be negative.');
            }
            if (!$costLedgerIntent->unitCost->isPositive()) {
                throw new InvalidArgumentException('Positive adjustment unit cost must be positive.');
            }
        } else {
            // AdjustmentOut
            if ($tx->transaction_type->value !== 'adjustment_out') {
                throw new InvalidArgumentException('Transaction type is not adjustment_out for AdjustmentOut entry.');
            }

            if ($lockedState->weighted_average_unit_cost === null) {
                throw new RuntimeException('Locked state weighted_average_unit_cost is null on AdjustmentOut.');
            }
            $currentWauc = new AvcoDecimal((string) $lockedState->weighted_average_unit_cost);
            if ($costLedgerIntent->unitCost->compareTo($currentWauc) !== 0) {
                throw new InvalidArgumentException('AdjustmentOut unit cost must match locked state WAUC.');
            }
            $expectedValueDelta = $costLedgerIntent->quantityDelta->mul($currentWauc);
            if ($costLedgerIntent->valueDelta->compareTo($expectedValueDelta) !== 0) {
                throw new InvalidArgumentException('AdjustmentOut value delta must match quantity * current WAUC.');
            }
        }

        // 6. Reconstruct authoritative ControlledAdjustmentValuationIntent from lockedState and costLedgerIntent
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

        $authoritativeIntent = new ControlledAdjustmentValuationIntent(
            propertyId: $propertyId,
            locationId: $locationId,
            itemId: $itemId,
            currentLastAppliedValuationSequence: $priorSequence,
            currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
            currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
            costLedgerIntent: $costLedgerIntent
        );

        // 7. Plan the transition
        $plan = $this->planner->plan($authoritativeIntent);

        // 8. Append the ledger entry
        $this->ledgerAdapter->append($costLedgerIntent);

        // 9. Persist the updated state
        $this->stateRepository->persistPlannedAdjustmentTransition($lockedState, $plan);

        // 10. Return the plan
        return $plan;
    }
}
