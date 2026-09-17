<?php

namespace Modules\Foundation\Property\Enums;

enum PropertyBootstrapProvisioningStatusEnum: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
}
