<?php

namespace Modules\Operations\PMS\Enums;

enum GuestPaymentReversalTypeEnum: string
{
    case PaymentVoid = 'PAYMENT_VOID';
    case AllocationReversal = 'ALLOCATION_REVERSAL';
}
