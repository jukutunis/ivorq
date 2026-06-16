<?php

namespace Modules\FunctionSpace\Enums;

enum MaintenanceBlockTypeEnum: string
{
    case Preventive = 'PREVENTIVE';
    case OutOfOrder = 'OUT_OF_ORDER';
    case Renovation = 'RENOVATION';
}
