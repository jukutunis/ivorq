<?php

namespace Modules\Operations\Logbook\Enums;

enum LogbookEntryStatusEnum: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }
}
