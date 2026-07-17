<?php

namespace Modules\Operations\NightAudit\Enums;

enum NightAuditRunStatusEnum: string
{
    case InProgress = 'IN_PROGRESS';
    case Aborted = 'ABORTED';
}
