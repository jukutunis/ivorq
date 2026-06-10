<?php

namespace Modules\Finance\Payables\Enums;

enum AccountPayableStatusEnum: string
{
    case Open = 'Open';
    case PartiallyPaid = 'PartiallyPaid';
    case Paid = 'Paid';
    case Cancelled = 'Cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
