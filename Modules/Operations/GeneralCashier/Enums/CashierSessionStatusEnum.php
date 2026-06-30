<?php

namespace Modules\Operations\GeneralCashier\Enums;

enum CashierSessionStatusEnum: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
