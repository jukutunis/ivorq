<?php

namespace Modules\Operations\PMS\Enums;

enum GuestDepositLifecycleStatusEnum: string
{
    case Recorded = 'RECORDED';
    case PartiallyResolved = 'PARTIALLY_RESOLVED';
    case Resolved = 'RESOLVED';
    case Voided = 'VOIDED';
}
