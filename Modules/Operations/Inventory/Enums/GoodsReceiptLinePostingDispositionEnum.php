<?php

namespace Modules\Operations\Inventory\Enums;

enum GoodsReceiptLinePostingDispositionEnum: string
{
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
}
