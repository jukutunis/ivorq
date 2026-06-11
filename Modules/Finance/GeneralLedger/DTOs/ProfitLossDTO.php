<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class ProfitLossDTO
{
    /**
     * @param ProfitLossLineDTO[] $revenue_lines
     * @param ProfitLossLineDTO[] $cost_of_sales_lines
     * @param ProfitLossLineDTO[] $expense_lines
     */
    public function __construct(
        public array $revenue_lines,
        public array $cost_of_sales_lines,
        public array $expense_lines,
        
        public float $period_total_revenue,
        public float $ytd_total_revenue,
        
        public float $period_total_cost_of_sales,
        public float $ytd_total_cost_of_sales,
        
        public float $period_gross_profit,
        public float $ytd_gross_profit,
        
        public float $period_total_expense,
        public float $ytd_total_expense,
        
        public float $period_net_profit,
        public float $ytd_net_profit,
    ) {}
}
