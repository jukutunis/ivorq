<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderPriorityEnum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Emergency => 'Emergency',
        };
    }
}
