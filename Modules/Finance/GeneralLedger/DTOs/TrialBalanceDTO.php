<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class TrialBalanceDTO
{
    /**
     * @param TrialBalanceLineDTO[] $lines
     */
    public function __construct(
        public array $lines,
        public float $total_debit,
        public float $total_credit,
        public bool $balanced,
    ) {}
}
