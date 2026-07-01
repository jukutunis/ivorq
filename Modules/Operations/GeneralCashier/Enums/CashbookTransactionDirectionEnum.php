<?php

namespace Modules\Operations\GeneralCashier\Enums;

enum CashbookTransactionDirectionEnum: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
}
