<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderSourceTypeEnum: string
{
    case PreventiveMaintenance = 'pm_exception';
    case Incident = 'incident_capa';
    case GuestRequest = 'guest_request';
    case Manual = 'manual';
    case Integration = 'integration';

    public function label(): string
    {
        return match($this) {
            self::PreventiveMaintenance => 'PM Exception',
            self::Incident => 'Incident CAPA',
            self::GuestRequest => 'Guest Request',
            self::Manual => 'Manual Entry',
            self::Integration => 'System Integration',
        };
    }
}
