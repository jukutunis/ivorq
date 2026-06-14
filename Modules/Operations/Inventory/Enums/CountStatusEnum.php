<?php

namespace Modules\Operations\Inventory\Enums;

enum CountStatusEnum: string
{
    case DRAFT = 'draft';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';
    case STALE = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::IN_PROGRESS => 'In Progress',
            self::SUBMITTED => 'Submitted',
            self::APPROVED => 'Approved',
            self::POSTED => 'Posted',
            self::CANCELLED => 'Cancelled',
            self::STALE => 'Stale',
        };
    }
}
