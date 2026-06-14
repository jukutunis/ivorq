<?php

namespace Modules\Operations\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Shared\Exceptions\BusinessLogicException;

class InventoryReceiptIntegrationService
{
    public function __construct(
        protected ReceiptService $receiptService
    ) {}

    public function syncToInventory(ReceivingDocument $document): void
    {
        DB::transaction(function () use ($document) {
            $document->loadMissing(['lines.inventoryItem', 'vendor']);
            
            // Map receiving document to Inventory Receipt DTO data
            $receiptData = [
                'property_id' => $document->property_id,
                'receipt_number' => $document->grn_number,
                'supplier_name' => $document->vendor ? $document->vendor->name : 'Unknown Vendor',
                'external_reference' => $document->vendor_delivery_no,
                'received_at' => $document->received_at ?? now(),
                'remarks' => $document->remarks,
            ];
            
            // Create InventoryReceipt via ReceiptService
            $inventoryReceipt = $this->receiptService->create($receiptData);
            
            // Create InventoryReceiptLines
            foreach ($document->lines as $line) {
                if ($line->inventory_item_id && $line->destination_location_id) {
                    InventoryReceiptLine::create([
                        'receipt_id' => $inventoryReceipt->id,
                        'item_id' => $line->inventory_item_id,
                        'location_id' => $line->destination_location_id,
                        'unit_id' => $line->inventory_unit_id,
                        'quantity' => $line->received_quantity,
                        'unit_cost' => $line->unit_cost,
                        'total_cost' => $line->line_total,
                        'expiry_date' => $line->expiry_date,
                    ]);
                }
            }
            
            // Post the InventoryReceipt to generate stock movements and update WAC
            try {
                // Check if there are lines to post, otherwise it will fail ValidationException
                if ($inventoryReceipt->lines()->count() > 0) {
                    $this->receiptService->post($inventoryReceipt->id, $document->created_by);
                }
            } catch (\Exception $e) {
                throw new BusinessLogicException("Failed to post inventory receipt: " . $e->getMessage());
            }
        });
    }
}
