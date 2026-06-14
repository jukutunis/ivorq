<?php

namespace Modules\Operations\Inventory\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Inventory\Models\InventoryReceipt;

class InventoryReceiptPosted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public InventoryReceipt $receipt
    ) {}
}
