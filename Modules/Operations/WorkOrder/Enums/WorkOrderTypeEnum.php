<?php

namespace Modules\Operations\WorkOrder\Enums;

enum WorkOrderTypeEnum: string
{
    case Corrective = 'corrective';
    case Reactive = 'reactive';
    case Emergency = 'emergency';
    case Breakdown = 'breakdown';
    case Inspection = 'inspection';
    case GuestRequest = 'guest_request';
    case Project = 'project';
    case CAPA = 'capa';

    public function label(): string
    {
        return match($this) {
            self::Corrective => 'Corrective',
            self::Reactive => 'Reactive',
            self::Emergency => 'Emergency',
            self::Breakdown => 'Breakdown',
            self::Inspection => 'Inspection',
            self::GuestRequest => 'Guest Request',
            self::Project => 'Project',
            self::CAPA => 'CAPA',
        };
    }
}
