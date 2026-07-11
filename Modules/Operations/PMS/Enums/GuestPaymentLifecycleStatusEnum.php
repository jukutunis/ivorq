<?php

namespace Modules\Operations\PMS\Enums;

enum GuestPaymentLifecycleStatusEnum: string
{
    case Recorded = 'RECORDED';
    case PartiallyAllocated = 'PARTIALLY_ALLOCATED';
    case FullyAllocated = 'FULLY_ALLOCATED';
    case Voided = 'VOIDED';
}
