<?php

namespace Modules\Operations\Inventory\Enums;

enum ToolStatusEnum: string
{
    case AVAILABLE = 'available';
    case CHECKED_OUT = 'checked_out';
    case IN_CALIBRATION = 'in_calibration';
    case IN_INSPECTION = 'in_inspection';
    case DAMAGED = 'damaged';
    case LOST = 'lost';
}
