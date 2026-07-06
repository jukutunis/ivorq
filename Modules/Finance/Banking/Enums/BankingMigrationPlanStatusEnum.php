<?php

namespace Modules\Finance\Banking\Enums;

enum BankingMigrationPlanStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case DRY_RUN_REQUESTED = 'DRY_RUN_REQUESTED';
    case DRY_RUN_COMPLETED = 'DRY_RUN_COMPLETED';
    case BLOCKED = 'BLOCKED';
    case ARCHIVED = 'ARCHIVED';
}
