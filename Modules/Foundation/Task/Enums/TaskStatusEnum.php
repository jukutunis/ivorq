<?php

namespace Modules\Foundation\Task\Enums;

enum TaskStatusEnum: string
{
    case Draft      = 'draft';
    case Open       = 'open';
    case Assigned   = 'assigned';
    case InProgress = 'in_progress';
    case OnHold     = 'on_hold';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
    case Closed     = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::Open       => 'Open',
            self::Assigned   => 'Assigned',
            self::InProgress => 'In Progress',
            self::OnHold     => 'On Hold',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
            self::Closed     => 'Closed',
        };
    }
}
