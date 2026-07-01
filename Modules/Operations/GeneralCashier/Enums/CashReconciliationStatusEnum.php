<?php

namespace Modules\Operations\GeneralCashier\Enums;

enum CashReconciliationStatusEnum: string
{
    case RECONCILED = 'RECONCILED';
    case EXCEPTION = 'EXCEPTION';
}
