<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryMovementSourceLegEnum: string
{
    case Primary = 'PRIMARY';
    case Outbound = 'OUTBOUND';
    case Inbound = 'INBOUND';
}
