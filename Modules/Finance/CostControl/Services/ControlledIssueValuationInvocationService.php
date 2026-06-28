<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Finance\CostControl\Services\ControlledValuationApplyCoordinator;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use RuntimeException;

/**
 * final invocation service to coordinate locking state, reading WAUC,
 * posting stock issues, and applying controlled issue valuations atomically.
 */
final class ControlledIssueValuationInvocationService
{
    public function __construct(
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly ControlledValuationApplyCoordinator $applyCoordinator,
        private readonly CostAvcoStateRepository $stateRepository
    ) {}

    /**
     * Lock state, read WAUC, post transaction, and apply valuation plan.
     */
    public function invokeIssue(
        string $propertyId,
        string $locationId,
        string $itemId,
        array $documentData,
        string $qtyStr,
        ?string $actorId = null
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('ControlledIssueValuationInvocationService::invokeIssue requires an active transaction.');
        }

        // 1. Lock existing seeded CostAvcoState first!
        $lockedState = $this->stateRepository->lockExistingSeededStateForScope($propertyId, $locationId, $itemId);

        // 2. Require its current quantity to be positive and its WAUC to exist and be positive.
        $qtyBefore = new AvcoDecimal((string) $lockedState->on_hand_quantity);
        if ($qtyBefore->isZero() || $qtyBefore->isNegative()) {
            throw new RuntimeException("Cannot issue from zero or negative stock.");
        }

        if ($lockedState->weighted_average_unit_cost === null) {
            throw new RuntimeException("Weighted average unit cost is null for locked state.");
        }

        $wac = new AvcoDecimal((string) $lockedState->weighted_average_unit_cost);
        if ($wac->isZero() || $wac->isNegative()) {
            throw new RuntimeException("Weighted average unit cost is zero or negative for locked state.");
        }

        // 3. Derive issue unit cost only from this locked state WAUC
        // 4. Derive negative issue total value only from: exact issue quantity * exact locked-state WAUC
        $qtyChange = (string) (-1 * abs((float) $qtyStr));
        $qtyChangeDec = new AvcoDecimal($qtyChange);

        $issueValueDec = $qtyChangeDec->abs()->mul($wac);
        $valueDeltaDec = AvcoDecimal::zero()->sub($issueValueDec);

        // 5. Build canonical InventoryLedgerPostingIntent
        $intent = new InventoryLedgerPostingIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            locationId: $locationId,
            businessDate: $documentData['businessDate'],
            occurredAt: $documentData['occurredAt'],
            sourceDocumentType: 'inventory_issue',
            sourceDocumentId: $documentData['documentId'],
            sourceLineType: 'inventory_issue_line',
            sourceLineId: $documentData['lineId'],
            movementRole: \Modules\Operations\Inventory\Enums\TransactionTypeEnum::Issue->value,
            idempotencyKey: $documentData['idempotencyKey'],
            transactionType: \Modules\Operations\Inventory\Enums\TransactionTypeEnum::Issue,
            quantityChange: $qtyChangeDec->getValue(),
            unitCost: $wac->getValue(),
            totalCost: $valueDeltaDec->getValue(),
            reference: $documentData['reference'],
            notes: $documentData['notes'] ?? 'Inventory Issue Posting'
        );

        // 6. Create the canonical InventoryTransaction
        $tx = $this->postingCoordinator->post($intent, $actorId);

        // 7. Apply valuation through the coordinator using the same already-locked state row
        $ledgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $tx->property_id,
            sourceInventoryTransactionId: $tx->id,
            priorCostLedgerEntryId: null,
            entryType: 'issue',
            idempotencyKey: $tx->idempotency_key,
            entrySequence: (int) $tx->valuation_sequence,
            currencyCode: $tx->currency_code,
            quantityDelta: $qtyChangeDec,
            unitCost: $wac,
            valueDelta: $valueDeltaDec,
            businessDate: $tx->business_date->format('Y-m-d'),
            occurredAt: $tx->occurred_at->format('Y-m-d H:i:s')
        );

        $this->applyCoordinator->applyUsingLockedState($lockedState, $ledgerIntent);
    }
}
