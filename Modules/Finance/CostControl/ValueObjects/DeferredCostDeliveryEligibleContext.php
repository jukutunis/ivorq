<?php

namespace Modules\Finance\CostControl\ValueObjects;

final readonly class DeferredCostDeliveryEligibleContext
{
    public function __construct(
        public string $outboxMessageId,
        public string $sourceInventoryTransactionId,
        public string $propertyId,
        public string $locationId,
        public string $itemId,
        public string $valuationScope,
        public int $valuationSequence,
        public string $ownershipId,
        public int $ownershipVersion,
        public string $cutoverId,
        public string $dispositionId,
        public string $processingState,
        public CostLedgerSourceEquivalence $sourceEquivalence,
        public int $expectedSequence,
        public bool $alreadySatisfied,
        public bool $requiresPairedApplication,
        public ?string $pairedInventoryTransactionId,
    ) {}
}
