<?php

namespace Modules\Operations\Inventory\Enums;

enum LocationTypeEnum: string
{
    case MainStore       = 'main_store';
    case DepartmentStore = 'department_store';
    case MinibarStore    = 'minibar_store';
    case LaundryStore    = 'laundry_store';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MainStore       => 'Main Store',
            self::DepartmentStore => 'Department Store',
            self::MinibarStore    => 'Minibar Store',
            self::LaundryStore    => 'Laundry Store',
            self::Other           => 'Other',
        };
    }
}
