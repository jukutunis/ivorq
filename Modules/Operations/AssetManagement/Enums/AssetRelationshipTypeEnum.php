<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetRelationshipTypeEnum: string
{
    case DEPENDS_ON = 'Depends On';
    case CONNECTED_TO = 'Connected To';
    case BACKUP_FOR = 'Backup For';
    case REDUNDANT_TO = 'Redundant To';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
