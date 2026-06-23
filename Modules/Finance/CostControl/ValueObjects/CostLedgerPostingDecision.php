<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;
use DateTime;

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
        
        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException("reasonCode is mandatory and non-empty");
        }

        if ($targetCorrectionBusinessDate !== null && (!($d = DateTime::createFromFormat('Y-m-d', $targetCorrectionBusinessDate)) || $d->format('Y-m-d') !== $targetCorrectionBusinessDate)) {
            throw new InvalidArgumentException("targetCorrectionBusinessDate must be a valid calendar date");
        }

        if ($originalBusinessDate !== null && (!($d = DateTime::createFromFormat('Y-m-d', $originalBusinessDate)) || $d->format('Y-m-d') !== $originalBusinessDate)) {
            throw new InvalidArgumentException("originalBusinessDate must be a valid calendar date");
        }

        if ($status === self::STATUS_ALLOW) {
            if ($historicalStateUnchanged !== false) throw new InvalidArgumentException("allow requires historicalStateUnchanged = false");
            if ($targetCorrectionBusinessDate !== null || $targetCorrectionPeriodId !== null || $originalTransactionReference !== null || $originalBusinessDate !== null) {
                throw new InvalidArgumentException("allow requires no correction target/date/reference fields");
            }
        }

        if ($status === self::STATUS_PENDING || $status === self::STATUS_REJECTED) {
            if ($historicalStateUnchanged !== true) throw new InvalidArgumentException("pending/rejected requires historicalStateUnchanged = true");
            if ($targetCorrectionBusinessDate !== null || $targetCorrectionPeriodId !== null) {
                throw new InvalidArgumentException("pending/rejected requires no correction target/date");
            }
        }

        if ($status === self::STATUS_CORRECTION_REQUIRED) {
            if ($historicalStateUnchanged !== true) throw new InvalidArgumentException("correction_required requires historicalStateUnchanged = true");
            if ($targetCorrectionBusinessDate === null || trim($targetCorrectionBusinessDate) === '') throw new InvalidArgumentException("correction business date required");
            if ($targetCorrectionPeriodId === null || trim($targetCorrectionPeriodId) === '') throw new InvalidArgumentException("correction FinancialPeriod ID required");
            if ($originalTransactionReference === null || trim($originalTransactionReference) === '') throw new InvalidArgumentException("original transaction reference required");
            if ($originalBusinessDate === null || trim($originalBusinessDate) === '') throw new InvalidArgumentException("original business date required");
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