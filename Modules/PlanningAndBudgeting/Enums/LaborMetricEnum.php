<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum LaborMetricEnum: string
{
    case PayrollPercent = 'PAYROLL_PERCENT';
    case HeadcountTarget = 'HEADCOUNT_TARGET';
    case LaborHours = 'LABOR_HOURS';
    case OvertimePercent = 'OVERTIME_PERCENT';
}
