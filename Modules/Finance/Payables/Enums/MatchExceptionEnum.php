<?php

namespace Modules\Finance\Payables\Enums;

enum MatchExceptionEnum: string
{
    case MissingPurchaseOrder = 'MissingPurchaseOrder';
    case MissingGoodsReceipt = 'MissingGoodsReceipt';
    case InvalidLineReference = 'InvalidLineReference';
    case DataIntegrityError = 'DataIntegrityError';
}
