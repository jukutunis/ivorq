<?php

namespace Modules\Operations\Purchasing\Enums;

enum PurchaseRequestStatusEnum: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Converted = 'CONVERTED';
}
