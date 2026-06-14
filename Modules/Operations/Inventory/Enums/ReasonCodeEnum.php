<?php

namespace Modules\Operations\Inventory\Enums;

enum ReasonCodeEnum: string
{
    case COUNT_ERROR = 'count_error';
    case DAMAGE = 'damage';
    case EXPIRED = 'expired';
    case LOSS = 'loss';
    case THEFT = 'theft';
    case OBSOLETE = 'obsolete';
    case UOM_CONVERSION_ERROR = 'uom_conversion_error';
    case SUPPLIER_SHORTAGE = 'supplier_shortage';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::COUNT_ERROR => 'Count Error',
            self::DAMAGE => 'Damage',
            self::EXPIRED => 'Expired',
            self::LOSS => 'Loss',
            self::THEFT => 'Theft',
            self::OBSOLETE => 'Obsolete',
            self::UOM_CONVERSION_ERROR => 'UOM Conversion Error',
            self::SUPPLIER_SHORTAGE => 'Supplier Shortage',
            self::OTHER => 'Other',
        };
    }
}
