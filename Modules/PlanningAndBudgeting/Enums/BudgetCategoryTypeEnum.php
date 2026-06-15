<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum BudgetCategoryTypeEnum: string
{
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';
    case Payroll = 'PAYROLL';
    case Statistical = 'STATISTICAL';
}
