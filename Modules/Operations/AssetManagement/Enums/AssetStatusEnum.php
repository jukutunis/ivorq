<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetStatusEnum: string
{
    case PLANNED = 'Planned';
    case ORDERED = 'Ordered';
    case RECEIVED = 'Received';
    case INSTALLED = 'Installed';
    case COMMISSIONED = 'Commissioned';
    case ACTIVE = 'Active';
    case UNDER_MAINTENANCE = 'Under Maintenance';
    case OUT_OF_SERVICE = 'Out Of Service';
    case DISPOSED = 'Disposed';
    case RETIRED = 'Retired';
    case LOST = 'Lost';
    case TRANSFERRED = 'Transferred';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
