<?php

namespace Modules\Operations\PMS\Enums;

enum GuestRefundSourceTypeEnum: string
{
    case GuestPayment = 'GUEST_PAYMENT';
    case GuestDeposit = 'GUEST_DEPOSIT';
}
