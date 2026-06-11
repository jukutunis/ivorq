<?php

namespace Modules\Operations\WorkOrder\Enums;

enum GuestImpactLevelEnum: string
{
    case None = 'none';
    case Low = 'low';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match($this) {
            self::None => 'None',
            self::Low => 'Low',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }
}
