<?php

namespace Modules\Finance\PaymentAdjustmentReference\Enums;

enum PaymentAdjustmentTypeEnum: string
{
    case TAX = 'TAX';
    case WITHHOLDING = 'WITHHOLDING';
    case DISCOUNT = 'DISCOUNT';
}
