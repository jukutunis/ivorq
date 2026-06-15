<?php

namespace Modules\Finance\Treasury\Enums;

enum VendorPaymentStatusEnum: string
{
    case Draft = 'DRAFT';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Executed = 'EXECUTED';
    case Reconciled = 'RECONCILED';
    case Voided = 'VOIDED';
    case Cancelled = 'CANCELLED';
}
