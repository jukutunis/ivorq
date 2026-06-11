<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum FinancialPeriodStatusEnum: string
{
    case Open = 'Open';
    case Closing = 'Closing';
    case Closed = 'Closed';
    case Reopened = 'Reopened';
}
