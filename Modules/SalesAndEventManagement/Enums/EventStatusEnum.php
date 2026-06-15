<?php

namespace Modules\SalesAndEventManagement\Enums;

enum EventStatusEnum: string
{
    case Tentative = 'TENTATIVE';
    case Definite = 'DEFINITE';
    case InHouse = 'IN_HOUSE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Archived = 'ARCHIVED';
}
