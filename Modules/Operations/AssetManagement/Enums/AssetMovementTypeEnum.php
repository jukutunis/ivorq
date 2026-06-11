<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetMovementTypeEnum: string
{
    case TRANSFER = 'Transfer';
    case RELOCATION = 'Relocation';
    case LOAN = 'Loan';
    case RETURN = 'Return';
    case TEMPORARY_MOVE = 'Temporary Move';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
