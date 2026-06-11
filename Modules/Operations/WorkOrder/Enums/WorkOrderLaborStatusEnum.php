<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderLaborStatusEnum: string
{
    case Started = 'started';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match($this) {
            self::Started => 'Started',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
        };
    }
}
