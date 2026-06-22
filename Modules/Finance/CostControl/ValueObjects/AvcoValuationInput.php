<?php

namespace Modules\Finance\CostControl\ValueObjects;

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
