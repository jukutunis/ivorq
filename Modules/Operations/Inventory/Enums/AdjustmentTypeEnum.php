<?php

namespace Modules\Operations\Inventory\Enums;

enum AdjustmentTypeEnum: string
{
    case StockTake  = 'stock_take';
    case Damaged    = 'damaged';
    case Lost       = 'lost';
    case Found      = 'found';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::StockTake  => 'Stock Take',
            self::Damaged    => 'Damaged',
            self::Lost       => 'Lost',
            self::Found      => 'Found',
            self::Correction => 'Correction',
        };
    }
}
