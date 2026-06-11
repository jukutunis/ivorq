<?php

namespace Modules\Operations\Maintenance\Enums;

enum MaintenanceTaskStatusEnum: string
{
    case ACTIVE = 'Active';
    case INACTIVE = 'Inactive';
}
