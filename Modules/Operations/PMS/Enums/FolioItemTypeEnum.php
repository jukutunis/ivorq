<?php

namespace Modules\Operations\PMS\Enums;

enum FolioItemTypeEnum: string
{
    case RoomCharge    = 'room_charge';
    case Tax           = 'tax';
    case ServiceCharge = 'service_charge';
    case Adjustment    = 'adjustment';
    case Payment       = 'payment';
    case Deposit       = 'deposit';
    case Other         = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RoomCharge    => 'Room Charge',
            self::Tax           => 'Tax',
            self::ServiceCharge => 'Service Charge',
            self::Adjustment    => 'Adjustment',
            self::Payment       => 'Payment',
            self::Deposit       => 'Deposit',
            self::Other         => 'Other',
        };
    }
}
