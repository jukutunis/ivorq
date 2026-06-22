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
    public readonly float $transactionValue;

    public function __construct(string $status, AvcoValuationState $newState, float $transactionValue)
    {
        $this->status = $status;
        $this->newState = $newState;
        $this->transactionValue = $transactionValue;
    }
}
