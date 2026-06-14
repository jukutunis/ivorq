<?php

namespace Modules\Finance\AccountsPayable\Enums;

enum ApInvoiceTypeEnum: string
{
    case GRNI_MATCHED = 'grni_matched';
    case DIRECT_EXPENSE = 'direct_expense';
}
