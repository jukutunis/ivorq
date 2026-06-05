<?php

namespace Modules\Operations\PMS\Enums;

enum RatePlanTypeEnum: string
{
    case Nightly = 'nightly';
    case Hourly  = 'hourly';
    case DayUse  = 'day_use';
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::Nightly => 'Nightly',
            self::Hourly  => 'Hourly',
            self::DayUse  => 'Day Use',
            self::Package => 'Package',
        };
    }
}
