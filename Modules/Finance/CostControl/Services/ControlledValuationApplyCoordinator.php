<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\ControlledValuationStateTransitionPlanner;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionPlan;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

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
        private readonly InventoryTransactionRepository $transactionRepository
    ) {}

    /**
     * Coordinate and atomically apply a single approved controlled receipt valuation.
     *
     * @param string $propertyId
     * @param string $locationId
     * @param string $itemId
     * @param ControlledValuationCostLedgerIntent $costLedgerIntent
     * @return ControlledValuationStateTransitionPlan
     * @throws RuntimeException|InvalidArgumentException
     */
    public function apply(
        string $propertyId,
        string $locationId,
        string $itemId,
        ControlledValuationCostLedgerIntent $costLedgerIntent
    ): ControlledValuationStateTransitionPlan {
        return DB::transaction(function () use ($propertyId, $locationId, $itemId, $costLedgerIntent) {
            // 1. Lock the existing seeded CostAvcoState
            $lockedState = $this->stateRepository->lockExistingSeededStateForScope($propertyId, $locationId, $itemId);

            // 2. Confirm the state enrollment group status is ENROLLED
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

            // Enforce purchase receipt type
            if ($tx->transaction_type->value !== 'purchase_receipt') {
                throw new InvalidArgumentException('Transaction type is not purchase_receipt.');
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
        });
    }
}
