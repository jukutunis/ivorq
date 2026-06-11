<?php

namespace Modules\Operations\Maintenance\Enums;

enum MaintenanceExecutionStatusEnum: string
{
    case PENDING = 'Pending';
    case IN_PROGRESS = 'In Progress';
    case COMPLETED = 'Completed';
    case CANCELLED = 'Cancelled';
}
