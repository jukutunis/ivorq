<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

class AvcoValuationInput
{
    public readonly string $transactionReference;
    public readonly ValuationSequence $sequence;
    public readonly string $eventType;
    public readonly AvcoDecimal $quantityDelta;
    public readonly ?AvcoDecimal $approvedValuationBasis;
    public readonly string $occurredAt;
    public readonly bool $isSourceFinancialPeriodClosed;
    public readonly ?string $currentOpenCorrectionPeriodId;
    public readonly ?string $originalBusinessDate;
    public readonly ?TransferValuationContext $transferContext;

    public function __construct(
        string $transactionReference,
        ValuationSequence $sequence,
        string $eventType,
        AvcoDecimal $quantityDelta,
        ?AvcoDecimal $approvedValuationBasis,
        string $occurredAt,
        bool $isSourceFinancialPeriodClosed,
        ?string $currentOpenCorrectionPeriodId = null,
        ?string $originalBusinessDate = null,
        ?TransferValuationContext $transferContext = null
    ) {
        if (empty($transactionReference)) {
            throw new InvalidArgumentException("transactionReference cannot be empty.");
        }
        if (empty($occurredAt)) {
            throw new InvalidArgumentException("occurredAt cannot be empty.");
        }
        if (!in_array($eventType, ['receipt', 'issue', 'positive_adjustment', 'negative_adjustment', 'transfer'], true)) {
            throw new InvalidArgumentException("Unknown event type: " . $eventType);
        }

        $this->transactionReference = $transactionReference;
        $this->sequence = $sequence;
        $this->eventType = $eventType;
        $this->quantityDelta = $quantityDelta;
        $this->approvedValuationBasis = $approvedValuationBasis;
        $this->occurredAt = $occurredAt;
        $this->isSourceFinancialPeriodClosed = $isSourceFinancialPeriodClosed;
        $this->currentOpenCorrectionPeriodId = $currentOpenCorrectionPeriodId;
        $this->originalBusinessDate = $originalBusinessDate;
        $this->transferContext = $transferContext;
    }
}
