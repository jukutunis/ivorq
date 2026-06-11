<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class BalanceSheetDTO
{
    /**
     * @param BalanceSheetLineDTO[] $asset_lines
     * @param BalanceSheetLineDTO[] $liability_lines
     * @param BalanceSheetLineDTO[] $equity_lines
     */
    public function __construct(
        public array $asset_lines,
        public array $liability_lines,
        public array $equity_lines,
        
        public float $prior_year_retained_earnings,
        public float $current_year_earnings,
        
        public float $total_assets,
        public float $total_liabilities,
        public float $total_equity,
        
        public bool $balanced,
    ) {}
}
