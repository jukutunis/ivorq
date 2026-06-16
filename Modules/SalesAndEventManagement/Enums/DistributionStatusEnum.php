<?php

namespace Modules\SalesAndEventManagement\Enums;

enum DistributionStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case DISTRIBUTED = 'DISTRIBUTED';
    case PARTIALLY_ACKNOWLEDGED = 'PARTIALLY_ACKNOWLEDGED';
    case FULLY_ACKNOWLEDGED = 'FULLY_ACKNOWLEDGED';
    case ESCALATED = 'ESCALATED';
    case SUPERSEDED = 'SUPERSEDED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
