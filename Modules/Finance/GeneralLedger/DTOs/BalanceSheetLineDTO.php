<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class BalanceSheetLineDTO
{
    public function __construct(
        public string $account_code,
        public string $account_name,
        public string $account_type,
        public float $balance,
    ) {}
}
