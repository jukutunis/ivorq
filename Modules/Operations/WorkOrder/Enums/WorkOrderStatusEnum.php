<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderStatusEnum: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Open => 'Open',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::Paused => 'Paused',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
