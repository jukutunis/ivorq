<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryCutover;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverScope;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryFailure;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;

final class DeferredCostDeliveryEligibilityService
{
    public function __construct(
        private readonly CostLedgerRepository $costLedgerRepository,
    ) {}

    public function evaluate(
        string $outboxMessageId,
    ): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure {
        if (DB::transactionLevel() > 0) {
            return $this->evaluateWithinTransaction($outboxMessageId);
        }

        return DB::transaction(
            fn (): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure => $this->evaluateWithinTransaction($outboxMessageId),
        );
    }

    public function evaluateWithinTransaction(
        string $outboxMessageId,
    ): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        if (trim($outboxMessageId) === '') {
            return $this->failure('OUTBOX_ID_REQUIRED');
        }

        $initialOutbox = OutboxMessage::find($outboxMessageId);
        if ($initialOutbox === null) {
            return $this->failure('OUTBOX_NOT_FOUND');
        }

        $sourceId = $this->validatedOutboxSourceId($initialOutbox);
        if ($sourceId instanceof DeferredCostDeliveryFailure) {
            return $sourceId;
        }

        $initialSource = InventoryTransaction::find($sourceId);
        if ($initialSource === null) {
            return $this->failure('INVENTORY_SOURCE_NOT_FOUND');
        }

        $modeFailure = $this->validateDeferredStampShape($initialSource);
        if ($modeFailure !== null) {
            return $modeFailure;
        }

        // Canonical lock order begins with ownership, then cutover/scope.
        $ownership = CostDeliveryModeOwnership::whereKey($initialSource->cost_delivery_ownership_id)
            ->lockForUpdate()
            ->first();
        if ($ownership === null) {
            return $this->failure('OWNERSHIP_NOT_FOUND');
        }

        if ($ownership->delivery_mode !== CostDeliveryMode::Deferred) {
            return $this->failure('OWNERSHIP_NOT_DEFERRED');
        }

        if ($ownership->property_id !== $initialSource->property_id
            || $ownership->item_id !== $initialSource->item_id) {
            return $this->failure('OWNERSHIP_IDENTITY_MISMATCH');
        }

        if ($ownership->ownership_version !== $initialSource->cost_delivery_ownership_version) {
            return $this->failure('OWNERSHIP_VERSION_MISMATCH');
        }

        if ($ownership->activated_cutover_id !== $initialSource->cost_delivery_cutover_id) {
            return $this->failure('OWNERSHIP_CUTOVER_MISMATCH');
        }

        $cutover = CostDeliveryCutover::whereKey($ownership->activated_cutover_id)
            ->where('ownership_id', $ownership->id)
            ->lockForUpdate()
            ->first();
        if ($cutover === null) {
            return $this->failure('CUTOVER_NOT_FOUND');
        }

        if ($cutover->property_id !== $initialSource->property_id
            || $cutover->item_id !== $initialSource->item_id
            || $cutover->enrollment_group_id !== $ownership->enrollment_group_id) {
            return $this->failure('CUTOVER_IDENTITY_MISMATCH');
        }

        $cutoverScope = CostDeliveryCutoverScope::where('cutover_id', $cutover->id)
            ->where('property_id', $initialSource->property_id)
            ->where('location_id', $initialSource->location_id)
            ->where('item_id', $initialSource->item_id)
            ->where('valuation_scope', $initialSource->valuation_scope)
            ->lockForUpdate()
            ->first();
        if ($cutoverScope === null) {
            return $this->failure('CUTOVER_SCOPE_NOT_FOUND');
        }

        // Re-resolve and lock source/disposition evidence after ownership/cutover.
        $outbox = OutboxMessage::whereKey($outboxMessageId)->lockForUpdate()->first();
        if ($outbox === null) {
            return $this->failure('OUTBOX_NOT_FOUND');
        }

        $lockedSourceId = $this->validatedOutboxSourceId($outbox);
        if ($lockedSourceId instanceof DeferredCostDeliveryFailure) {
            return $lockedSourceId;
        }

        if ($lockedSourceId !== $sourceId) {
            return $this->failure('OUTBOX_SOURCE_CHANGED_DURING_PROOF');
        }

        $source = InventoryTransaction::whereKey($sourceId)->lockForUpdate()->first();
        if ($source === null) {
            return $this->failure('INVENTORY_SOURCE_NOT_FOUND');
        }

        $modeFailure = $this->validateDeferredStampShape($source);
        if ($modeFailure !== null) {
            return $modeFailure;
        }

        if ($source->cost_delivery_ownership_id !== $ownership->id
            || $source->cost_delivery_ownership_version !== $ownership->ownership_version
            || $source->cost_delivery_cutover_id !== $cutover->id) {
            return $this->failure('SOURCE_OWNERSHIP_STAMP_CHANGED_DURING_PROOF');
        }

        $sourceFailure = $this->validateCanonicalSource($source);
        if ($sourceFailure !== null) {
            return $sourceFailure;
        }

        $dispositions = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)
            ->orWhere('source_inventory_transaction_id', $source->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($dispositions->count() !== 1) {
            return $this->failure('DISPOSITION_NOT_FOUND');
        }

        $disposition = $dispositions->first();
        if ($disposition->outbox_message_id !== $outbox->id
            || $disposition->source_inventory_transaction_id !== $source->id
            || $disposition->property_id !== $source->property_id
            || $disposition->location_id !== $source->location_id
            || $disposition->item_id !== $source->item_id
            || $disposition->valuation_scope !== $source->valuation_scope
            || $disposition->valuation_sequence !== $source->valuation_sequence
            || $disposition->cost_delivery_ownership_id !== $ownership->id
            || $disposition->cost_delivery_ownership_version !== $ownership->ownership_version
            || $disposition->cost_delivery_cutover_id !== $cutover->id) {
            return $this->failure('DISPOSITION_IDENTITY_MISMATCH');
        }

        if ($disposition->classification !== CostDeliveryDispositionClass::DeferredOwnedAfterCutover) {
            return $this->failure('DISPOSITION_CLASSIFICATION_INELIGIBLE');
        }

        if (! in_array($disposition->processing_state, [
            CostDeliveryProcessingState::Pending,
            CostDeliveryProcessingState::Failed,
            CostDeliveryProcessingState::BlockedSequence,
        ], true)) {
            return $this->failure('DISPOSITION_STATE_INELIGIBLE');
        }

        $group = CostAuthorityEnrollmentGroup::find($ownership->enrollment_group_id);
        if ($group === null) {
            return $this->failure('ENROLLMENT_NOT_FOUND');
        }
        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled) {
            return $this->failure('ENROLLMENT_NOT_ENROLLED');
        }
        if ($group->property_id !== $source->property_id || $group->item_id !== $source->item_id) {
            return $this->failure('ENROLLMENT_IDENTITY_MISMATCH');
        }

        $snapshot = CostAuthorityEnrollmentScopeSnapshot::whereKey($cutoverScope->enrollment_scope_snapshot_id)
            ->where('enrollment_group_id', $group->id)
            ->first();
        if ($snapshot === null) {
            return $this->failure('SCOPE_SNAPSHOT_NOT_FOUND');
        }
        if ($snapshot->location_id !== $source->location_id
            || $snapshot->valuation_scope !== $source->valuation_scope) {
            return $this->failure('SCOPE_SNAPSHOT_MISMATCH');
        }

        if (! CostDeliveryPilotProperty::where('pilot_slot', 1)
            ->where('property_id', $source->property_id)
            ->exists()) {
            return $this->failure('PILOT_NOT_AUTHORIZED');
        }

        if ($source->valuation_sequence < $cutoverScope->first_deferred_owned_sequence) {
            return $this->failure('WATERMARK_NOT_REACHED', [
                'first_deferred_owned_sequence' => $cutoverScope->first_deferred_owned_sequence,
                'source_valuation_sequence' => $source->valuation_sequence,
            ]);
        }

        $dateFailure = $this->validateSourceDateAndPeriod($source);
        if ($dateFailure !== null) {
            return $dateFailure;
        }

        $typeResult = $this->validateTransactionType($source);
        if ($typeResult instanceof DeferredCostDeliveryFailure) {
            return $typeResult;
        }

        [$requiresPair, $pairedSourceId] = $typeResult;

        // CostAvcoState / sequence evidence precedes final Cost Ledger arbitration.
        $avcoState = CostAvcoState::where('property_id', $source->property_id)
            ->where('location_id', $source->location_id)
            ->where('item_id', $source->item_id)
            ->lockForUpdate()
            ->first();
        if ($avcoState === null || $avcoState->valuation_scope !== $source->valuation_scope) {
            return $this->failure('AVCO_STATE_MISSING');
        }

        $expectedSequence = $avcoState->last_valuation_sequence === null
            ? 1
            : $avcoState->last_valuation_sequence + 1;

        $sourceEquivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
        if ($sourceEquivalence->status === CostLedgerSourceEquivalence::CONFLICTING_EFFECT) {
            return $this->failure('COST_LEDGER_CONFLICTING_EFFECT');
        }
        if ($sourceEquivalence->status === CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION) {
            return $this->failure('COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION', [
                'source_row_count' => $sourceEquivalence->sourceRowCount,
            ]);
        }

        if ($source->valuation_sequence > $expectedSequence) {
            return $this->failure('BLOCKED_SEQUENCE', [
                'expected_sequence' => $expectedSequence,
                'source_valuation_sequence' => $source->valuation_sequence,
            ]);
        }

        $alreadySatisfied = $sourceEquivalence->status === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT;
        if ($source->valuation_sequence < $expectedSequence && ! $alreadySatisfied) {
            return $this->failure('SOURCE_SEQUENCE_BEHIND_CONTRADICTION', [
                'expected_sequence' => $expectedSequence,
                'source_valuation_sequence' => $source->valuation_sequence,
            ]);
        }

        return new DeferredCostDeliveryEligibleContext(
            outboxMessageId: $outbox->id,
            sourceInventoryTransactionId: $source->id,
            propertyId: $source->property_id,
            locationId: $source->location_id,
            itemId: $source->item_id,
            valuationScope: $source->valuation_scope,
            valuationSequence: $source->valuation_sequence,
            ownershipId: $ownership->id,
            ownershipVersion: $ownership->ownership_version,
            cutoverId: $cutover->id,
            dispositionId: $disposition->id,
            processingState: $disposition->processing_state->value,
            sourceEquivalence: $sourceEquivalence,
            expectedSequence: $expectedSequence,
            alreadySatisfied: $alreadySatisfied,
            requiresPairedApplication: $requiresPair,
            pairedInventoryTransactionId: $pairedSourceId,
        );
    }

    private function validatedOutboxSourceId(
        OutboxMessage $outbox,
    ): string|DeferredCostDeliveryFailure {
        if ($outbox->topic !== 'inventory.transaction.posted') {
            return $this->failure('OUTBOX_TOPIC_INVALID');
        }

        if (! in_array($outbox->status, [OutboxStatusEnum::Pending, OutboxStatusEnum::Failed], true)) {
            return $this->failure('OUTBOX_STATE_INELIGIBLE');
        }

        $sourceId = trim((string) $outbox->source_inventory_transaction_id);
        if ($sourceId === '') {
            return $this->failure('OUTBOX_SOURCE_ID_MISSING');
        }

        $payload = $outbox->payload;
        if (! is_array($payload)
            || count($payload) !== 1
            || ! array_key_exists('transactionId', $payload)
            || ! is_string($payload['transactionId'])
            || $payload['transactionId'] !== $sourceId) {
            return $this->failure('OUTBOX_PAYLOAD_SOURCE_MISMATCH');
        }

        if ($outbox->idempotency_key !== "inventory_transaction:{$sourceId}:cost_ledger") {
            return $this->failure('OUTBOX_SOURCE_IDENTITY_MISMATCH');
        }

        return $sourceId;
    }

    private function validateDeferredStampShape(
        InventoryTransaction $source,
    ): ?DeferredCostDeliveryFailure {
        if ($source->cost_delivery_mode === null) {
            return $this->failure('HISTORICAL_NULL_STAMP_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode === CostDeliveryMode::Synchronous->value) {
            return $this->failure('SYNCHRONOUS_SOURCE_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode !== CostDeliveryMode::Deferred->value) {
            return $this->failure('SOURCE_DELIVERY_MODE_INVALID');
        }
        if (trim((string) $source->cost_delivery_ownership_id) === ''
            || $source->cost_delivery_ownership_version === null
            || $source->cost_delivery_ownership_version < 1
            || trim((string) $source->cost_delivery_cutover_id) === '') {
            return $this->failure('SOURCE_OWNERSHIP_EVIDENCE_INCOMPLETE');
        }

        return null;
    }

    private function validateCanonicalSource(
        InventoryTransaction $source,
    ): ?DeferredCostDeliveryFailure {
        if (trim((string) $source->property_id) === ''
            || trim((string) $source->item_id) === ''
            || trim((string) $source->location_id) === ''
            || trim((string) $source->valuation_scope) === ''
            || $source->valuation_sequence === null
            || $source->valuation_sequence < 1
            || $source->business_date === null
            || trim((string) $source->financial_period_id) === ''
            || trim((string) $source->currency_code) === ''
            || $source->occurred_at === null) {
            return $this->failure('SOURCE_EVIDENCE_INCOMPLETE');
        }

        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($source->valuation_scope !== $expectedScope) {
            return $this->failure('SOURCE_SCOPE_MISMATCH');
        }

        $item = InventoryItem::find($source->item_id);
        $location = InventoryLocation::find($source->location_id);
        if ($item === null
            || $location === null
            || $item->property_id !== $source->property_id
            || $location->property_id !== $source->property_id) {
            return $this->failure('SOURCE_PROPERTY_MISMATCH');
        }

        return null;
    }

    private function validateSourceDateAndPeriod(
        InventoryTransaction $source,
    ): ?DeferredCostDeliveryFailure {
        $businessDate = PropertyBusinessDate::where('property_id', $source->property_id)
            ->whereDate('business_date', $source->business_date->format('Y-m-d'))
            ->first();
        if ($businessDate === null) {
            return $this->failure('BUSINESS_DATE_MISSING');
        }
        if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || ! $businessDate->is_open) {
            return $this->failure('BUSINESS_DATE_CLOSED');
        }

        $period = FinancialPeriod::find($source->financial_period_id);
        if ($period === null) {
            return $this->failure('FINANCIAL_PERIOD_MISSING');
        }
        if ($period->property_id !== $source->property_id) {
            return $this->failure('FINANCIAL_PERIOD_PROPERTY_MISMATCH');
        }
        if ($period->period_year !== (int) $source->business_date->format('Y')
            || $period->period_month !== (int) $source->business_date->format('n')) {
            return $this->failure('FINANCIAL_PERIOD_DATE_MISMATCH');
        }
        if (! in_array($period->status, [
            FinancialPeriodStatusEnum::Open,
            FinancialPeriodStatusEnum::Reopened,
        ], true)) {
            return $this->failure('FINANCIAL_PERIOD_STATE_INELIGIBLE');
        }

        return null;
    }

    /**
     * @return array{bool, ?string}|DeferredCostDeliveryFailure
     */
    private function validateTransactionType(
        InventoryTransaction $source,
    ): array|DeferredCostDeliveryFailure {
        $transactionType = $this->transactionType($source);

        if ($transactionType === TransactionTypeEnum::OpeningBalance) {
            return $this->failure('OPENING_BALANCE_UNSUPPORTED');
        }
        if ($transactionType === TransactionTypeEnum::Return) {
            return $this->failure('RETURN_UNSUPPORTED');
        }
        if ($transactionType === TransactionTypeEnum::Reversal) {
            return $this->failure('REVERSAL_HANDLER_NOT_AVAILABLE');
        }
        if (! in_array($transactionType, [
            TransactionTypeEnum::PurchaseReceipt,
            TransactionTypeEnum::Issue,
            TransactionTypeEnum::AdjustmentIn,
            TransactionTypeEnum::AdjustmentOut,
            TransactionTypeEnum::TransferOut,
            TransactionTypeEnum::TransferIn,
        ], true)) {
            return $this->failure('TRANSACTION_TYPE_UNSUPPORTED');
        }

        if (! in_array($transactionType, [
            TransactionTypeEnum::TransferOut,
            TransactionTypeEnum::TransferIn,
        ], true)) {
            return [false, null];
        }

        if (trim((string) $source->source_document_id) === ''
            || trim((string) $source->source_line_id) === '') {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }

        $partnerType = $transactionType === TransactionTypeEnum::TransferOut
            ? TransactionTypeEnum::TransferIn
            : TransactionTypeEnum::TransferOut;
        $partners = InventoryTransaction::where('property_id', $source->property_id)
            ->where('source_document_id', $source->source_document_id)
            ->where('source_line_id', $source->source_line_id)
            ->where('transaction_type', $partnerType->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($partners->count() !== 1) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }

        $partner = $partners->first();
        if ($partner->item_id !== $source->item_id
            || $partner->location_id === $source->location_id
            || $partner->business_date?->format('Y-m-d') !== $source->business_date?->format('Y-m-d')
            || $partner->financial_period_id !== $source->financial_period_id
            || $partner->currency_code !== $source->currency_code
            || $partner->cost_delivery_mode !== CostDeliveryMode::Deferred->value
            || $partner->cost_delivery_ownership_id !== $source->cost_delivery_ownership_id
            || $partner->cost_delivery_ownership_version !== $source->cost_delivery_ownership_version
            || $partner->cost_delivery_cutover_id !== $source->cost_delivery_cutover_id
            || bccomp((string) $partner->unit_cost, (string) $source->unit_cost, 4) !== 0
            || bccomp((string) $partner->quantity_change, bcmul((string) $source->quantity_change, '-1', 4), 4) !== 0
            || bccomp((string) $partner->total_cost, bcmul((string) $source->total_cost, '-1', 4), 4) !== 0) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_CONFLICT');
        }

        return [true, $partner->id];
    }

    private function transactionType(InventoryTransaction $source): ?TransactionTypeEnum
    {
        return $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
    }

    /** @param array<string, int|string> $evidence */
    private function failure(string $code, array $evidence = []): DeferredCostDeliveryFailure
    {
        return new DeferredCostDeliveryFailure($code, $evidence);
    }
}
