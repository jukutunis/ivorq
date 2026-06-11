<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class ProfitLossLineDTO
{
    public function __construct(
        public string $account_code,
        public string $account_name,
        public string $account_type,
        public float $period_amount,
        public float $ytd_amount,
    ) {}
}
