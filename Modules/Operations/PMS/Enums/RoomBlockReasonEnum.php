<?php

namespace Modules\Operations\PMS\Enums;

enum RoomBlockReasonEnum: string
{
    case Maintenance = 'maintenance';
    case Cleaning    = 'cleaning';
    case Reserved    = 'reserved';
    case StaffUse    = 'staff_use';
    case Other       = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Maintenance',
            self::Cleaning    => 'Cleaning',
            self::Reserved    => 'Reserved',
            self::StaffUse    => 'Staff Use',
            self::Other       => 'Other',
        };
    }
}
