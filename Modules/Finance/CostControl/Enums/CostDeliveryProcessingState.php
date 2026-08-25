<?php

namespace Modules\Finance\CostControl\Enums;

enum CostDeliveryProcessingState: string
{
    case HistoricalExcluded = 'HISTORICAL_EXCLUDED';
    case Pending = 'PENDING';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case BlockedSequence = 'BLOCKED_SEQUENCE';
}
