<?php

namespace Shared\Enums;

enum DepartmentTypeEnum: string
{
    case Operational = 'operational';
    case Administrative = 'administrative';
    case Support = 'support';
    case Revenue = 'revenue';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operational',
            self::Administrative => 'Administrative',
            self::Support => 'Support',
            self::Revenue => 'Revenue',
        };
    }
}
