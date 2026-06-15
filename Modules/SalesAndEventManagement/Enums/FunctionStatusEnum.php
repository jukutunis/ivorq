<?php

namespace Modules\SalesAndEventManagement\Enums;

enum FunctionStatusEnum: string
{
    case Planned = 'PLANNED';
    case Confirmed = 'CONFIRMED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
