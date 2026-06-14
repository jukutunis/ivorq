<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Operations\Receiving\Models\ReceivingDocument;

class InventoryReceiptIntegrationService
{
    public function syncToInventory(ReceivingDocument $document): void
    {
        // Map receiving lines to InventoryTransaction DTOs
        // Emit InventoryReceiptPosted event
        // The Inventory module will listen to this event and increment stock ledgers
    }
}
