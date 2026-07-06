<?php

namespace Modules\Finance\Banking\Enums;

enum BankingMigrationExceptionSeverityEnum: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case BLOCKER = 'BLOCKER';
}
