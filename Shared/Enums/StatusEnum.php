<?php

namespace Shared\Enums;

enum StatusEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Inactive  => 'Inactive',
            self::Pending   => 'Pending',
            self::Suspended => 'Suspended',
        };
    }
}
