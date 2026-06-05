<?php

namespace Modules\Operations\PMS\Enums;

enum RoomBlockTypeEnum: string
{
    case OutOfOrder   = 'out_of_order';
    case OutOfService = 'out_of_service';

    public function label(): string
    {
        return match ($this) {
            self::OutOfOrder   => 'Out Of Order',
            self::OutOfService => 'Out Of Service',
        };
    }
}
