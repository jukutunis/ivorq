<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetConditionEnum: string
{
    case EXCELLENT = 'Excellent';
    case GOOD = 'Good';
    case FAIR = 'Fair';
    case POOR = 'Poor';
    case CRITICAL = 'Critical';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
