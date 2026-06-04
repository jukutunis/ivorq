<?php

namespace Modules\Operations\Housekeeping\Enums;

enum AssignmentStatusEnum: string
{
    case Active    = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
