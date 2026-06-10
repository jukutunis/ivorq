<?php

namespace Modules\Operations\Purchasing\Enums;

enum PurchaseRequestStatusEnum: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Cancelled = 'Cancelled';
    case ConvertedToPO = 'ConvertedToPO';
}
