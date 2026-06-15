<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum BudgetCycleStatusEnum: string
{
    case Draft = 'DRAFT';
    case Open = 'OPEN';
    case InReview = 'IN_REVIEW';
    case Approved = 'APPROVED';
    case Locked = 'LOCKED';
}
