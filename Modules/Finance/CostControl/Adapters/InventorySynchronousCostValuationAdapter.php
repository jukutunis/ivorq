<?php

namespace Modules\Finance\CostControl\Adapters;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationApplyCoordinator;
use Modules\Finance\CostControl\Services\ControlledReversalValuationPlanner;
use Modules\Finance\CostControl\Services\ControlledTransferValuationApplyCoordinator;
use Modules\Finance\CostControl\Services\ControlledValuationApplyCoordinator;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\GeneralLedger\Services\CostIssuePostingEngine;
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
        private readonly ControlledValuationApplyCoordinator $valuationApplyCoordinator,
        private readonly ControlledAdjustmentValuationApplyCoordinator $adjustmentApplyCoordinator,
        private readonly ControlledTransferValuationApplyCoordinator $transferApplyCoordinator,
        private readonly CostIssuePostingEngine $issuePostingEngine,
    ) {}

    public function applyReceipt(string $sourceInventoryTransactionId): string
    {
        return $this->applySingle($sourceInventoryTransactionId, [TransactionTypeEnum::PurchaseReceipt], false);
    }

    public function applyIssue(string $sourceInventoryTransactionId): string
    {
        $entryId = $this->applySingle($sourceInventoryTransactionId, [TransactionTypeEnum::Issue], false);
        $this->issuePostingEngine->process(CostLedgerEntry::findOrFail($entryId));

        return $entryId;
    }

    public function applyAdjustment(string $sourceInventoryTransactionId): string
    {
        return $this->applySingle($sourceInventoryTransactionId, [
            TransactionTypeEnum::AdjustmentIn,
            TransactionTypeEnum::AdjustmentOut,
        ], true);
    }

    public function applyTransfer(
        string $outboundInventoryTransactionId,
        string $inboundInventoryTransactionId,
    ): array {
        $this->requireOuterTransaction(__METHOD__);
        $outbound = $this->transactionRepository->findById($outboundInventoryTransactionId);
        $inbound = $this->transactionRepository->findById($inboundInventoryTransactionId);
        if ($outbound === null || $inbound === null) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_TRANSFER_SOURCE_MISSING');
        }

        $ownership = $this->ownershipRepository->findForUpdateByPropertyItem($outbound->property_id, $outbound->item_id);
        $outbound = InventoryTransaction::whereKey($outbound->id)->lockForUpdate()->firstOrFail();
        $inbound = InventoryTransaction::whereKey($inbound->id)->lockForUpdate()->firstOrFail();
        $this->assertSynchronousSource($outbound, $ownership, [TransactionTypeEnum::TransferOut]);
        $this->assertSynchronousSource($inbound, $ownership, [TransactionTypeEnum::TransferIn]);
        if ($outbound->property_id !== $inbound->property_id
            || $outbound->item_id !== $inbound->item_id
            || $outbound->source_document_id !== $inbound->source_document_id
            || $outbound->source_line_id !== $inbound->source_line_id
            || $outbound->location_id === $inbound->location_id
            || bccomp((string) $outbound->quantity_change, bcmul((string) $inbound->quantity_change, '-1', 4), 4) !== 0
            || bccomp((string) $outbound->unit_cost, (string) $inbound->unit_cost, 4) !== 0
            || bccomp((string) $outbound->total_cost, bcmul((string) $inbound->total_cost, '-1', 4), 4) !== 0) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_TRANSFER_PAIR_INVALID');
        }

        $locations = [$outbound->location_id, $inbound->location_id];
        sort($locations, SORT_STRING);
        $states = [];
        foreach ($locations as $locationId) {
            $states[$locationId] = $this->stateRepository->lockExistingSeededStateForScope(
                $outbound->property_id, $locationId, $outbound->item_id,
            );
        }
        $sourceState = $states[$outbound->location_id];
        $destinationState = $states[$inbound->location_id];
        $this->assertStateOwnership($sourceState, $ownership);
        $this->assertStateOwnership($destinationState, $ownership);

        $outEquivalence = $this->assertApplyRequiredOrReturn($outbound, $sourceState);
        $inEquivalence = $this->assertApplyRequiredOrReturn($inbound, $destinationState);
        if ($outEquivalence !== null || $inEquivalence !== null) {
            if ($outEquivalence !== null && $inEquivalence !== null) {
                return ['outbound' => $outEquivalence, 'inbound' => $inEquivalence];
            }
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_TRANSFER_PARTIAL_LEDGER_EFFECT');
        }

        $intent = new ControlledTransferValuationIntent(
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
            outboundIntent: $this->sourceIntent($outbound),
            inboundIntent: $this->sourceIntent($inbound),
        );
        $this->transferApplyCoordinator->applyUsingLockedStates($sourceState, $destinationState, $intent);

        return [
            'outbound' => $this->requiredLedgerId($outbound),
            'inbound' => $this->requiredLedgerId($inbound),
        ];
    }

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

    /** @param array<int,TransactionTypeEnum> $expectedTypes */
    private function applySingle(string $sourceId, array $expectedTypes, bool $adjustment): string
    {
        $this->requireOuterTransaction(__METHOD__);
        $source = $this->transactionRepository->findById($sourceId);
        if ($source === null) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_SOURCE_MISSING');
        }

        $ownership = $this->ownershipRepository->findForUpdateByPropertyItem($source->property_id, $source->item_id);
        $source = InventoryTransaction::whereKey($source->id)->lockForUpdate()->firstOrFail();
        $this->assertSynchronousSource($source, $ownership, $expectedTypes);
        $state = $this->stateRepository->lockExistingSeededStateForScope(
            $source->property_id, $source->location_id, $source->item_id,
        );
        $this->assertStateOwnership($state, $ownership);

        $existingLedgerId = $this->assertApplyRequiredOrReturn($source, $state);
        if ($existingLedgerId !== null) {
            return $existingLedgerId;
        }

        $ledgerIntent = $this->sourceIntent($source);
        if ($adjustment) {
            $this->adjustmentApplyCoordinator->applyUsingLockedState(
                $state,
                new ControlledAdjustmentValuationIntent(
                    propertyId: $source->property_id,
                    locationId: $source->location_id,
                    itemId: $source->item_id,
                    currentLastAppliedValuationSequence: $this->priorSequence($state),
                    currentQuantity: new AvcoDecimal((string) $state->on_hand_quantity),
                    currentCarryingValue: new AvcoDecimal((string) $state->carrying_value),
                    costLedgerIntent: $ledgerIntent,
                ),
            );
        } else {
            $this->valuationApplyCoordinator->applyUsingLockedState($state, $ledgerIntent);
        }

        return $this->requiredLedgerId($source);
    }

    /** @param array<int,TransactionTypeEnum> $expectedTypes */
    private function assertSynchronousSource(
        InventoryTransaction $source,
        ?CostDeliveryModeOwnership $ownership,
        array $expectedTypes,
    ): void {
        $type = $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($ownership === null
            || $ownership->delivery_mode !== CostDeliveryMode::Synchronous
            || $ownership->activated_cutover_id !== null
            || ! in_array($type, $expectedTypes, true)
            || $source->valuation_scope !== $expectedScope
            || $source->cost_delivery_mode !== CostDeliveryMode::Synchronous->value
            || $source->cost_delivery_ownership_id !== $ownership->id
            || (int) $source->cost_delivery_ownership_version !== (int) $ownership->ownership_version
            || $source->cost_delivery_cutover_id !== null) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_SOURCE_STAMP_INVALID');
        }
    }

    private function assertStateOwnership(CostAvcoState $state, CostDeliveryModeOwnership $ownership): void
    {
        if ($state->property_id !== $ownership->property_id
            || $state->item_id !== $ownership->item_id
            || $state->enrollment_group_id !== $ownership->enrollment_group_id) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_SOURCE_ENROLLMENT_MISMATCH');
        }
    }

    /** Returns the existing exact ledger id, or null when application is required. */
    private function assertApplyRequiredOrReturn(InventoryTransaction $source, CostAvcoState $state): ?string
    {
        $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
        if ($equivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
            if ($state->last_valuation_sequence === null
                || (int) $state->last_valuation_sequence < (int) $source->valuation_sequence) {
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

        $expected = $state->last_valuation_sequence === null ? 1 : (int) $state->last_valuation_sequence + 1;
        if ((int) $source->valuation_sequence !== $expected) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_SEQUENCE_STATE_DIVERGENCE');
        }

        return null;
    }

    private function sourceIntent(InventoryTransaction $source): ControlledValuationCostLedgerIntent
    {
        $type = $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::from((string) $source->transaction_type);
        $entryType = match ($type) {
            TransactionTypeEnum::PurchaseReceipt => 'receipt',
            TransactionTypeEnum::Issue => 'issue',
            TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
            TransactionTypeEnum::TransferOut, TransactionTypeEnum::TransferIn => 'transfer',
            default => throw new RuntimeException('CC_P01F_SYNCHRONOUS_TRANSACTION_TYPE_UNSUPPORTED'),
        };

        return new ControlledValuationCostLedgerIntent(
            propertyId: $source->property_id,
            sourceInventoryTransactionId: $source->id,
            priorCostLedgerEntryId: null,
            entryType: $entryType,
            idempotencyKey: $source->idempotency_key,
            entrySequence: (int) $source->valuation_sequence,
            currencyCode: $source->currency_code,
            quantityDelta: new AvcoDecimal((string) $source->quantity_change),
            unitCost: new AvcoDecimal((string) $source->unit_cost),
            valueDelta: new AvcoDecimal((string) $source->total_cost),
            businessDate: $source->business_date->format('Y-m-d'),
            occurredAt: $source->occurred_at->toIso8601String(),
        );
    }

    private function priorSequence(CostAvcoState $state): ?ValuationSequence
    {
        if ($state->last_valuation_sequence === null || $state->last_valuation_business_date === null) {
            return null;
        }

        return new ValuationSequence(
            propertyId: $state->property_id,
            itemId: $state->item_id,
            valuationScope: $state->valuation_scope,
            businessDate: $state->last_valuation_business_date->format('Y-m-d'),
            ledgerSequence: (int) $state->last_valuation_sequence,
        );
    }

    private function requiredLedgerId(InventoryTransaction $source): string
    {
        $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
        if ($equivalence->status !== CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT) {
            throw new RuntimeException('CC_P01F_SYNCHRONOUS_LEDGER_EFFECT_MISSING');
        }

        return (string) $equivalence->costLedgerEntryId;
    }

    private function requireOuterTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException("{$method} requires an active outer transaction.");
        }
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
