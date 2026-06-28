<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;

/**
 * Adapter boundary between a future controlled CostControl valuation runtime
 * and the existing Cost Ledger append path.
 *
 * Maps ControlledValuationCostLedgerIntent → CostLedgerEntryIntent and
 * delegates exclusively through CostLedgerAppendService. Does not call
 * CostLedgerRepository directly.
 *
 * No production caller exists in this slice.
 */
final class ControlledValuationCostLedgerAdapter
{
    public function __construct(
        private readonly CostLedgerAppendService $appendService,
    ) {}

    /**
     * Append one controlled valuation entry through the existing append service.
     *
     * Duplicate idempotency key + sequence failures propagate as thrown by
     * CostLedgerAppendService without retry or suppression.
     */
    public function append(ControlledValuationCostLedgerIntent $intent): CostLedgerEntry
    {
        $legacyIntent = new CostLedgerEntryIntent(
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

        return $this->appendService->append($legacyIntent);
    }
}
