<?php

namespace Modules\Operations\AssetManagement\Enums;

enum AssetCommissioningStatusEnum: string
{
    case DRAFT = 'Draft';
    case PENDING_VENDOR = 'Pending Vendor';
    case PENDING_ENGINEER = 'Pending Engineer';
    case APPROVED = 'Approved';
    case REJECTED = 'Rejected';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
