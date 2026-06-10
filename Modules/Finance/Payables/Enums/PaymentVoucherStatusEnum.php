<?php

namespace Modules\Finance\Payables\Enums;

enum PaymentVoucherStatusEnum: string
{
    case Draft = 'Draft';
    case Posted = 'Posted';
    case Cancelled = 'Cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
