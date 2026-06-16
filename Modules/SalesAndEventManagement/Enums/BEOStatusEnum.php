<?php

namespace Modules\SalesAndEventManagement\Enums;

enum BEOStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case PUBLISHED = 'PUBLISHED';
    case SUPERSEDED = 'SUPERSEDED';
    case CANCELLED = 'CANCELLED';
}
