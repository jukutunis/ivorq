<?php

namespace Modules\Operations\Housekeeping\Enums;

enum InspectionSeverityEnum: string
{
    case Minor    = 'minor';
    case Major    = 'major';
    case Critical = 'critical';

    public function label(): string
    {
        return match($this) {
            self::Minor    => 'Minor',
            self::Major    => 'Major',
            self::Critical => 'Critical',
        };
    }
}
