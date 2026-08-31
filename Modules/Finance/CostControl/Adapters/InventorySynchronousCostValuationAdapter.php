<?php

namespace Modules\Finance\CostControl\Adapters;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\Services\ControlledReversalValuationPlanner;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use RuntimeException;

final class InventorySynchronousCostValuationAdapter implements SynchronousCostValuationPort
{
    public function __construct(
        private readonly InventoryTransactionRepository $transactionRepository,
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly CostLedgerRepository $costLedgerRepository,
        private readonly ControlledReversalValuationPlanner $planner,
        private readonly ControlledValuationCostLedgerAdapter $ledgerAdapter,
    ) {}

    public function applyReversal(
        string $reversalInventoryTransactionId,
        string $originalInventoryTransactionId,
        string $reversalReason,
        string $approvalReference,
    ): string {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $source = $this->transactionRepository->findById($reversalInventoryTransactionId);
        $original = $this->transactionRepository->findById($originalInventoryTransactionId);

        if ($source === null || $original === null) {
            throw new RuntimeException('CC_P01D_SYNCHRONOUS_REVERSAL_SOURCE_MISSING');
        }

        $ownership = $this->ownershipRepository->findForUpdateByPropertyItem(
            $source->property_id,
            $source->item_id,
        );

        $this->assertExactSynchronousSource(
            $source,
            $original,
            $ownership,
            $reversalReason,
            $approvalReference,
        );

        $lockedState = $this->stateRepository->lockExistingSeededStateForScope(
            $source->property_id,
            $source->location_id,
            $source->item_id,
        );

        if ($lockedState->enrollment_group_id !== $ownership->enrollment_group_id) {
            throw new RuntimeException('CC_P01D_SYNCHRONOUS_SOURCE_ENROLLMENT_MISMATCH');
        }

        $controlledIntent = $this->controlledIntent(
            $source,
            $original,
            $reversalReason,
            $approvalReference,
        );
        $ledgerIntent = $this->ledgerIntent($controlledIntent);
        $sourceEquivalence = $this->costLedgerRepository->resolveIntent($ledgerIntent, true);

        if ($sourceEquivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
            return $sourceEquivalence->costLedgerEntryId;
        }

        if ($sourceEquivalence->status === CostLedgerSourceEquivalence::CONFLICTING_EFFECT) {
            throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_CONFLICT');
        }

        if ($sourceEquivalence->status === CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION) {
            throw new RuntimeException('CC_P01C_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION');
        }

        $priorSequence = null;
        if ($lockedState->last_valuation_sequence !== null
            && $lockedState->last_valuation_business_date !== null) {
            $priorSequence = new ValuationSequence(
                propertyId: $source->property_id,
                itemId: $source->item_id,
                valuationScope: $lockedState->valuation_scope,
                businessDate: $lockedState->last_valuation_business_date->format('Y-m-d'),
                ledgerSequence: (int) $lockedState->last_valuation_sequence,
            );
        }

        $transitionIntent = new ControlledValuationStateTransitionIntent(
            propertyId: $source->property_id,
            locationId: $source->location_id,
            itemId: $source->item_id,
            currentLastAppliedValuationSequence: $priorSequence,
            currentQuantity: new AvcoDecimal((string) $lockedState->on_hand_quantity),
            currentCarryingValue: new AvcoDecimal((string) $lockedState->carrying_value),
            costLedgerIntent: $controlledIntent,
        );

        $plan = $this->planner->plan($transitionIntent);
        $entry = $this->ledgerAdapter->append($controlledIntent);
        $this->stateRepository->persistPlannedReversalTransition($lockedState, $plan);

        return $entry->id;
    }

    private function assertExactSynchronousSource(
        InventoryTransaction $source,
        InventoryTransaction $original,
        ?CostDeliveryModeOwnership $ownership,
        string $reversalReason,
        string $approvalReference,
    ): void {
        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        $exact = $ownership !== null
            && $ownership->delivery_mode === CostDeliveryMode::Synchronous
            && $ownership->activated_cutover_id === null
            && $source->transaction_type === TransactionTypeEnum::Reversal
            && $source->reverses_inventory_transaction_id === $original->id
            && $source->property_id === $original->property_id
            && $source->item_id === $original->item_id
            && $source->location_id === $original->location_id
            && $source->valuation_scope === $expectedScope
            && $original->valuation_scope === $expectedScope
            && $source->cost_delivery_mode === CostDeliveryMode::Synchronous->value
            && $source->cost_delivery_ownership_id === $ownership->id
            && $source->cost_delivery_ownership_version === $ownership->ownership_version
            && $source->cost_delivery_cutover_id === null
            && $source->valuation_approval_reference === $approvalReference
            && trim($reversalReason) !== ''
            && $source->currency_code === $original->currency_code
            && bccomp((string) $source->quantity_change, bcmul((string) $original->quantity_change, '-1', 4), 4) === 0
            && bccomp((string) $source->unit_cost, (string) $original->unit_cost, 4) === 0
            && bccomp((string) $source->total_cost, bcmul((string) $original->total_cost, '-1', 2), 4) === 0;

        if (! $exact) {
            throw new RuntimeException('CC_P01D_SYNCHRONOUS_SOURCE_STAMP_INVALID');
        }
    }

    private function controlledIntent(
        InventoryTransaction $source,
        InventoryTransaction $original,
        string $reversalReason,
        string $approvalReference,
    ): ControlledValuationCostLedgerIntent {
        return new ControlledValuationCostLedgerIntent(
            propertyId: $source->property_id,
            sourceInventoryTransactionId: $source->id,
            priorCostLedgerEntryId: null,
            entryType: 'reversal',
            idempotencyKey: "reversal_ledger:{$source->id}",
            entrySequence: $source->valuation_sequence,
            currencyCode: $source->currency_code,
            quantityDelta: new AvcoDecimal((string) $source->quantity_change),
            unitCost: new AvcoDecimal((string) $source->unit_cost),
            valueDelta: new AvcoDecimal((string) $source->total_cost),
            businessDate: $source->business_date->format('Y-m-d'),
            occurredAt: $source->occurred_at->format('Y-m-d H:i:s'),
            originalBusinessDate: $original->business_date->format('Y-m-d'),
            metadata: [
                'original_transaction_id' => $original->id,
                'reversal_reason' => $reversalReason,
                'approval_reference' => $approvalReference,
            ],
        );
    }

    private function ledgerIntent(ControlledValuationCostLedgerIntent $intent): CostLedgerEntryIntent
    {
        return new CostLedgerEntryIntent(
            $intent->propertyId,
            $intent->sourceInventoryTransactionId,
            $intent->priorCostLedgerEntryId,
            $intent->entryType,
            $intent->idempotencyKey,
            $intent->entrySequence,
            $intent->currencyCode,
            $intent->quantityDelta,
            $intent->unitCost,
            $intent->valueDelta,
            $intent->businessDate,
            $intent->occurredAt,
            $intent->originalBusinessDate,
            $intent->metadata,
        );
    }
}
