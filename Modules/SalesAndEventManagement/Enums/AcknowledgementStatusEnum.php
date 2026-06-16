<?php

namespace Modules\SalesAndEventManagement\Enums;

enum AcknowledgementStatusEnum: string
{
    case PENDING = 'PENDING';
    case VIEWED = 'VIEWED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case REJECTED = 'REJECTED';
    case ESCALATED = 'ESCALATED';
}
