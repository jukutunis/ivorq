<?php

namespace Modules\SalesAndEventManagement\Enums;

enum DistributionSeverityEnum: string
{
    case MINOR = 'MINOR';
    case MAJOR = 'MAJOR';
    case CRITICAL = 'CRITICAL';
}
