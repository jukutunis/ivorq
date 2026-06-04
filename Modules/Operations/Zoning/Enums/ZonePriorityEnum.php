<?php

namespace Modules\Operations\Zoning\Enums;

enum ZonePriorityEnum: int
{
    case Highest = 1;
    case High    = 2;
    case Medium  = 3;
    case Low     = 4;
    case Lowest  = 5;

    public function label(): string
    {
        return match($this) {
            self::Highest => 'Highest',
            self::High    => 'High',
            self::Medium  => 'Medium',
            self::Low     => 'Low',
            self::Lowest  => 'Lowest',
        };
    }
}
