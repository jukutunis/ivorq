<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Purchasing\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Repositories\GoodsReceiptRepository;
use Modules\Operations\Purchasing\Repositories\PurchaseOrderRepository;
use Modules\Operations\Inventory\Services\ReceiptService as InventoryReceiptService;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Shared\Exceptions\BusinessLogicException;

class ReceivingService
{
    public function __construct(
        private GoodsReceiptRepository $goodsReceiptRepository,
        private PurchaseOrderRepository $purchaseOrderRepository,
        private InventoryReceiptService $inventoryReceiptService
    ) {}

    public function receive(string $purchaseOrderId, array $data): GoodsReceipt
    {
        $po = $this->purchaseOrderRepository->find($purchaseOrderId);

        // BR-001: Only Issued or PartiallyReceived POs can be received
        if (! in_array($po->status, [PurchaseOrderStatusEnum::Issued, PurchaseOrderStatusEnum::PartiallyReceived])) {
            throw new BusinessLogicException('Only Issued or Partially Received Purchase Orders can be received.');
        }

        return DB::transaction(function () use ($po, $data) {
            // Create Goods Receipt
            $grnData = [
                'property_id' => $po->property_id,
                'grn_no' => 'GRN-' . now()->format('Y') . '-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT),
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'received_date' => $data['received_date'] ?? now()->format('Y-m-d'),
                'status' => GoodsReceiptStatusEnum::Posted->value,
                'remarks' => $data['remarks'] ?? null,
            ];
            
            $grn = $this->goodsReceiptRepository->create($grnData);

            $receivingDocument = ReceivingDocument::create([
                'property_id' => $po->property_id,
                'vendor_id' => $po->vendor_id,
                'purchase_order_id' => $po->id,
                'grn_number' => $grn->grn_no,
                'received_at' => $grn->received_date,
                'status' => 'submitted',
                'remarks' => $data['remarks'] ?? null,
            ]);

            $inventoryReceiptData = [
                'property_id' => $po->property_id,
                'receipt_number' => $grn->grn_no,
                'supplier_name' => $po->vendor->name,
                'external_reference' => $grn->grn_no,
                'receiving_document_id' => $receivingDocument->id,
                'received_at' => $grn->received_date,
                'remarks' => 'Auto-generated from ' . $grn->grn_no,
            ];

            // Create InventoryReceipt via InventoryReceiptService
            $inventoryReceipt = $this->inventoryReceiptService->create($inventoryReceiptData);

            $allLinesFullyReceived = true;
            $hasPartialReceiving = false;
            
            $totalReceivedValue = 0;

            foreach ($data['lines'] as $lineData) {
                $poLine = $po->lines()->where('id', $lineData['purchase_order_line_id'])->first();
                
                if (!$poLine) {
                    throw new BusinessLogicException('Invalid purchase order line.');
                }

                $quantityReceived = (float) $lineData['quantity_received'];

                // BR-002: Cannot receive more than ordered_quantity
                if ($quantityReceived <= 0 || ($poLine->quantity_received + $quantityReceived) > $poLine->ordered_quantity) {
                    throw new BusinessLogicException('Quantity received exceeds quantity ordered or is invalid.');
                }

                $lineTotal = $quantityReceived * $poLine->unit_cost;
                $totalReceivedValue += $lineTotal;

                // Create GRN Line
                $grn->lines()->create([
                    'property_id' => $po->property_id,
                    'purchase_order_line_id' => $poLine->id,
                    'inventory_item_id' => $poLine->inventory_item_id,
                    'location_id' => $lineData['location_id'],
                    'quantity_received' => $quantityReceived,
                    'unit_cost' => $poLine->unit_cost,
                    'line_total' => $lineTotal,
                ]);

                ReceivingLine::create([
                    'receiving_document_id' => $receivingDocument->id,
                    'purchase_order_line_id' => $poLine->id,
                    'inventory_item_id' => $poLine->inventory_item_id,
                    'destination_location_id' => $lineData['location_id'],
                    'description' => $poLine->description ?? 'Purchase order receipt',
                    'received_quantity' => $quantityReceived,
                    'unit_cost' => $poLine->unit_cost,
                    'line_total' => $lineTotal,
                ]);

                // Create InventoryReceiptLine directly since ReceiptService create doesn't handle lines
                InventoryReceiptLine::create([
                    'property_id' => $po->property_id,
                    'receipt_id' => $inventoryReceipt->id,
                    'item_id' => $poLine->inventory_item_id,
                    'location_id' => $lineData['location_id'],
                    'quantity' => $quantityReceived,
                    'unit_cost' => $poLine->unit_cost,
                    'total_value' => $lineTotal,
                ]);

                // Update PO Line quantity
                $poLine->quantity_received += $quantityReceived;
                $poLine->save();

                if ($poLine->quantity_received < $poLine->ordered_quantity) {
                    $allLinesFullyReceived = false;
                }
                
                $hasPartialReceiving = true;
            }

            // Update PO total received
            $po->received_total += $totalReceivedValue;

            // BR-004 & BR-003: Update PO status
            if ($allLinesFullyReceived && $hasPartialReceiving) {
                // Double check if ALL lines in the PO are fully received
                $unreceivedLines = $po->lines()->whereColumn('quantity_received', '<', 'ordered_quantity')->count();
                if ($unreceivedLines === 0) {
                    $po->status = PurchaseOrderStatusEnum::FullyReceived->value;
                } else {
                    $po->status = PurchaseOrderStatusEnum::PartiallyReceived->value;
                }
            } else {
                $po->status = PurchaseOrderStatusEnum::PartiallyReceived->value;
            }
            $po->save();

            // Post InventoryReceipt
            $this->inventoryReceiptService->post($inventoryReceipt->id);

            return $grn->fresh(['lines']);
        });
    }
}
