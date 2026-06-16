<?php

namespace Modules\FunctionSpace\Enums;

enum VenueStatusEnum: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case OutOfOrder = 'OUT_OF_ORDER';
    case UnderRenovation = 'UNDER_RENOVATION';
    case Seasonal = 'SEASONAL';
}
