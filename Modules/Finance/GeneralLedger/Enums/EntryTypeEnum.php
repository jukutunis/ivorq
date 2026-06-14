<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum EntryTypeEnum: string
{
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';
}
