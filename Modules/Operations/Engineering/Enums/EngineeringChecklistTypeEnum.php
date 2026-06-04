<?php

namespace Modules\Operations\Engineering\Enums;

enum EngineeringChecklistTypeEnum: string
{
    case WorkOrder              = 'work_order';
    case PreventiveMaintenance  = 'preventive_maintenance';
    case Inspection             = 'inspection';

    public function label(): string
    {
        return match($this) {
            self::WorkOrder             => 'Work Order',
            self::PreventiveMaintenance => 'Preventive Maintenance',
            self::Inspection            => 'Inspection',
        };
    }
}
