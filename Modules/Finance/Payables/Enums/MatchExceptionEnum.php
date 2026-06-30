<?php

namespace Modules\Finance\Payables\Enums;

enum MatchExceptionEnum: string
{
    case MissingPurchaseOrder = 'MissingPurchaseOrder';
    case MissingGoodsReceipt = 'MissingGoodsReceipt';
    case InvalidLineReference = 'InvalidLineReference';
    case QuantityVariance = 'QuantityVariance';
    case PriceVariance = 'PriceVariance';
    case LineAmountVariance = 'LineAmountVariance';
    case VendorMismatch = 'VendorMismatch';
    case ReceivingMismatch = 'ReceivingMismatch';
    case CurrencyMismatch = 'CurrencyMismatch';
    case DataIntegrityError = 'DataIntegrityError';
}
