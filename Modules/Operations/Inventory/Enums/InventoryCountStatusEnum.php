<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryCountStatusEnum: string
{
    case DRAFT = 'draft';
    case SYNCED = 'synced';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
