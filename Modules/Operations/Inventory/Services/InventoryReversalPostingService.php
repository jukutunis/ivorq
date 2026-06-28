<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Repositories\InventoryValuationSequenceRepository;
use Modules\Operations\Inventory\Exceptions\InventoryReversalPostingRejectedException;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingResult;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\Services\ControlledReversalValuationPlanner;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;

class InventoryReversalPostingService
{
    public function __construct(
        private readonly InventoryTransactionRepository $transactionRepo,
        private readonly InventoryValuationSequenceRepository $sequenceRepo,
        private readonly InventoryReversalCandidateGuard $candidateGuard,
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly ControlledReversalValuationPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter,
        private readonly AuditService $auditService
    ) {}

    public function post(InventoryReversalPostingIntent $intent): InventoryReversalPostingResult
    {
        return DB::transaction(function () use ($intent) {
            // 1. Lock and validate the candidate original transaction
            $original = $this->candidateGuard->guard($intent->originalTransactionId);

            // 2. Enforce the current-open Business Date and Financial Period control convention
            $businessDate = PropertyBusinessDate::where('property_id', $original->property_id)
                ->where('status', PropertyBusinessDateStatusEnum::Open)
                ->where('is_open', true)
                ->first();

            if (!$businessDate) {
                throw new InventoryReversalPostingRejectedException(
                    'closed_business_date',
                    'No open business date found for property.'
                );
            }

            try {
                [$dbDate, $period] = $this->postingCoordinator->lockContext(
                    $original->property_id,
                    $businessDate->business_date->format('Y-m-d'),
                    now()
                );
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Business date')) {
                    throw new InventoryReversalPostingRejectedException('closed_business_date', $e->getMessage());
                }
                if (str_contains($e->getMessage(), 'Financial period')) {
                    throw new InventoryReversalPostingRejectedException('closed_financial_period', $e->getMessage());
                }
                throw new InventoryReversalPostingRejectedException('closed_business_date', $e->getMessage());
            }

            // 3. Enforce same-scope later-controlled-movement blocker
            $laterMovementExists = InventoryTransaction::where('property_id', $original->property_id)
                ->where('location_id', $original->location_id)
                ->where('item_id', $original->item_id)
                ->where('valuation_sequence', '>', $original->valuation_sequence)
                ->exists();

            if ($laterMovementExists) {
                throw new InventoryReversalPostingRejectedException(
                    'later_movement_exists',
                    'A later controlled movement exists in this valuation scope.'
                );
            }

            // 4. Lock/verify the current CostAvcoState
            $lockedState = $this->stateRepository->lockExistingSeededStateForScope(
                $original->property_id,
                $original->location_id,
                $original->item_id
            );

            if (empty($lockedState->enrollment_group_id) || empty($lockedState->enrollment_scope_snapshot_id)) {
                throw new InventoryReversalPostingRejectedException(
                    'missing_enrollment',
                    'Seed provenance missing for CostAvcoState.'
                );
            }

            $isEnrolled = $this->enrollmentRepository->isEnrolledGroupForPropertyItem(
                $lockedState->enrollment_group_id,
                $original->property_id,
                $original->item_id
            );

            if (!$isEnrolled) {
                throw new InventoryReversalPostingRejectedException(
                    'not_enrolled',
                    'Enrollment group is not ENROLLED.'
                );
            }

            // 5. Check if the original transaction itself has missing evidence
            if (
                empty($original->property_id) ||
                empty($original->location_id) ||
                empty($original->item_id) ||
                empty($original->valuation_scope) ||
                $original->valuation_sequence === null
            ) {
                throw new InventoryReversalPostingRejectedException(
                    'candidate_missing_controlled_evidence',
                    'Required controlled valuation evidence is absent.'
                );
            }

            // 6. Allocate new sequence
            $newValuationSequence = $this->sequenceRepo->allocateNext(
                $original->property_id,
                $original->location_id,
                $original->item_id
            );

            // 7. Calculate quantity changes
            $quantityBefore = (string) $lockedState->on_hand_quantity;
            $reversalQuantityChange = bcmul((string) $original->quantity_change, '-1', 4);
            $quantityAfter = bcadd($quantityBefore, $reversalQuantityChange, 4);

            // 8. Create reversal transaction
            $reversal = $this->transactionRepo->appendReversal(
                $original,
                $businessDate->business_date->format('Y-m-d'),
                $period->id,
                $newValuationSequence,
                $quantityBefore,
                $quantityAfter,
                $intent->approvalReference,
                $intent->idempotencyKey,
                $intent->actorId
            );

            // 9. Build transition intents
            $ledgerIntent = new ControlledValuationCostLedgerIntent(
                propertyId: $original->property_id,
                sourceInventoryTransactionId: $reversal->id,
                priorCostLedgerEntryId: null,
                entryType: 'reversal',
                idempotencyKey: "reversal_ledger:{$reversal->id}",
                entrySequence: $newValuationSequence,
                currencyCode: $original->currency_code,
                quantityDelta: new AvcoDecimal($reversalQuantityChange),
                unitCost: new AvcoDecimal((string) $original->unit_cost),
                valueDelta: new AvcoDecimal(bcmul((string) $original->total_cost, '-1', 2)),
                businessDate: $reversal->business_date->format('Y-m-d'),
                occurredAt: $reversal->occurred_at->format('Y-m-d H:i:s'),
                originalBusinessDate: $original->business_date->format('Y-m-d'),
                metadata: [
                    'original_transaction_id' => $original->id,
                    'reversal_reason' => $intent->reversalReason,
                    'approval_reference' => $intent->approvalReference,
                ]
            );

            $priorSequence = null;
            if ($lockedState->last_valuation_sequence !== null && $lockedState->last_valuation_business_date !== null) {
                $priorSequence = new ValuationSequence(
                    propertyId: $original->property_id,
                    itemId: $original->item_id,
                    valuationScope: $lockedState->valuation_scope,
                    businessDate: $lockedState->last_valuation_business_date->format('Y-m-d'),
                    ledgerSequence: (int) $lockedState->last_valuation_sequence
                );
            }

            $transitionIntent = new ControlledValuationStateTransitionIntent(
                propertyId: $original->property_id,
                locationId: $original->location_id,
                itemId: $original->item_id,
                currentLastAppliedValuationSequence: $priorSequence,
                currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
                currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
                costLedgerIntent: $ledgerIntent
            );

            // 10. Plan transition
            $plan = $this->planner->plan($transitionIntent);

            // 11. Append ledger entry
            $costLedgerEntry = $this->ledgerAdapter->append($ledgerIntent);

            // 12. Persist transition to CostAvcoState
            $this->stateRepository->persistPlannedReversalTransition($lockedState, $plan);

            // 13. Audit log
            $auditLog = $this->auditService->log(
                'reversal',
                $reversal,
                [],
                [
                    'original_transaction_id' => $original->id,
                    'reversal_reason' => $intent->reversalReason,
                    'approval_reference' => $intent->approvalReference,
                    'actor_id' => $intent->actorId,
                    'quantity_change' => (string) $reversal->quantity_change,
                    'total_cost' => (string) $reversal->total_cost,
                ],
                ['reversal']
            );

            return new InventoryReversalPostingResult(
                originalTransaction: $original,
                reversalTransaction: $reversal,
                costLedgerEntry: $costLedgerEntry,
                auditLog: $auditLog
            );
        });
    }
}
