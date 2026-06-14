<?php

namespace Modules\Operations\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Inventory\Models\InventoryTransaction;

class InventoryAdjustmentPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public InventoryTransaction $transaction)
    {
    }
}
