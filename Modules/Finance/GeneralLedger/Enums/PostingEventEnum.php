<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum PostingEventEnum: string
{
    case AccountPayable = 'AccountPayable';
    case PaymentVoucher = 'PaymentVoucher';
}
