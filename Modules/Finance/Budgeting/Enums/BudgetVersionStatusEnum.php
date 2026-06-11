<?php

namespace Modules\Finance\Budgeting\Enums;

enum BudgetVersionStatusEnum: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Locked = 'Locked';
}
