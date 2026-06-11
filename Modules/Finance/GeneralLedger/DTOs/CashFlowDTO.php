<?php

namespace Modules\Finance\GeneralLedger\DTOs;

class CashFlowDTO
{
    /**
     * @param CashFlowLineDTO[] $operating_lines
     * @param CashFlowLineDTO[] $investing_lines
     * @param CashFlowLineDTO[] $financing_lines
     */
    public function __construct(
        public float $opening_cash,
        
        public float $net_profit,
        public array $operating_lines,
        public float $net_cash_operating,
        
        public array $investing_lines,
        public float $net_cash_investing,
        
        public array $financing_lines,
        public float $net_cash_financing,
        
        public float $net_cash_change,
        public float $closing_cash,
        
        public bool $balanced,
    ) {}
}
