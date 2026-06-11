<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum AccountCategoryEnum: string
{
    case CurrentAsset = 'CurrentAsset';
    case FixedAsset = 'FixedAsset';
    case OtherAsset = 'OtherAsset';
    case CurrentLiability = 'CurrentLiability';
    case LongTermLiability = 'LongTermLiability';
    case Equity = 'Equity';
    case Revenue = 'Revenue';
    case CostOfSales = 'CostOfSales';
    case Expense = 'Expense';
    case Statistical = 'Statistical';
}
