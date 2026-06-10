<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum NormalBalanceEnum: string
{
    case Debit = 'Debit';
    case Credit = 'Credit';
    case None = 'None';
}
