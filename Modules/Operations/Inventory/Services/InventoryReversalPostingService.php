<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Exceptions\InventoryReversalPostingRejectedException;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Repositories\InventoryValuationSequenceRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingResult;
use Throwable;

class InventoryReversalPostingService
{
    public function __construct(
        private readonly InventoryTransactionRepository $transactionRepo,
        private readonly InventoryValuationSequenceRepository $sequenceRepo,
        private readonly InventoryReversalCandidateGuard $candidateGuard,
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly SynchronousCostValuationPort $synchronousCostValuation,
        private readonly OutboxRepository $outboxRepository,
        private readonly AuditService $auditService,
        private readonly InventoryStockRepository $stockRepo,
    ) {}

    public function post(InventoryReversalPostingIntent $intent): InventoryReversalPostingResult
    {
        return $this->postingCoordinator->executeOnce(fn (): InventoryReversalPostingResult => DB::transaction(
            fn (): InventoryReversalPostingResult => $this->postWithinTransaction($intent)
        ));
    }

    private function postWithinTransaction(InventoryReversalPostingIntent $intent): InventoryReversalPostingResult
    {
        // Exact retry proof is deliberately first and does not resolve current ownership.
        $originalEvidence = $this->transactionRepo->findById($intent->originalTransactionId);
        $existingResult = $this->resolveExistingReversal($intent, $originalEvidence);
        if ($existingResult !== null) {
            return $existingResult;
        }

        // The original source is the anti-double-reversal serialization latch.
        $lockedOriginal = $this->transactionRepo->findAndLock($intent->originalTransactionId);
        if ($lockedOriginal === null) {
            $this->candidateGuard->guard($intent->originalTransactionId);
        }

        // A concurrent request may have committed while this transaction waited for the source lock.
        $existingResult = $this->resolveExistingReversal($intent, $lockedOriginal);
        if ($existingResult !== null) {
            return $existingResult;
        }

        $original = $this->candidateGuard->guard($intent->originalTransactionId);

        $costDeliveryDecision = $this->postingCoordinator->resolveCostDeliveryMode(
            $original->property_id,
            $original->item_id,
            $original->location_id,
        );

        if ($costDeliveryDecision->outcome === CostDeliveryPostingDecision::NOT_ENROLLED) {
            throw new InventoryReversalPostingRejectedException(
                'not_enrolled',
                'Controlled reversal requires enrolled CostControl authority.'
            );
        }

        $occurredAt = now();
        $businessDate = PropertyBusinessDate::where('property_id', $original->property_id)
            ->where('status', PropertyBusinessDateStatusEnum::Open)
            ->where('is_open', true)
            ->first();

        if ($businessDate === null) {
            throw new InventoryReversalPostingRejectedException(
                'closed_business_date',
                'No open business date found for property.'
            );
        }

        try {
            [, $period] = $this->postingCoordinator->lockContext(
                $original->property_id,
                $businessDate->business_date->format('Y-m-d'),
                $occurredAt,
            );
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'Financial period')) {
                throw new InventoryReversalPostingRejectedException(
                    'closed_financial_period',
                    $exception->getMessage()
                );
            }

            throw new InventoryReversalPostingRejectedException(
                'closed_business_date',
                $exception->getMessage()
            );
        }

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

        $stock = $this->stockRepo->createOrLockControlled(
            $original->property_id,
            $original->item_id,
            $original->location_id,
        );

        $reversalQuantityChange = bcmul((string) $original->quantity_change, '-1', 4);
        $quantityBefore = (string) $stock->physical_quantity;
        $quantityAfter = bcadd($quantityBefore, $reversalQuantityChange, 4);

        if (bccomp($quantityAfter, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'stock' => ["Negative stock is not allowed for item {$original->item_id} at location {$original->location_id}"],
            ]);
        }

        $newValuationSequence = $this->sequenceRepo->allocateNext(
            $original->property_id,
            $original->location_id,
            $original->item_id,
        );

        if ($costDeliveryDecision->outcome === CostDeliveryPostingDecision::DEFERRED
            && $newValuationSequence < $costDeliveryDecision->firstDeferredOwnedSequence) {
            throw new InventoryReversalPostingRejectedException(
                'deferred_sequence_below_watermark',
                'Reversal sequence is below the exact deferred cutover watermark.'
            );
        }

        $reversal = $this->transactionRepo->appendReversal(
            original: $original,
            businessDate: $businessDate->business_date->format('Y-m-d'),
            financialPeriodId: $period->id,
            valuationSequence: $newValuationSequence,
            quantityBefore: $quantityBefore,
            quantityAfter: $quantityAfter,
            valuationApprovalReference: $intent->approvalReference,
            idempotencyKey: $intent->idempotencyKey,
            actorId: $intent->actorId,
            costDeliveryDecision: $costDeliveryDecision,
            occurredAt: $occurredAt,
        );

        $costLedgerEntryId = null;
        if ($costDeliveryDecision->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
            $costLedgerEntryId = $this->synchronousCostValuation->applyReversal(
                $reversal->id,
                $original->id,
                $intent->reversalReason,
                $intent->approvalReference,
            );
        } else {
            $this->outboxRepository->createPending([
                'topic' => 'inventory.transaction.posted',
                'source_inventory_transaction_id' => $reversal->id,
                'payload' => ['transactionId' => $reversal->id],
                'idempotency_key' => "inventory_transaction:{$reversal->id}:cost_ledger",
            ]);
        }

        $stockStatus = bccomp($quantityAfter, '0', 4) > 0
            ? ItemStatusEnum::InStock
            : ItemStatusEnum::OutOfStock;
        $this->stockRepo->updateBalance($stock->id, $quantityAfter, $stockStatus, $occurredAt);

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
                'cost_delivery_mode' => $reversal->cost_delivery_mode,
            ],
            ['reversal']
        );

        return new InventoryReversalPostingResult(
            originalTransaction: $original,
            reversalTransaction: $reversal,
            costLedgerEntryId: $costLedgerEntryId,
            auditLog: $auditLog,
            replayed: false,
        );
    }

    private function resolveExistingReversal(
        InventoryReversalPostingIntent $intent,
        ?InventoryTransaction $original,
    ): ?InventoryReversalPostingResult {
        if ($original === null) {
            return null;
        }

        $existing = $this->transactionRepo->findByIdempotency(
            $original->property_id,
            $intent->idempotencyKey,
            true,
        );

        if ($existing === null) {
            return null;
        }

        $auditLog = AuditLog::where('auditable_type', $existing->getMorphClass())
            ->where('auditable_id', $existing->id)
            ->first();

        if (! $this->isExactExistingReversal($intent, $original, $existing, $auditLog)) {
            throw new InventoryReversalPostingRejectedException(
                'CC_P01D_EXISTING_REVERSAL_SOURCE_CONFLICT',
                'CC_P01D_EXISTING_REVERSAL_SOURCE_CONFLICT'
            );
        }

        return new InventoryReversalPostingResult(
            originalTransaction: $original,
            reversalTransaction: $existing,
            costLedgerEntryId: null,
            auditLog: $auditLog,
            replayed: true,
        );
    }

    private function isExactExistingReversal(
        InventoryReversalPostingIntent $intent,
        InventoryTransaction $original,
        InventoryTransaction $existing,
        ?AuditLog $auditLog,
    ): bool {
        $stampIsExact = match ($existing->cost_delivery_mode) {
            CostDeliveryPostingDecision::SYNCHRONOUS => $existing->cost_delivery_ownership_id !== null
                && $existing->cost_delivery_ownership_version !== null
                && $existing->cost_delivery_ownership_version >= 1
                && $existing->cost_delivery_cutover_id === null,
            CostDeliveryPostingDecision::DEFERRED => $existing->cost_delivery_ownership_id !== null
                && $existing->cost_delivery_ownership_version !== null
                && $existing->cost_delivery_ownership_version >= 1
                && $existing->cost_delivery_cutover_id !== null,
            default => false,
        };

        return $stampIsExact
            && $auditLog !== null
            && $auditLog->property_id === $original->property_id
            && $auditLog->event === 'reversal'
            && ($auditLog->new_values['original_transaction_id'] ?? null) === $original->id
            && ($auditLog->new_values['reversal_reason'] ?? null) === $intent->reversalReason
            && ($auditLog->new_values['approval_reference'] ?? null) === $intent->approvalReference
            && ($auditLog->new_values['actor_id'] ?? null) === $intent->actorId
            && $existing->transaction_type === TransactionTypeEnum::Reversal
            && $existing->property_id === $original->property_id
            && $existing->reverses_inventory_transaction_id === $original->id
            && $existing->idempotency_key === $intent->idempotencyKey
            && $existing->item_id === $original->item_id
            && $existing->location_id === $original->location_id
            && $existing->valuation_scope === $original->valuation_scope
            && $existing->valuation_approval_reference === $intent->approvalReference
            && $existing->posted_by === $intent->actorId
            && $existing->source_document_type === $original->source_document_type
            && $existing->source_document_id === $original->source_document_id
            && $existing->source_line_type === $original->source_line_type
            && $existing->source_line_id === $original->source_line_id
            && $existing->movement_role === $original->movement_role
            && $existing->currency_code === $original->currency_code
            && bccomp((string) $existing->quantity_change, bcmul((string) $original->quantity_change, '-1', 4), 4) === 0
            && bccomp((string) $existing->unit_cost, (string) $original->unit_cost, 4) === 0
            && bccomp((string) $existing->total_cost, bcmul((string) $original->total_cost, '-1', 2), 4) === 0;
    }
}
