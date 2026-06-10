<?php

namespace Modules\Finance\Payables\Enums;

enum PaymentMethodEnum: string
{
    case Cash = 'Cash';
    case BankTransfer = 'BankTransfer';
    case Cheque = 'Cheque';
    case CreditCard = 'CreditCard';
    case Other = 'Other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
