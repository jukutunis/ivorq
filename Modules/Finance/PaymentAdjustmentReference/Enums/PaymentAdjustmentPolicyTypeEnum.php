<?php

namespace Modules\Finance\PaymentAdjustmentReference\Enums;

enum PaymentAdjustmentPolicyTypeEnum: string
{
    case RATE = 'RATE';
    case FIXED = 'FIXED';
}
