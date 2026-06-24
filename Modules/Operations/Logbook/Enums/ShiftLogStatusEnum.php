<?php

namespace Modules\Operations\Logbook\Enums;

enum ShiftLogStatusEnum: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted for Handover',
            self::Acknowledged => 'Acknowledged',
        };
    }
}
