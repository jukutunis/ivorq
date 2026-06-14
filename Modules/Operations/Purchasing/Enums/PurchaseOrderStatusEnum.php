<?php

namespace Modules\Operations\Purchasing\Enums;

enum PurchaseOrderStatusEnum: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Issued = 'ISSUED';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case FullyReceived = 'FULLY_RECEIVED';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
