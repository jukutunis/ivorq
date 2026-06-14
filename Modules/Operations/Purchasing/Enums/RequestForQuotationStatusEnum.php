<?php

namespace Modules\Operations\Purchasing\Enums;

enum RequestForQuotationStatusEnum: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case BidsReceived = 'BIDS_RECEIVED';
    case Awarded = 'AWARDED';
    case Cancelled = 'CANCELLED';
}
