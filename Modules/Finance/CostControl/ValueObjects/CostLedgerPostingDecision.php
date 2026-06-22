<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;

class CostLedgerPostingDecision
{
    public const STATUS_ALLOW = 'allow';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CORRECTION_REQUIRED = 'correction_required';

    public readonly string $status;
    public readonly string $reasonCode;
    public readonly bool $historicalStateUnchanged;
    public readonly ?string $targetCorrectionBusinessDate;
    public readonly ?string $targetCorrectionPeriodId;
    public readonly ?string $originalTransactionReference;
    public readonly ?string $originalBusinessDate;

    public function __construct(
        string $status,
        string $reasonCode,
        bool $historicalStateUnchanged = false,
        ?string $targetCorrectionBusinessDate = null,
        ?string $targetCorrectionPeriodId = null,
        ?string $originalTransactionReference = null,
        ?string $originalBusinessDate = null
    ) {
        if (!in_array($status, [self::STATUS_ALLOW, self::STATUS_PENDING, self::STATUS_REJECTED, self::STATUS_CORRECTION_REQUIRED], true)) {
            throw new InvalidArgumentException("Invalid decision status");
        }
        
        $this->status = $status;
        $this->reasonCode = $reasonCode;
        $this->historicalStateUnchanged = $historicalStateUnchanged;
        $this->targetCorrectionBusinessDate = $targetCorrectionBusinessDate;
        $this->targetCorrectionPeriodId = $targetCorrectionPeriodId;
        $this->originalTransactionReference = $originalTransactionReference;
        $this->originalBusinessDate = $originalBusinessDate;
    }
}