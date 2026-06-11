<?php

namespace Modules\Operations\ContractorPTW\Enums;

enum PermitStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case CLOSED = 'closed';
    case REJECTED = 'rejected';
}
