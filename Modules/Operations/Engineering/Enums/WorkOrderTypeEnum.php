<?php

namespace Modules\Operations\Engineering\Enums;

enum WorkOrderTypeEnum: string
{
    case Corrective   = 'corrective';
    case Preventive   = 'preventive';
    case Emergency    = 'emergency';
    case Installation = 'installation';
    case Inspection   = 'inspection';
    case Renovation   = 'renovation';
    case GuestRequest = 'guest_request';

    public function label(): string
    {
        return match($this) {
            self::Corrective   => 'Corrective',
            self::Preventive   => 'Preventive',
            self::Emergency    => 'Emergency',
            self::Installation => 'Installation',
            self::Inspection   => 'Inspection',
            self::Renovation   => 'Renovation',
            self::GuestRequest => 'Guest Request',
        };
    }
}
