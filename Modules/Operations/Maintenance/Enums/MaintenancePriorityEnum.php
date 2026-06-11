<?php

namespace Modules\Operations\Maintenance\Enums;

enum MaintenancePriorityEnum: string
{
    case LOW = 'Low';
    case MEDIUM = 'Medium';
    case HIGH = 'High';
    case CRITICAL = 'Critical';
}
