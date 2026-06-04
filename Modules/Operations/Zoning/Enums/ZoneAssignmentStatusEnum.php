<?php

namespace Modules\Operations\Zoning\Enums;

enum ZoneAssignmentStatusEnum: string
{
    case Active    = 'active';
    case Inactive  = 'inactive';
    case Completed = 'completed';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Inactive  => 'Inactive',
            self::Completed => 'Completed',
        };
    }
}
