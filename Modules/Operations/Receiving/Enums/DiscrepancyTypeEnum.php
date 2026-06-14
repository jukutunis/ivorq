<?php

namespace Modules\Operations\Receiving\Enums;

enum DiscrepancyTypeEnum: string
{
    case Shortage = 'SHORTAGE';
    case Overage = 'OVERAGE';
    case Damaged = 'DAMAGED';
    case WrongItem = 'WRONG_ITEM';
    case Expired = 'EXPIRED';
    case QualityIssue = 'QUALITY_ISSUE';

    public function label(): string
    {
        return match ($this) {
            self::Shortage => 'Shortage',
            self::Overage => 'Overage',
            self::Damaged => 'Damaged',
            self::WrongItem => 'Wrong Item',
            self::Expired => 'Expired',
            self::QualityIssue => 'Quality Issue',
        };
    }
}
