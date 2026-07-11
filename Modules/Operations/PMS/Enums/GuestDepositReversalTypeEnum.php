<?php

namespace Modules\Operations\PMS\Enums;

enum GuestDepositReversalTypeEnum: string
{
    case DepositVoid = 'DEPOSIT_VOID';
    case ApplicationReversal = 'APPLICATION_REVERSAL';
}
