<?php

namespace Modules\SalesAndEventManagement\Enums;

enum TemplateStatusEnum: string
{
    case Draft = 'DRAFT';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Published = 'PUBLISHED';
    case Inactive = 'INACTIVE';
    case Superseded = 'SUPERSEDED';
    case Archived = 'ARCHIVED';
}
