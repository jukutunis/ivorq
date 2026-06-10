<?php

namespace Modules\Foundation\Approval\Enums;

enum ApprovalActionEnum: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Skipped = 'Skipped';
    case Delegated = 'Delegated';
}
