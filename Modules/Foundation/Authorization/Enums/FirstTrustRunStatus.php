<?php

namespace Modules\Foundation\Authorization\Enums;

enum FirstTrustRunStatus: string
{
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
