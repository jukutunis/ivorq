<?php

namespace Modules\Operations\Inventory\Enums;

enum ItemStatusEnum: string
{
    case InStock     = 'in_stock';
    case LowStock    = 'low_stock';
    case OutOfStock  = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::InStock    => 'In Stock',
            self::LowStock   => 'Low Stock',
            self::OutOfStock => 'Out of Stock',
        };
    }
}
