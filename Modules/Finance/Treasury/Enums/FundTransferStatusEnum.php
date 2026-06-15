<?php

namespace Modules\Finance\Treasury\Enums;

enum FundTransferStatusEnum: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Executed = 'EXECUTED';
    case Reconciled = 'RECONCILED';
    case Cancelled = 'CANCELLED';
}
