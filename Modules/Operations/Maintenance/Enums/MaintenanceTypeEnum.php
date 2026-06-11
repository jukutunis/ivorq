<?php

namespace Modules\Operations\Maintenance\Enums;

enum MaintenanceTypeEnum: string
{
    case TIME_BASED = 'Time Based';
    case METER_BASED = 'Meter Based';
    case CONDITION_BASED = 'Condition Based';
}
