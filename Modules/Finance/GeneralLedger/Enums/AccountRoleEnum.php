<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum AccountRoleEnum: string
{
    case Expense_Account = 'Expense_Account';
    case AP_Liability = 'AP_Liability';
    case Cash_Account = 'Cash_Account';
    case Bank_Account = 'Bank_Account';
}
