<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\Repositories\CostTransferPairResolutionRepository;
use Modules\Finance\CostControl\ValueObjects\ApprovedInventoryEvidence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingWindow;
use Modules\Finance\CostControl\ValueObjects\TransferValuationContext;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;

class PairedTransferValuationService
{
    public function __construct(
        private readonly CostAvcoStateRepository $avcoRepo,
        private readonly CostLedgerRepository $ledgerRepo,
        private readonly CostTransferPairResolutionRepository $pairRepo,
        private readonly CostLedgerPostingPlanner $planner,
        private readonly OutboxRepository $outboxRepository,
    ) {}

    /**
     * Process one OutboxMessage trigger (TransferOut or TransferIn) as a paired
     * transfer valuation event. Either leg may trigger the pair; both legs are
     * resolved and applied in one outer transaction.
     *
     * Idempotency guarantees:
     *   pending  → attempt full paired evaluation
     *   frozen   → reuse existing frozen source unit cost, re-evaluate plans
     *   applied  → deliver both Outbox messages and mark pair delivered
     *   delivered → no-op
     */
    public function processOutboxMessage(string $outboxMessageId): void
    {
        // Resolve triggering OutboxMessage and its InventoryTransaction outside
        // the outer transaction — immutable evidence only, no locks.
        $triggeringOutbox = OutboxMessage::findOrFail($outboxMessageId);
        $triggeringTx     = InventoryTransaction::findOrFail(
            $triggeringOutbox->source_inventory_transaction_id
        );

        $txType = TransactionTypeEnum::from($triggeringTx->transaction_type);

        if ($txType !== TransactionTypeEnum::TransferOut && $txType !== TransactionTypeEnum::TransferIn) {
            throw new InvalidArgumentException(
                "PairedTransferValuationService: Outbox message '{$outboxMessageId}' is not a transfer leg " .
                "(transaction_type='{$triggeringTx->transaction_type}')."
            );
        }

        // Resolve partner InventoryTransaction using immutable pair identity.
        $partnerType = ($txType === TransactionTypeEnum::TransferOut)
            ? TransactionTypeEnum::TransferIn
            : TransactionTypeEnum::TransferOut;

        $partnerTx = InventoryTransaction::where('property_id', $triggeringTx->property_id)
            ->where('source_document_id', $triggeringTx->source_document_id)
            ->where('source_line_id', $triggeringTx->source_line_id)
            ->where('transaction_type', $partnerType->value)
            ->first();

        if ($partnerTx === null) {
            // Partner not yet posted — governed pending; caller may retry.
            return;
        }

        // Resolve partner OutboxMessage.
        $partnerOutbox = OutboxMessage::where('source_inventory_transaction_id', $partnerTx->id)
            ->where('topic', $triggeringOutbox->topic)
            ->first();

        if ($partnerOutbox === null) {
            return;
        }

        // Assign source and destination legs.
        if ($txType === TransactionTypeEnum::TransferOut) {
            [$sourceTx, $sourceOutbox, $destinationTx, $destinationOutbox] =
                [$triggeringTx, $triggeringOutbox, $partnerTx, $partnerOutbox];
        } else {
            [$sourceTx, $sourceOutbox, $destinationTx, $destinationOutbox] =
                [$partnerTx, $partnerOutbox, $triggeringTx, $triggeringOutbox];
        }

        // Enter the single outer transaction that governs all financial state
        // transitions, pair lifecycle, and Outbox delivery.
        DB::transaction(function () use (
            $sourceTx, $sourceOutbox, $destinationTx, $destinationOutbox
        ) {
            // Lock both OutboxMessages in deterministic ascending-id order to
            // prevent deadlocks with concurrent consumers on the same pair.
            [$firstId, $secondId] = $sourceOutbox->id <= $destinationOutbox->id
                ? [$sourceOutbox->id, $destinationOutbox->id]
                : [$destinationOutbox->id, $sourceOutbox->id];

            $lockedFirst  = OutboxMessage::where('id', $firstId)->lockForUpdate()->firstOrFail();
            $lockedSecond = OutboxMessage::where('id', $secondId)->lockForUpdate()->firstOrFail();

            $lockedSourceOutbox = ($lockedFirst->id === $sourceOutbox->id) ? $lockedFirst : $lockedSecond;
            $lockedDestOutbox   = ($lockedFirst->id === $destinationOutbox->id) ? $lockedFirst : $lockedSecond;

            // Idempotency: if both already delivered, no-op.
            if ($lockedSourceOutbox->status === OutboxStatusEnum::Delivered &&
                $lockedDestOutbox->status === OutboxStatusEnum::Delivered) {
                return;
            }

            // Bootstrap (or re-lock) the pair-resolution row.
            $resolution = $this->pairRepo->bootstrapAndLock([
                'property_id'                          => $sourceTx->property_id,
                'source_document_id'                   => $sourceTx->source_document_id,
                'source_line_id'                       => $sourceTx->source_line_id,
                'source_inventory_transaction_id'      => $sourceTx->id,
                'destination_inventory_transaction_id' => $destinationTx->id,
                'source_valuation_scope'               => $sourceTx->valuation_scope,
                'destination_valuation_scope'          => $destinationTx->valuation_scope,
                'source_valuation_sequence'            => (int) $sourceTx->valuation_sequence,
                'destination_valuation_sequence'       => (int) $destinationTx->valuation_sequence,
            ]);

            // Idempotency by lifecycle_status.
            if ($resolution->lifecycle_status === 'delivered') {
                return;
            }

            if ($resolution->lifecycle_status === 'applied') {
                $this->outboxRepository->markDelivered($lockedSourceOutbox->id);
                $this->outboxRepository->markDelivered($lockedDestOutbox->id);
                $this->pairRepo->markDelivered($resolution);
                return;
            }

            // Lock business context — PropertyBusinessDate and FinancialPeriod —
            // using the source leg as the canonical business date reference.
            // Both legs share the same property, business_date, and occurred_at.
            $businessDateRow = PropertyBusinessDate::where('property_id', $sourceTx->property_id)
                ->where('business_date', $sourceTx->business_date->format('Y-m-d'))
                ->lockForUpdate()
                ->first();

            $financialPeriod = FinancialPeriod::where('id', $sourceTx->financial_period_id)
                ->lockForUpdate()
                ->first();

            $isBusinessDateOpen = $businessDateRow !== null
                && $businessDateRow->is_open
                && $businessDateRow->status === PropertyBusinessDateStatusEnum::Open;

            $isFinancialPeriodOpen = $financialPeriod !== null
                && in_array(
                    $financialPeriod->status,
                    [FinancialPeriodStatusEnum::Open, FinancialPeriodStatusEnum::Reopened],
                    true
                );

            $businessDateStr = $sourceTx->business_date->format('Y-m-d');
            $occurredAtStr   = $sourceTx->occurred_at->format('Y-m-d H:i:s');

            $window = new CostLedgerPostingWindow(
                $sourceTx->property_id,
                $businessDateStr,
                $isBusinessDateOpen,
                $isFinancialPeriodOpen,
            );

            // Lock CostAvcoState rows in deterministic valuation_scope string order
            // to prevent deadlocks with concurrent scope-level consumers.
            $sourceScope = $sourceTx->valuation_scope;
            $destScope   = $destinationTx->valuation_scope;

            if ($sourceScope <= $destScope) {
                $lockedSourceAvco = $this->avcoRepo->bootstrapAndLock(
                    $sourceTx->property_id, $sourceTx->location_id, $sourceTx->item_id, $sourceScope
                );
                $lockedDestAvco = $this->avcoRepo->bootstrapAndLock(
                    $destinationTx->property_id, $destinationTx->location_id, $destinationTx->item_id, $destScope
                );
            } else {
                $lockedDestAvco = $this->avcoRepo->bootstrapAndLock(
                    $destinationTx->property_id, $destinationTx->location_id, $destinationTx->item_id, $destScope
                );
                $lockedSourceAvco = $this->avcoRepo->bootstrapAndLock(
                    $sourceTx->property_id, $sourceTx->location_id, $sourceTx->item_id, $sourceScope
                );
            }

            $sourceState = $this->avcoRepo->toValuationState($lockedSourceAvco);
            $destState   = $this->avcoRepo->toValuationState($lockedDestAvco);

            // Freeze source WAUC if pair is still in pending status.
            if ($resolution->lifecycle_status === 'pending') {
                $sourceWauc = $sourceState->weightedAverageUnitCost;

                if ($sourceWauc === null) {
                    $this->pairRepo->recordBlockingReason($resolution, 'MISSING_PREVAILING_CARRYING_COST');
                    return;
                }

                $this->pairRepo->freezeSourceUnitCost($resolution, $sourceWauc->getValue());
                $frozenCostStr = $sourceWauc->getValue();
            } else {
                // frozen: reuse stored frozen cost on retry — never re-read from mutable AVCO state.
                $frozenCostStr = (string) $resolution->frozen_source_unit_cost;
            }

            // Build the transfer context carrying the frozen source unit cost.
            $transferCtx = new TransferValuationContext(
                $sourceTx->property_id,
                $sourceTx->item_id,
                $sourceScope,
                new AvcoDecimal($frozenCostStr),
                $destScope,
            );

            // Build ApprovedInventoryEvidence for each leg.
            $sourceEvidence = new ApprovedInventoryEvidence(
                sourceInventoryTransactionId: $sourceTx->id,
                sourceTransactionReference:   $sourceTx->id,
                propertyId:                   $sourceTx->property_id,
                itemId:                       $sourceTx->item_id,
                valuationScope:               $sourceScope,
                currencyCode:                 $sourceTx->currency_code,
                sourceBusinessDate:           $businessDateStr,
                occurredAt:                   $occurredAtStr,
                eventType:                    'transfer',
                quantityDelta:                new AvcoDecimal((string) $sourceTx->quantity_change),
                approvedValuationBasis:       null,
                transferContext:              $transferCtx,
                idempotencyKey:               "transfer_pair:{$sourceTx->id}:cost_ledger",
                entrySequence:                (int) $sourceTx->valuation_sequence,
                approvalStatus:               $sourceTx->valuation_approval_status,
                approvalReference:            $sourceTx->valuation_approval_reference,
            );

            $destEvidence = new ApprovedInventoryEvidence(
                sourceInventoryTransactionId: $destinationTx->id,
                sourceTransactionReference:   $destinationTx->id,
                propertyId:                   $destinationTx->property_id,
                itemId:                       $destinationTx->item_id,
                valuationScope:               $destScope,
                currencyCode:                 $destinationTx->currency_code,
                sourceBusinessDate:           $businessDateStr,
                occurredAt:                   $occurredAtStr,
                eventType:                    'transfer',
                quantityDelta:                new AvcoDecimal((string) $destinationTx->quantity_change),
                approvedValuationBasis:       null,
                transferContext:              $transferCtx,
                idempotencyKey:               "transfer_pair:{$destinationTx->id}:cost_ledger",
                entrySequence:                (int) $destinationTx->valuation_sequence,
                approvalStatus:               $destinationTx->valuation_approval_status,
                approvalReference:            $destinationTx->valuation_approval_reference,
            );

            // Evaluate both legs via the planner. Plans must both be ALLOW before
            // any AVCO state, Cost Ledger entry, or Outbox delivery is mutated.
            $sourcePlan = $this->planner->plan($sourceEvidence, $window, $sourceState);
            $destPlan   = $this->planner->plan($destEvidence, $window, $destState);

            if ($sourcePlan->decision->status !== CostLedgerPostingDecision::STATUS_ALLOW) {
                $this->pairRepo->recordBlockingReason(
                    $resolution,
                    $sourcePlan->decision->reasonCode ?? 'SOURCE_PLAN_NOT_ALLOW'
                );
                return;
            }

            if ($destPlan->decision->status !== CostLedgerPostingDecision::STATUS_ALLOW) {
                $this->pairRepo->recordBlockingReason(
                    $resolution,
                    $destPlan->decision->reasonCode ?? 'DESTINATION_PLAN_NOT_ALLOW'
                );
                return;
            }

            // Both legs allowed — persist atomically. The Strict Sequence Barrier
            // in persistAllowedResultingState enforces no partial AVCO advance.
            $this->avcoRepo->persistAllowedResultingState($lockedSourceAvco, $sourcePlan);
            $this->avcoRepo->persistAllowedResultingState($lockedDestAvco, $destPlan);

            $this->ledgerRepo->append($sourcePlan->intent);
            $this->ledgerRepo->append($destPlan->intent);

            $this->pairRepo->markApplied($resolution);

            $this->outboxRepository->markDelivered($lockedSourceOutbox->id);
            $this->outboxRepository->markDelivered($lockedDestOutbox->id);

            $this->pairRepo->markDelivered($resolution);
        });
    }
}
