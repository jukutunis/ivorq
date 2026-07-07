<?php

namespace Modules\Operations\Inventory\Enums;

enum GoodsReceiptStatusEnum: string
{
    case Draft = 'DRAFT';
    case ConfirmationPending = 'CONFIRMATION_PENDING';
    case Posted = 'POSTED';
}
