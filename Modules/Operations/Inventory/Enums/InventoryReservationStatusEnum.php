<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryReservationStatusEnum: string
{
    case PENDING = 'pending';
    case RESERVED = 'reserved';
    case CONSUMED = 'consumed';
    case CANCELLED = 'cancelled';
}
