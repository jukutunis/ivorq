<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class CashFlowLineDTO
{
    public function __construct(
        public string $account_code,
        public string $account_name,
        public string $account_category,
        public float $amount,
    ) {}
}
