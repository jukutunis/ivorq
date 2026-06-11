<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderApprovalModeEnum: string
{
    case Linear = 'linear';
    case Parallel = 'parallel';

    public function label(): string
    {
        return match($this) {
            self::Linear => 'Linear',
            self::Parallel => 'Parallel',
        };
    }
}
