<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum ForecastSourceTypeEnum: string
{
    case Manual = 'MANUAL';
    case BudgetSeed = 'BUDGET_SEED';
    case ActualSeed = 'ACTUAL_SEED';
    case PmsPace = 'PMS_PACE';
    case Rms = 'RMS';
    case SalesEvent = 'SALES_EVENT';
}
