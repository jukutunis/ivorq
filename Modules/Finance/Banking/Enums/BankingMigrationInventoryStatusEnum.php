<?php

namespace Modules\Finance\Banking\Enums;

enum BankingMigrationInventoryStatusEnum: string
{
    case INVENTORIED = 'INVENTORIED';
    case EXCLUDED = 'EXCLUDED';
    case BLOCKED = 'BLOCKED';
    case QUARANTINED = 'QUARANTINED';
}
