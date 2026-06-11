<?php

namespace Modules\Operations\Maintenance\Enums;

enum MaintenanceFrequencyEnum: string
{
    case DAILY = 'Daily';
    case WEEKLY = 'Weekly';
    case MONTHLY = 'Monthly';
    case QUARTERLY = 'Quarterly';
    case SEMI_ANNUALLY = 'Semi-Annually';
    case ANNUALLY = 'Annually';
    case CUSTOM = 'Custom';
}
