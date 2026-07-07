<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryMovementTypeEnum: string
{
    case GoodsReceipt = 'GOODS_RECEIPT';
    case TransferOut = 'TRANSFER_OUT';
    case TransferIn = 'TRANSFER_IN';
    case IssueConsumption = 'ISSUE_CONSUMPTION';
    case CountVarianceIn = 'COUNT_VARIANCE_IN';
    case CountVarianceOut = 'COUNT_VARIANCE_OUT';
    case ManualAdjustmentIn = 'MANUAL_ADJUSTMENT_IN';
    case ManualAdjustmentOut = 'MANUAL_ADJUSTMENT_OUT';

    public function direction(): InventoryMovementDirectionEnum
    {
        return match ($this) {
            self::GoodsReceipt, self::TransferIn, self::CountVarianceIn,
            self::ManualAdjustmentIn => InventoryMovementDirectionEnum::In,
            self::TransferOut, self::IssueConsumption, self::CountVarianceOut,
            self::ManualAdjustmentOut => InventoryMovementDirectionEnum::Out,
        };
    }
}
