<?php

namespace Modules\Operations\ContractorPTW\Enums;

enum RiskLevelEnum: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}
