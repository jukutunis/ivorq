<?php

namespace Modules\Operations\Engineering\Enums;

enum WorkOrderPriorityEnum: int
{
    case Critical = 1;
    case High     = 2;
    case Normal   = 3;
    case Low      = 4;

    public function label(): string
    {
        return match($this) {
            self::Critical => 'Critical',
            self::High     => 'High',
            self::Normal   => 'Normal',
            self::Low      => 'Low',
        };
    }
}
