<?php

namespace Modules\Operations\GeneralCashier\Enums;

enum CashierPaymentInstrumentTypeEnum: string
{
    case CASH = 'CASH';
    case BANK = 'BANK';
}
