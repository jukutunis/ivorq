<?php

namespace Modules\Operations\Inventory\Enums;

enum GoodsReceiptExceptionResolutionTypeEnum: string
{
    case AcceptAsIs = 'ACCEPT_AS_IS';
    case AdjustAcceptedQuantity = 'ADJUST_ACCEPTED_QUANTITY';
    case RejectLine = 'REJECT_LINE';
    case VoidReceipt = 'VOID_RECEIPT';
}
