<?php

namespace Modules\Operations\Inventory\Enums;

enum SessionTypeEnum: string
{
    case FULL_COUNT = 'full_count';
    case CYCLE_COUNT = 'cycle_count';
    case SPOT_COUNT = 'spot_count';

    public function label(): string
    {
        return match ($this) {
            self::FULL_COUNT => 'Full Count',
            self::CYCLE_COUNT => 'Cycle Count',
            self::SPOT_COUNT => 'Spot Count',
        };
    }
}
