<?php

namespace Modules\Finance\CostControl\ValueObjects;

class AvcoValuationResult
{
    public const STATUS_FINAL = 'final';
    public const STATUS_PROVISIONAL = 'provisional';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CORRECTION_REQUIRED = 'correction_required';
    public const STATUS_REJECTED = 'rejected';

    public readonly string $status;
    public readonly AvcoValuationState $newState;
    public readonly ?AvcoDecimal $signedCarryingValueDelta;
    public readonly ?string $reasonCode;
    public readonly ?AvcoDecimal $unresolvedProvisionalQuantity;
    public readonly ?AvcoDecimal $sourceCarryingUnitCost;
    public readonly ?string $correctionTargetPeriodId;
    public readonly ?string $originalTransactionReference;
    public readonly ?string $originalBusinessDate;
    public readonly bool $historicalStateUnchanged;

    public function __construct(
        string $status,
        AvcoValuationState $newState,
        ?AvcoDecimal $signedCarryingValueDelta = null,
        ?string $reasonCode = null,
        ?AvcoDecimal $unresolvedProvisionalQuantity = null,
        ?AvcoDecimal $sourceCarryingUnitCost = null,
        ?string $correctionTargetPeriodId = null,
        ?string $originalTransactionReference = null,
        ?string $originalBusinessDate = null,
        bool $historicalStateUnchanged = false
    ) {
        $this->status = $status;
        $this->newState = $newState;
        $this->signedCarryingValueDelta = $signedCarryingValueDelta;
        $this->reasonCode = $reasonCode;
        $this->unresolvedProvisionalQuantity = $unresolvedProvisionalQuantity;
        $this->sourceCarryingUnitCost = $sourceCarryingUnitCost;
        $this->correctionTargetPeriodId = $correctionTargetPeriodId;
        $this->originalTransactionReference = $originalTransactionReference;
        $this->originalBusinessDate = $originalBusinessDate;
        $this->historicalStateUnchanged = $historicalStateUnchanged;
    }
}
