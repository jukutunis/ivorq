<?php

namespace Modules\Operations\PMS\Enums;

enum GuestTypeEnum: string
{
    case Individual = 'individual';
    case Corporate  = 'corporate';
    case Group      = 'group';
    case Vip        = 'vip';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Corporate  => 'Corporate',
            self::Group      => 'Group',
            self::Vip        => 'VIP',
        };
    }
}
