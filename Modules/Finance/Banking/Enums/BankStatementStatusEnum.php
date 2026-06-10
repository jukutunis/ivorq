<?php

namespace Modules\Finance\Banking\Enums;

enum BankStatementStatusEnum: string
{
    case Draft = 'Draft';
    case Imported = 'Imported';
    case Reconciled = 'Reconciled';
}
