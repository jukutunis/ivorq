<?php

namespace Modules\Finance\CostControl\ValueObjects;

use DateTimeInterface;
use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;

final readonly class CostDeliveryDispositionDecision
{
    public const PROVENANCE_EXACT_COST_LEDGER = 'EXACT_COST_LEDGER_EQUIVALENCE';

    public const PROVENANCE_SOURCE_TYPE_NON_ELIGIBLE = 'SOURCE_TRANSACTION_TYPE_NOT_COSTCONTROL_ELIGIBLE';

    public const PROVENANCE_DEFERRED_SOURCE_STAMP_AND_CUTOVER_PROOF = 'DEFERRED_SOURCE_STAMP_AND_CUTOVER_PROOF';

    private function __construct(
        public string $outboxMessageId,
        public string $sourceInventoryTransactionId,
        public string $propertyId,
        public string $locationId,
        public string $itemId,
        public string $valuationScope,
        public int $valuationSequence,
        public CostDeliveryDispositionClass $classification,
        public CostDeliveryProcessingState $processingState,
        public ?string $costDeliveryOwnershipId,
        public ?int $costDeliveryOwnershipVersion,
        public ?string $costDeliveryCutoverId,
        public ?string $equivalentCostLedgerEntryId,
        public string $classifiedBy,
        public string $classificationProvenance,
        public DateTimeInterface $classifiedAt,
    ) {}

    public static function synchronouslySatisfied(
        string $outboxMessageId,
        string $sourceInventoryTransactionId,
        string $propertyId,
        string $locationId,
        string $itemId,
        string $valuationScope,
        int $valuationSequence,
        ?string $costDeliveryOwnershipId,
        ?int $costDeliveryOwnershipVersion,
        string $equivalentCostLedgerEntryId,
        string $classifiedBy,
        DateTimeInterface $classifiedAt,
    ): self {
        return new self(
            $outboxMessageId,
            $sourceInventoryTransactionId,
            $propertyId,
            $locationId,
            $itemId,
            $valuationScope,
            $valuationSequence,
            CostDeliveryDispositionClass::SynchronouslySatisfiedHistory,
            CostDeliveryProcessingState::HistoricalExcluded,
            $costDeliveryOwnershipId,
            $costDeliveryOwnershipVersion,
            null,
            $equivalentCostLedgerEntryId,
            $classifiedBy,
            self::PROVENANCE_EXACT_COST_LEDGER,
            $classifiedAt,
        );
    }

    public static function nonCostControlEligible(
        string $outboxMessageId,
        string $sourceInventoryTransactionId,
        string $propertyId,
        string $locationId,
        string $itemId,
        string $valuationScope,
        int $valuationSequence,
        string $classifiedBy,
        DateTimeInterface $classifiedAt,
    ): self {
        return new self(
            $outboxMessageId,
            $sourceInventoryTransactionId,
            $propertyId,
            $locationId,
            $itemId,
            $valuationScope,
            $valuationSequence,
            CostDeliveryDispositionClass::UnenrolledOrNonCostControlEligibleHistory,
            CostDeliveryProcessingState::HistoricalExcluded,
            null,
            null,
            null,
            null,
            $classifiedBy,
            self::PROVENANCE_SOURCE_TYPE_NON_ELIGIBLE,
            $classifiedAt,
        );
    }

    public static function deferredOwnedAfterCutover(
        string $outboxMessageId,
        string $sourceInventoryTransactionId,
        string $propertyId,
        string $locationId,
        string $itemId,
        string $valuationScope,
        int $valuationSequence,
        string $costDeliveryOwnershipId,
        int $costDeliveryOwnershipVersion,
        string $costDeliveryCutoverId,
        string $classifiedBy,
        DateTimeInterface $classifiedAt,
    ): self {
        return new self(
            $outboxMessageId,
            $sourceInventoryTransactionId,
            $propertyId,
            $locationId,
            $itemId,
            $valuationScope,
            $valuationSequence,
            CostDeliveryDispositionClass::DeferredOwnedAfterCutover,
            CostDeliveryProcessingState::Pending,
            $costDeliveryOwnershipId,
            $costDeliveryOwnershipVersion,
            $costDeliveryCutoverId,
            null,
            $classifiedBy,
            self::PROVENANCE_DEFERRED_SOURCE_STAMP_AND_CUTOVER_PROOF,
            $classifiedAt,
        );
    }
}
