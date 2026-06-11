<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryCriticalityEnum: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
}
