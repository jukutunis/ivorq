<?php

namespace Modules\Operations\Purchasing\Enums;

enum VendorQuotationStatusEnum: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Withdrawn = 'WITHDRAWN';
    case Selected = 'SELECTED';
    case Rejected = 'REJECTED';
}
