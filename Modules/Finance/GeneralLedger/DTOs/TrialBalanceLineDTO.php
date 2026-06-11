<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class TrialBalanceLineDTO
{
    public function __construct(
        public string $account_code,
        public string $account_name,
        public string $account_type,
        public float $opening_balance,
        public float $debit_activity,
        public float $credit_activity,
        public float $ending_balance,
    ) {}
}
