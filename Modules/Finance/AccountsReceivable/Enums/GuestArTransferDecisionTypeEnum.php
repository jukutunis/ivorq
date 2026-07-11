<?php

namespace Modules\Finance\AccountsReceivable\Enums;

enum GuestArTransferDecisionTypeEnum: string
{
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Reversed = 'REVERSED';
}
