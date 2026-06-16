<?php

namespace Modules\SalesAndEventManagement\Enums;

enum TaskPriorityEnum: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';
}
