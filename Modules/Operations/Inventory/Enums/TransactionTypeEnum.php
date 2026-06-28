<?php

namespace Modules\Operations\Inventory\Enums;

enum TransactionTypeEnum: string
{
    case OpeningBalance  = 'opening_balance';
    case PurchaseReceipt = 'purchase_receipt';
    case Issue           = 'issue';
    case TransferOut     = 'transfer_out';
    case TransferIn      = 'transfer_in';
    case AdjustmentIn    = 'adjustment_in';
    case AdjustmentOut   = 'adjustment_out';
    case Return          = 'return';
    case Reversal        = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance  => 'Opening Balance',
            self::PurchaseReceipt => 'Purchase Receipt',
            self::Issue           => 'Issue',
            self::TransferOut     => 'Transfer Out',
            self::TransferIn      => 'Transfer In',
            self::AdjustmentIn    => 'Adjustment In',
            self::AdjustmentOut   => 'Adjustment Out',
            self::Return          => 'Return',
            self::Reversal        => 'Reversal',
        };
    }
}
