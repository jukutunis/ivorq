<?php

namespace Modules\Operations\Housekeeping\Enums;

enum InspectionStatusEnum: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Passed     = 'passed';
    case Failed     = 'failed';
    case Deferred   = 'deferred';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Passed     => 'Passed',
            self::Failed     => 'Failed',
            self::Deferred   => 'Deferred',
        };
    }
}
