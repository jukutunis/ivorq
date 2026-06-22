<?php

namespace Modules\Finance\CostControl\ValueObjects;

class AvcoValuationInput
{
    public readonly string $transactionReference;
    public readonly ValuationSequence $sequence;
    public readonly string $eventType;
    public readonly float $quantityDelta;
    public readonly ?float $approvedValuationBasis;
    public readonly string $occurredAt;
    public readonly bool $isSourceFinancialPeriodClosed;
    public readonly ?string $currentOpenCorrectionPeriodId;

    public function __construct(
        string $transactionReference,
        ValuationSequence $sequence,
        string $eventType,
        float $quantityDelta,
        ?float $approvedValuationBasis,
        string $occurredAt,
        bool $isSourceFinancialPeriodClosed,
        ?string $currentOpenCorrectionPeriodId = null
    ) {
        $this->transactionReference = $transactionReference;
        $this->sequence = $sequence;
        $this->eventType = $eventType;
        $this->quantityDelta = $quantityDelta;
        $this->approvedValuationBasis = $approvedValuationBasis;
        $this->occurredAt = $occurredAt;
        $this->isSourceFinancialPeriodClosed = $isSourceFinancialPeriodClosed;
        $this->currentOpenCorrectionPeriodId = $currentOpenCorrectionPeriodId;
    }
}
