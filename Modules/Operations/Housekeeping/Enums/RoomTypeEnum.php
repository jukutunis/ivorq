<?php

namespace Modules\Operations\Housekeeping\Enums;

enum RoomTypeEnum: string
{
    case Standard  = 'standard';
    case Deluxe    = 'deluxe';
    case Suite     = 'suite';
    case Villa     = 'villa';
    case Dormitory = 'dormitory';
    case Custom    = 'custom';

    public function label(): string
    {
        return match($this) {
            self::Standard  => 'Standard',
            self::Deluxe    => 'Deluxe',
            self::Suite     => 'Suite',
            self::Villa     => 'Villa',
            self::Dormitory => 'Dormitory',
            self::Custom    => 'Custom',
        };
    }
}
