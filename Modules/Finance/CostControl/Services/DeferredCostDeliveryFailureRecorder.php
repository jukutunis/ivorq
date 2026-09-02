<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Repositories\CostDeliveryOutboxDispositionRepository;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryFailure;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;

final class DeferredCostDeliveryFailureRecorder
{
    public function __construct(
        private readonly CostDeliveryOutboxDispositionRepository $dispositionRepository,
        private readonly OutboxRepository $outboxRepository,
        private readonly CostLedgerRepository $costLedgerRepository,
    ) {}

    /** @param list<array<string, int|string>> $legs */
    public function record(
        array $legs,
        DeferredCostDeliveryFailure $failure,
        ?DeferredCostDeliveryEligibleContext $eligibleContext = null,
    ): void {
        if ($legs === []) {
            return;
        }

        DB::transaction(function () use ($legs, $failure, $eligibleContext): void {
            $outboxIds = array_column($legs, 'outbox_id');
            $outboxes = $this->outboxRepository->findManyForUpdate($outboxIds);
            $dispositions = CostDeliveryOutboxDisposition::whereIn('id', array_column($legs, 'disposition_id'))
                ->orderBy('outbox_message_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($dispositions->count() !== count($legs)) {
                throw new RuntimeException('CC_P01E_FAILURE_DISPOSITION_SET_INCOMPLETE');
            }

            $pending = [];
            foreach ($legs as $leg) {
                $disposition = $dispositions->get($leg['disposition_id']);
                if ($disposition === null
                    || $disposition->outbox_message_id !== $leg['outbox_id']
                    || $disposition->source_inventory_transaction_id !== $leg['source_id']
                    || $disposition->property_id !== $leg['property_id']
                    || $disposition->location_id !== $leg['location_id']
                    || $disposition->item_id !== $leg['item_id']
                    || $disposition->valuation_scope !== $leg['valuation_scope']
                    || $disposition->valuation_sequence !== $leg['valuation_sequence']) {
                    throw new RuntimeException('CC_P01E_FAILURE_DISPOSITION_IDENTITY_MISMATCH');
                }
                if ($disposition->processing_state === CostDeliveryProcessingState::Pending) {
                    $pending[] = [$leg, $disposition];
                } elseif (! in_array($disposition->processing_state, [
                    CostDeliveryProcessingState::Failed,
                    CostDeliveryProcessingState::BlockedSequence,
                ], true)) {
                    throw new RuntimeException('CC_P01E_FAILURE_DISPOSITION_STATE_CONTRADICTION');
                }
            }

            if ($pending === []) {
                return;
            }

            $states = $this->lockStates($legs);
            $this->proveNoAttemptAdvancement($legs, $states, $failure, $eligibleContext);

            $attemptedAt = now();
            if ($failure->code === 'BLOCKED_SEQUENCE') {
                foreach ($pending as [$leg, $disposition]) {
                    $state = $states[$leg['valuation_scope']];
                    $expected = $state->last_valuation_sequence === null
                        ? 1
                        : $state->last_valuation_sequence + 1;
                    $this->dispositionRepository->markBlockedSequence($disposition, $expected, $attemptedAt);
                }

                return;
            }

            $recoverable = $this->isRecoverable($failure->code);
            foreach ($pending as [$leg, $disposition]) {
                $this->dispositionRepository->markFailed(
                    $disposition,
                    $failure->code,
                    $recoverable,
                    $attemptedAt,
                );
                $this->outboxRepository->markFailedWithinTransaction(
                    $outboxes[$leg['outbox_id']],
                    $failure->code,
                );
            }
        });
    }

    public function recordUnclassifiedTransferFailure(
        string $outboxMessageId,
        string $sourceInventoryTransactionId,
        DeferredCostDeliveryFailure $failure,
    ): void {
        DB::transaction(function () use ($outboxMessageId, $sourceInventoryTransactionId, $failure): void {
            $outbox = $this->outboxRepository->findForUpdate($outboxMessageId);
            $source = InventoryTransaction::whereKey($sourceInventoryTransactionId)->lockForUpdate()->first();
            if ($outbox === null || $source === null
                || $outbox->source_inventory_transaction_id !== $source->id) {
                throw new RuntimeException('CC_P01E_UNCLASSIFIED_FAILURE_SOURCE_CHANGED');
            }

            $state = CostAvcoState::where('property_id', $source->property_id)
                ->where('location_id', $source->location_id)
                ->where('item_id', $source->item_id)
                ->lockForUpdate()
                ->first();
            if ($state !== null && $state->last_valuation_sequence !== null
                && $state->last_valuation_sequence >= $source->valuation_sequence) {
                throw new RuntimeException('CC_P01E_UNCLASSIFIED_FAILURE_AVCO_ALREADY_ADVANCED');
            }
            $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
            if ($equivalence->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
                throw new RuntimeException('CC_P01E_UNCLASSIFIED_FAILURE_MONETARY_EFFECT_EXISTS');
            }

            $this->outboxRepository->markFailedWithinTransaction($outbox, $failure->code);
        });
    }

    /**
     * @param  list<array<string, int|string>>  $legs
     * @return array<string, CostAvcoState>
     */
    private function lockStates(array $legs): array
    {
        usort($legs, fn (array $left, array $right): int => $left['valuation_scope'] <=> $right['valuation_scope']);
        $states = [];
        foreach ($legs as $leg) {
            $state = CostAvcoState::where('property_id', $leg['property_id'])
                ->where('location_id', $leg['location_id'])
                ->where('item_id', $leg['item_id'])
                ->lockForUpdate()
                ->first();
            if ($state === null || $state->valuation_scope !== $leg['valuation_scope']) {
                throw new RuntimeException('CC_P01E_FAILURE_AVCO_STATE_MISSING');
            }
            $states[$leg['valuation_scope']] = $state;
        }

        return $states;
    }

    /**
     * @param  list<array<string, int|string>>  $legs
     * @param  array<string, CostAvcoState>  $states
     */
    private function proveNoAttemptAdvancement(
        array $legs,
        array $states,
        DeferredCostDeliveryFailure $failure,
        ?DeferredCostDeliveryEligibleContext $eligibleContext,
    ): void {
        $eligibleLegs = [];
        if ($eligibleContext !== null) {
            $eligibleLegs[$eligibleContext->sourceLeg->sourceInventoryTransactionId] = $eligibleContext->sourceLeg;
            if ($eligibleContext->pairedLeg !== null) {
                $eligibleLegs[$eligibleContext->pairedLeg->sourceInventoryTransactionId] = $eligibleContext->pairedLeg;
            }
        }

        foreach ($legs as $leg) {
            $source = InventoryTransaction::find($leg['source_id']);
            if ($source === null) {
                throw new RuntimeException('CC_P01E_FAILURE_SOURCE_MISSING');
            }
            $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($source, true);
            $eligibleLeg = $eligibleLegs[$source->id] ?? null;
            if ($eligibleLeg !== null && ! $eligibleLeg->alreadySatisfied
                && $equivalence->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
                throw new RuntimeException('CC_P01E_FAILURE_LEDGER_ADVANCEMENT_DETECTED');
            }
            if ($eligibleLeg === null
                && ! in_array($failure->code, [
                    'COST_LEDGER_CONFLICTING_EFFECT',
                    'CC_P01C_COST_LEDGER_SOURCE_CONFLICT',
                    'COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION',
                    'CC_P01C_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION',
                ], true)
                && $equivalence->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
                throw new RuntimeException('CC_P01E_FAILURE_LEDGER_ADVANCEMENT_DETECTED');
            }

            if ($eligibleLeg !== null && ! $eligibleLeg->alreadySatisfied) {
                $state = $states[$leg['valuation_scope']];
                $expectedPrior = $eligibleLeg->expectedSequence - 1;
                $current = $state->last_valuation_sequence;
                if (($expectedPrior === 0 && $current !== null)
                    || ($expectedPrior > 0 && $current !== $expectedPrior)) {
                    throw new RuntimeException('CC_P01E_FAILURE_AVCO_ADVANCEMENT_DETECTED');
                }
            }
        }
    }

    private function isRecoverable(string $failureCode): bool
    {
        return in_array($failureCode, [
            'BUSINESS_DATE_CLOSED',
            'FINANCIAL_PERIOD_STATE_INELIGIBLE',
            'TRANSFER_PAIR_BUSINESS_DATE_CLOSED',
            'TRANSFER_PAIR_FINANCIAL_PERIOD_STATE_INELIGIBLE',
            'TRANSFER_PAIR_EVIDENCE_INCOMPLETE',
            'TRANSFER_PAIR_OUTBOX_MISSING',
            'DEFERRED_APPLY_INFRASTRUCTURE_FAILURE',
        ], true);
    }
}
