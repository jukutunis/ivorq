<?php

namespace Modules\Operations\ContractorPTW\Enums;

enum ContractorStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case SUSPENDED = 'suspended';
    case BLACKLISTED = 'blacklisted';
}
