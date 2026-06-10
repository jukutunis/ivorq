<?php

namespace Modules\Operations\Purchasing\Enums;

enum PurchaseOrderStatusEnum: string
{
    case Draft = 'Draft';
    case Issued = 'Issued';
    case PartiallyReceived = 'PartiallyReceived';
    case FullyReceived = 'FullyReceived';
    case Cancelled = 'Cancelled';
    case Closed = 'Closed';
}
