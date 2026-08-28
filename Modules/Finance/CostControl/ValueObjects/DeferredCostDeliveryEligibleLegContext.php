<?php

namespace Modules\Finance\CostControl\ValueObjects;

final readonly class DeferredCostDeliveryEligibleLegContext
{
    public function __construct(
        public string $outboxMessageId,
        public string $sourceInventoryTransactionId,
        public string $propertyId,
        public string $locationId,
        public string $itemId,
        public string $valuationScope,
        public int $valuationSequence,
        public string $cutoverScopeId,
        public string $enrollmentScopeSnapshotId,
        public int $firstDeferredOwnedSequence,
        public string $dispositionId,
        public string $processingState,
        public CostLedgerSourceEquivalence $sourceEquivalence,
        public int $expectedSequence,
        public bool $alreadySatisfied,
    ) {}
}
