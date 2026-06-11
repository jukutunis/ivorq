<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryTransactionTypeEnum: string
{
    case RECEIVE = 'receive';
    case ISSUE = 'issue';
    case TRANSFER = 'transfer';
    case ADJUSTMENT = 'adjustment';
    case CONSUMPTION = 'consumption';
    case RETURN = 'return';
    case WRITE_OFF = 'write_off';
    case CYCLE_COUNT = 'cycle_count';
    case TOOL_CHECKOUT = 'tool_checkout';
    case TOOL_RETURN = 'tool_return';
}
