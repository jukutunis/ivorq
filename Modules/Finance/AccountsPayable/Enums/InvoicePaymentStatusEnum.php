<?php

namespace Modules\Finance\AccountsPayable\Enums;

enum InvoicePaymentStatusEnum: string
{
    case Unpaid = 'UNPAID';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
}
