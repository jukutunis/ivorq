<?php

namespace Modules\Finance\Payables\Enums;

enum VendorInvoiceStatusEnum: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Matched = 'Matched';
    case Cancelled = 'Cancelled';
}
