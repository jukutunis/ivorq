<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskCheckoutHousekeepingHandoffStatusEnum: string
{
    case Pending = 'PENDING';
    case Claimed = 'CLAIMED';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
}
