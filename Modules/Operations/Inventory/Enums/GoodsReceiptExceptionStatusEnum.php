<?php

namespace Modules\Operations\Inventory\Enums;

enum GoodsReceiptExceptionStatusEnum: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';
}
