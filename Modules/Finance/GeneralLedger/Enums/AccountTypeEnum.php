<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum AccountTypeEnum: string
{
    case Asset = 'Asset';
    case Liability = 'Liability';
    case Equity = 'Equity';
    case Revenue = 'Revenue';
    case CostOfSales = 'CostOfSales';
    case Expense = 'Expense';
    case Statistical = 'Statistical';
}
