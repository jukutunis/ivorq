<?php

namespace Modules\FunctionSpace\Enums;

enum FunctionSpaceBookingStatusEnum: string
{
    case Tentative = 'TENTATIVE';
    case Definite = 'DEFINITE';
    case InHouse = 'IN_HOUSE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
