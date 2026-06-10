<?php

namespace Modules\Operations\Purchasing\Enums;

enum GoodsReceiptStatusEnum: string
{
    case Draft = 'Draft';
    case Posted = 'Posted';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
