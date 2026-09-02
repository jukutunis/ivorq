<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostDeliveryCutover;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverScope;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostDeliveryOutboxDispositionRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryDispositionDecision;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryFailure;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryResult;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;
use Throwable;

final class DeferredCostDeliveryConsumer
{
    public function __construct(
        private readonly CostDeliveryOutboxDispositionRepository $dispositionRepository,
        private readonly OutboxRepository $outboxRepository,
        private readonly DeferredCostDeliveryEligibilityService $eligibilityService,
        private readonly DeferredSingleTransactionValuationHandler $singleHandler,
        private readonly DeferredTransferValuationHandler $transferHandler,
        private readonly DeferredCostDeliveryFailureRecorder $failureRecorder,
    ) {}

    public function consume(string $outboxMessageId): DeferredCostDeliveryResult
    {
        $preRead = $this->immutablePreRead($outboxMessageId);
        if ($preRead instanceof DeferredCostDeliveryFailure) {
            return DeferredCostDeliveryResult::rejected($preRead->code);
        }

        try {
            $classifiedLegs = DB::transaction(
                fn (): array|DeferredCostDeliveryFailure => $this->classifyWithinTransaction($outboxMessageId),
            );
        } catch (Throwable $exception) {
            $failure = $this->safeFailure($exception);

            return DeferredCostDeliveryResult::rejected($failure->code, [$preRead]);
        }

        if ($classifiedLegs instanceof DeferredCostDeliveryFailure) {
            if (str_starts_with($classifiedLegs->code, 'TRANSFER_PAIR_')
                && $preRead['deferred_stamp']) {
                $this->failureRecorder->recordUnclassifiedTransferFailure(
                    $preRead['outbox_id'],
                    $preRead['source_id'],
                    $classifiedLegs,
                );

                return DeferredCostDeliveryResult::failed($classifiedLegs->code, [$preRead]);
            }

            return DeferredCostDeliveryResult::rejected($classifiedLegs->code, [$preRead]);
        }

        $applyLegs = $classifiedLegs;
        $eligibleContext = null;
        try {
            $outcome = DB::transaction(function () use (
                $outboxMessageId,
                &$applyLegs,
                &$eligibleContext,
            ): DeferredCostDeliveryResult|DeferredCostDeliveryFailure {
                $reclassified = $this->classifyWithinTransaction($outboxMessageId);
                if ($reclassified instanceof DeferredCostDeliveryFailure) {
                    return $reclassified;
                }
                $applyLegs = $reclassified;

                $lifecycle = $this->lifecycleResult($applyLegs);
                if ($lifecycle !== null) {
                    return $lifecycle;
                }

                $eligibility = $this->eligibilityService->evaluateWithinTransaction($outboxMessageId);
                if ($eligibility instanceof DeferredCostDeliveryFailure) {
                    return $eligibility;
                }
                $eligibleContext = $eligibility;

                if ($eligibility->requiresPairedApplication) {
                    $this->transferHandler->apply($eligibility);
                } else {
                    $this->singleHandler->apply($eligibility);
                }

                $completedAt = now();
                foreach ($applyLegs as $leg) {
                    $this->dispositionRepository->markDelivered($leg['disposition'], $completedAt);
                }
                foreach ($applyLegs as $leg) {
                    $this->outboxRepository->markDeliveredWithinTransaction($leg['outbox'], $completedAt);
                }

                return DeferredCostDeliveryResult::delivered($applyLegs);
            });
        } catch (Throwable $exception) {
            $outcome = $this->safeFailure($exception);
        }

        if ($outcome instanceof DeferredCostDeliveryResult) {
            return $outcome;
        }

        if ($this->isLifecycleContradiction($outcome->code)) {
            return DeferredCostDeliveryResult::rejected($outcome->code, $applyLegs);
        }

        $this->failureRecorder->record($applyLegs, $outcome, $eligibleContext);

        return DeferredCostDeliveryResult::failed($outcome->code, $applyLegs);
    }

    /** @return array{outbox_id:string,source_id:string,deferred_stamp:bool}|DeferredCostDeliveryFailure */
    private function immutablePreRead(string $outboxMessageId): array|DeferredCostDeliveryFailure
    {
        if (trim($outboxMessageId) === '') {
            return new DeferredCostDeliveryFailure('OUTBOX_ID_REQUIRED');
        }
        $outbox = OutboxMessage::find($outboxMessageId);
        if ($outbox === null) {
            return new DeferredCostDeliveryFailure('OUTBOX_NOT_FOUND');
        }
        $sourceId = trim((string) $outbox->source_inventory_transaction_id);
        if ($sourceId === '') {
            return new DeferredCostDeliveryFailure('OUTBOX_SOURCE_ID_MISSING');
        }
        $source = InventoryTransaction::find($sourceId);
        if ($source === null) {
            return new DeferredCostDeliveryFailure('INVENTORY_SOURCE_NOT_FOUND');
        }

        return [
            'outbox_id' => $outbox->id,
            'source_id' => $source->id,
            'deferred_stamp' => $source->cost_delivery_mode === CostDeliveryMode::Deferred->value,
        ];
    }

    /** @return list<array<string, mixed>>|DeferredCostDeliveryFailure */
    private function classifyWithinTransaction(string $outboxMessageId): array|DeferredCostDeliveryFailure
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $triggerOutbox = OutboxMessage::find($outboxMessageId);
        if ($triggerOutbox === null) {
            return $this->failure('OUTBOX_NOT_FOUND');
        }
        $triggerSourceId = $this->outboxSourceId($triggerOutbox, false);
        if ($triggerSourceId instanceof DeferredCostDeliveryFailure) {
            return $triggerSourceId;
        }
        $triggerSource = InventoryTransaction::find($triggerSourceId);
        if ($triggerSource === null) {
            return $this->failure('INVENTORY_SOURCE_NOT_FOUND');
        }

        $initialLegs = [
            [
                'outbox' => $triggerOutbox,
                'source' => $triggerSource,
                'partner' => false,
            ],
        ];
        $triggerType = $this->transactionType($triggerSource);
        if (in_array($triggerType, [TransactionTypeEnum::TransferOut, TransactionTypeEnum::TransferIn], true)) {
            $partner = $this->transferPartner($triggerSource);
            if ($partner instanceof DeferredCostDeliveryFailure) {
                return $partner;
            }
            $partnerOutboxes = OutboxMessage::where('source_inventory_transaction_id', $partner->id)
                ->orderBy('id')
                ->get();
            if ($partnerOutboxes->count() !== 1) {
                return $this->failure('TRANSFER_PAIR_OUTBOX_MISSING');
            }
            $initialLegs[] = [
                'outbox' => $partnerOutboxes->first(),
                'source' => $partner,
                'partner' => true,
            ];
        }

        $pilot = CostDeliveryPilotProperty::where('pilot_slot', 1)
            ->where('property_id', $triggerSource->property_id)
            ->lockForUpdate()
            ->first();
        if ($pilot === null) {
            return $this->failure('PILOT_NOT_AUTHORIZED');
        }

        $ownership = CostDeliveryModeOwnership::whereKey($triggerSource->cost_delivery_ownership_id)
            ->lockForUpdate()
            ->first();
        if ($ownership === null) {
            return $this->failure('OWNERSHIP_NOT_FOUND');
        }
        if ($ownership->delivery_mode !== CostDeliveryMode::Deferred
            || $ownership->property_id !== $triggerSource->property_id
            || $ownership->item_id !== $triggerSource->item_id
            || $ownership->ownership_version !== $triggerSource->cost_delivery_ownership_version
            || $ownership->activated_cutover_id !== $triggerSource->cost_delivery_cutover_id) {
            return $this->failure('OWNERSHIP_SOURCE_STAMP_MISMATCH');
        }

        $cutover = CostDeliveryCutover::whereKey($ownership->activated_cutover_id)
            ->where('ownership_id', $ownership->id)
            ->lockForUpdate()
            ->first();
        if ($cutover === null
            || $cutover->property_id !== $ownership->property_id
            || $cutover->item_id !== $ownership->item_id
            || $cutover->enrollment_group_id !== $ownership->enrollment_group_id
            || trim((string) $cutover->activated_by) === '') {
            return $this->failure('CUTOVER_IDENTITY_MISMATCH');
        }

        $scopeOrdered = $initialLegs;
        usort($scopeOrdered, fn (array $left, array $right): int => $left['source']->valuation_scope <=> $right['source']->valuation_scope);
        $cutoverScopes = [];
        foreach ($scopeOrdered as $leg) {
            $source = $leg['source'];
            $scope = CostDeliveryCutoverScope::where('cutover_id', $cutover->id)
                ->where('property_id', $source->property_id)
                ->where('location_id', $source->location_id)
                ->where('item_id', $source->item_id)
                ->where('valuation_scope', $source->valuation_scope)
                ->lockForUpdate()
                ->first();
            if ($scope === null) {
                return $this->failure($leg['partner'] ? 'TRANSFER_PAIR_CUTOVER_SCOPE_MISSING' : 'CUTOVER_SCOPE_NOT_FOUND');
            }
            $cutoverScopes[$source->id] = $scope;
        }

        usort($initialLegs, fn (array $left, array $right): int => $left['outbox']->id <=> $right['outbox']->id);
        $lockedOutboxes = $this->outboxRepository->findManyForUpdate(
            array_map(fn (array $leg): string => $leg['outbox']->id, $initialLegs),
        );

        $prepared = [];
        foreach ($initialLegs as $leg) {
            $initialSource = $leg['source'];
            $outbox = $lockedOutboxes[$leg['outbox']->id];
            $sourceId = $this->outboxSourceId($outbox, $leg['partner']);
            if ($sourceId instanceof DeferredCostDeliveryFailure) {
                return $sourceId;
            }
            if ($sourceId !== $initialSource->id) {
                return $this->failure($leg['partner']
                    ? 'TRANSFER_PAIR_OUTBOX_SOURCE_CHANGED_DURING_CLASSIFICATION'
                    : 'OUTBOX_SOURCE_CHANGED_DURING_CLASSIFICATION');
            }

            $source = InventoryTransaction::whereKey($sourceId)->lockForUpdate()->first();
            if ($source === null) {
                return $this->failure($leg['partner'] ? 'TRANSFER_PAIR_INVENTORY_SOURCE_NOT_FOUND' : 'INVENTORY_SOURCE_NOT_FOUND');
            }
            $sourceFailure = $this->validateClassifiableSource($source, $ownership, $cutover, $leg['partner']);
            if ($sourceFailure !== null) {
                return $sourceFailure;
            }
            $scope = $cutoverScopes[$source->id] ?? null;
            if ($scope === null
                || $scope->property_id !== $source->property_id
                || $scope->location_id !== $source->location_id
                || $scope->item_id !== $source->item_id
                || $scope->valuation_scope !== $source->valuation_scope) {
                return $this->failure($leg['partner'] ? 'TRANSFER_PAIR_CUTOVER_SCOPE_MISMATCH' : 'CUTOVER_SCOPE_MISMATCH');
            }
            if ($source->valuation_sequence < $scope->first_deferred_owned_sequence) {
                return $this->failure($leg['partner'] ? 'TRANSFER_PAIR_WATERMARK_NOT_REACHED' : 'WATERMARK_NOT_REACHED');
            }

            $existing = $this->dispositionRepository->findByEitherForUpdate($outbox->id, $source->id);
            if ($existing === null && $outbox->status !== OutboxStatusEnum::Pending) {
                return $this->failure($leg['partner'] ? 'TRANSFER_PAIR_OUTBOX_STATE_INELIGIBLE' : 'OUTBOX_STATE_INELIGIBLE');
            }
            $decision = CostDeliveryDispositionDecision::deferredOwnedAfterCutover(
                outboxMessageId: $outbox->id,
                sourceInventoryTransactionId: $source->id,
                propertyId: $source->property_id,
                locationId: $source->location_id,
                itemId: $source->item_id,
                valuationScope: $source->valuation_scope,
                valuationSequence: $source->valuation_sequence,
                costDeliveryOwnershipId: $ownership->id,
                costDeliveryOwnershipVersion: $ownership->ownership_version,
                costDeliveryCutoverId: $cutover->id,
                classifiedBy: $cutover->activated_by,
                classifiedAt: now(),
            );
            $prepared[] = [
                'outbox' => $outbox,
                'source' => $source,
                'decision' => $decision,
            ];
        }

        if (count($prepared) === 2) {
            $pairFailure = $this->validateClassifiedTransferPair($prepared[0]['source'], $prepared[1]['source']);
            if ($pairFailure !== null) {
                return $pairFailure;
            }
        }

        $classified = [];
        foreach ($prepared as $leg) {
            $outbox = $leg['outbox'];
            $source = $leg['source'];
            $disposition = $this->dispositionRepository->persistDeferred($leg['decision']);
            $classified[] = [
                'outbox_id' => $outbox->id,
                'source_id' => $source->id,
                'disposition_id' => $disposition->id,
                'property_id' => $source->property_id,
                'location_id' => $source->location_id,
                'item_id' => $source->item_id,
                'valuation_scope' => $source->valuation_scope,
                'valuation_sequence' => $source->valuation_sequence,
                'outbox' => $outbox,
                'source' => $source,
                'disposition' => $disposition,
            ];
        }

        return $classified;
    }

    /** @param list<array<string, mixed>> $legs */
    private function lifecycleResult(array $legs): ?DeferredCostDeliveryResult
    {
        $dispositionStates = array_map(
            fn (array $leg): CostDeliveryProcessingState => $leg['disposition']->processing_state,
            $legs,
        );
        $outboxStates = array_map(fn (array $leg): OutboxStatusEnum => $leg['outbox']->status, $legs);

        $allDelivered = count(array_filter(
            $dispositionStates,
            fn (CostDeliveryProcessingState $state): bool => $state === CostDeliveryProcessingState::Delivered,
        )) === count($legs);
        $allOutboxesDelivered = count(array_filter(
            $outboxStates,
            fn (OutboxStatusEnum $state): bool => $state === OutboxStatusEnum::Delivered,
        )) === count($legs);
        if ($allDelivered && $allOutboxesDelivered) {
            return DeferredCostDeliveryResult::alreadyDelivered($legs);
        }
        if ($allDelivered || $allOutboxesDelivered
            || in_array(CostDeliveryProcessingState::Delivered, $dispositionStates, true)
            || in_array(OutboxStatusEnum::Delivered, $outboxStates, true)) {
            return DeferredCostDeliveryResult::rejected('CC_P01E_DELIVERY_LIFECYCLE_CONTRADICTION', $legs);
        }
        if (in_array(CostDeliveryProcessingState::HistoricalExcluded, $dispositionStates, true)) {
            return DeferredCostDeliveryResult::rejected('HISTORICAL_DISPOSITION_NOT_CONSUMABLE', $legs);
        }
        if (in_array(CostDeliveryProcessingState::Failed, $dispositionStates, true)) {
            if (! in_array(OutboxStatusEnum::Failed, $outboxStates, true)) {
                return DeferredCostDeliveryResult::rejected('CC_P01E_FAILURE_LIFECYCLE_CONTRADICTION', $legs);
            }

            return DeferredCostDeliveryResult::recoveryRequired('RECOVERY_REQUIRED_FAILED', $legs);
        }
        if (in_array(CostDeliveryProcessingState::BlockedSequence, $dispositionStates, true)) {
            return DeferredCostDeliveryResult::recoveryRequired('RECOVERY_REQUIRED_BLOCKED_SEQUENCE', $legs);
        }

        return null;
    }

    private function validateClassifiableSource(
        InventoryTransaction $source,
        CostDeliveryModeOwnership $ownership,
        CostDeliveryCutover $cutover,
        bool $partner,
    ): ?DeferredCostDeliveryFailure {
        $prefix = $partner ? 'TRANSFER_PAIR_' : '';
        if ($source->cost_delivery_mode === null) {
            return $this->failure($prefix.'HISTORICAL_NULL_STAMP_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode === CostDeliveryMode::Synchronous->value) {
            return $this->failure($prefix.'SYNCHRONOUS_SOURCE_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode !== CostDeliveryMode::Deferred->value
            || trim((string) $source->cost_delivery_ownership_id) === ''
            || $source->cost_delivery_ownership_version === null
            || $source->cost_delivery_ownership_version < 1
            || trim((string) $source->cost_delivery_cutover_id) === '') {
            return $this->failure($prefix.'SOURCE_OWNERSHIP_EVIDENCE_INCOMPLETE');
        }
        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($source->cost_delivery_ownership_id !== $ownership->id
            || $source->cost_delivery_ownership_version !== $ownership->ownership_version
            || $source->cost_delivery_cutover_id !== $cutover->id
            || $source->property_id !== $ownership->property_id
            || $source->item_id !== $ownership->item_id
            || $source->valuation_scope !== $expectedScope
            || $source->valuation_sequence === null
            || $source->valuation_sequence < 1) {
            return $this->failure($prefix.'SOURCE_OWNERSHIP_OR_SCOPE_MISMATCH');
        }
        $item = InventoryItem::find($source->item_id);
        $location = InventoryLocation::find($source->location_id);
        if ($item === null || $location === null
            || $item->property_id !== $source->property_id
            || $location->property_id !== $source->property_id) {
            return $this->failure($prefix.'SOURCE_PROPERTY_MISMATCH');
        }

        return null;
    }

    private function outboxSourceId(
        OutboxMessage $outbox,
        bool $partner,
    ): string|DeferredCostDeliveryFailure {
        $prefix = $partner ? 'TRANSFER_PAIR_' : '';
        $sourceId = trim((string) $outbox->source_inventory_transaction_id);
        if ($outbox->topic !== 'inventory.transaction.posted') {
            return $this->failure($prefix.'OUTBOX_TOPIC_INVALID');
        }
        if ($sourceId === '') {
            return $this->failure($prefix.'OUTBOX_SOURCE_ID_MISSING');
        }
        if (! is_array($outbox->payload)
            || count($outbox->payload) !== 1
            || ! array_key_exists('transactionId', $outbox->payload)
            || $outbox->payload['transactionId'] !== $sourceId
            || $outbox->idempotency_key !== "inventory_transaction:{$sourceId}:cost_ledger") {
            return $this->failure($prefix.'OUTBOX_PAYLOAD_SOURCE_MISMATCH');
        }

        return $sourceId;
    }

    private function transferPartner(InventoryTransaction $source): InventoryTransaction|DeferredCostDeliveryFailure
    {
        if (trim((string) $source->source_document_id) === '' || trim((string) $source->source_line_id) === '') {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }
        $partnerType = $this->transactionType($source) === TransactionTypeEnum::TransferOut
            ? TransactionTypeEnum::TransferIn
            : TransactionTypeEnum::TransferOut;
        $partners = InventoryTransaction::where('property_id', $source->property_id)
            ->where('source_document_id', $source->source_document_id)
            ->where('source_line_id', $source->source_line_id)
            ->where('transaction_type', $partnerType->value)
            ->orderBy('id')
            ->get();
        if ($partners->count() !== 1) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }

        return $partners->first();
    }

    private function validateClassifiedTransferPair(
        InventoryTransaction $left,
        InventoryTransaction $right,
    ): ?DeferredCostDeliveryFailure {
        $leftType = $this->transactionType($left);
        $rightType = $this->transactionType($right);
        $opposite = ($leftType === TransactionTypeEnum::TransferOut && $rightType === TransactionTypeEnum::TransferIn)
            || ($leftType === TransactionTypeEnum::TransferIn && $rightType === TransactionTypeEnum::TransferOut);
        if (! $opposite
            || $left->property_id !== $right->property_id
            || $left->item_id !== $right->item_id
            || $left->source_document_id !== $right->source_document_id
            || $left->source_line_id !== $right->source_line_id
            || $left->location_id === $right->location_id
            || $left->currency_code !== $right->currency_code
            || $left->business_date?->format('Y-m-d') !== $right->business_date?->format('Y-m-d')
            || $left->occurred_at?->getTimestamp() !== $right->occurred_at?->getTimestamp()
            || $left->cost_delivery_ownership_id !== $right->cost_delivery_ownership_id
            || $left->cost_delivery_ownership_version !== $right->cost_delivery_ownership_version
            || $left->cost_delivery_cutover_id !== $right->cost_delivery_cutover_id
            || bccomp((string) $left->quantity_change, bcmul((string) $right->quantity_change, '-1', 4), 4) !== 0
            || bccomp((string) $left->unit_cost, (string) $right->unit_cost, 4) !== 0
            || bccomp((string) $left->total_cost, bcmul((string) $right->total_cost, '-1', 4), 4) !== 0) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_CONFLICT');
        }

        return null;
    }

    private function transactionType(InventoryTransaction $source): ?TransactionTypeEnum
    {
        return $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
    }

    private function safeFailure(Throwable $exception): DeferredCostDeliveryFailure
    {
        $message = trim($exception->getMessage());
        if ($exception instanceof QueryException) {
            return $this->failure('DEFERRED_APPLY_INFRASTRUCTURE_FAILURE');
        }
        if (preg_match('/^[A-Z0-9_]{1,96}$/', $message) === 1) {
            return $this->failure($message);
        }

        return $this->failure('DEFERRED_VALUATION_PLAN_REJECTED');
    }

    private function isLifecycleContradiction(string $code): bool
    {
        return str_contains($code, 'LIFECYCLE_CONTRADICTION')
            || $code === 'HISTORICAL_DISPOSITION_NOT_CONSUMABLE';
    }

    private function failure(string $code): DeferredCostDeliveryFailure
    {
        return new DeferredCostDeliveryFailure($code);
    }
}
