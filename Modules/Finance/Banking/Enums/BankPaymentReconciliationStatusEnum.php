<?php

namespace Modules\Finance\Banking\Enums;

enum BankPaymentReconciliationStatusEnum: string
{
    case RECONCILED = 'RECONCILED';
    case EXCEPTION = 'EXCEPTION';
}
