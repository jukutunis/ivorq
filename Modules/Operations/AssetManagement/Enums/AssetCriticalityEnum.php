<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetCriticalityEnum: string
{
    case LOW = 'Low';
    case MEDIUM = 'Medium';
    case HIGH = 'High';
    case CRITICAL = 'Critical';
    case LIFE_SAFETY = 'LifeSafety';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
