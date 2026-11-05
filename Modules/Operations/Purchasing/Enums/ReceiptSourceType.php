<?php

namespace Modules\Operations\Purchasing\Enums;

enum ReceiptSourceType: string
{
    case InventoryGoodsReceipt = 'inventory_goods_receipt';
    case LegacyReceivingDocument = 'legacy_receiving_document';

    public function label(): string
    {
        return match ($this) {
            self::InventoryGoodsReceipt => 'Controlled Goods Receipt',
            self::LegacyReceivingDocument => 'Legacy Receiving Evidence',
        };
    }
}
