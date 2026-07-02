<?php

namespace Modules\Finance\PaymentAdjustmentReference\Enums;

enum PaymentAdjustmentConfigurationStatusEnum: string
{
    case RECORDED = 'RECORDED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
