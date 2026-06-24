<?php

namespace Modules\Operations\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\Services\AvcoValuationCalculator;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Shared\Exceptions\BusinessLogicException;

class InventoryReceiptIntegrationService
{
    public function __construct(
        protected InventoryPostingControlCoordinator $coordinator,
        protected InventoryStockRepository $stockRepository,
        protected InventoryItemRepository $itemRepository,
        protected AvcoValuationCalculator $avcoCalculator
    ) {}

    public function syncToInventory(ReceivingDocument $document, ?string $approverId = null): void
    {
        DB::transaction(function () use ($document, $approverId) {
            $document->loadMissing(['lines.inventoryItem']);

            if ($document->lines->isEmpty()) {
                return;
            }

            $businessDate = PropertyBusinessDate::where('property_id', $document->property_id)
                ->where('status', \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open)
                ->where('is_open', true)
                ->first();

            if (!$businessDate) {
                throw new BusinessLogicException("No open business date found for property.");
            }

            if (!$approverId) {
                throw new BusinessLogicException("Actual approver identity is required for controlled posting.");
            }

            $actorId = $approverId;

            $occurredAt = \Illuminate\Support\Carbon::parse($document->received_at ?? $document->created_at);
            $this->coordinator->lockContext($document->property_id, $businessDate->business_date, $occurredAt);

            $linesByItem = $document->lines->groupBy('inventory_item_id');

            $itemSnapshots = [];
            foreach ($linesByItem as $itemId => $lines) {
                if (!$itemId) continue;
                $item = $this->itemRepository->find($itemId);
                $oldWac = (float) $item->weighted_average_cost;
                $oldQty = (float) $this->stockRepository->totalQuantityForPropertyItemLocked($document->property_id, $itemId);

                $itemSnapshots[$itemId] = [
                    'item'   => $item,
                    'oldWac' => $oldWac,
                    'oldQty' => $oldQty,
                ];
            }

            $sortedLines = $document->lines->map(function($line) {
                if (!$line->inventory_item_id || !$line->destination_location_id) {
                    throw new BusinessLogicException("Receiving line is missing item or destination location.");
                }
                return $line;
            })->sortBy([
                ['inventory_item_id', 'asc'],
                ['destination_location_id', 'asc'],
            ]);

            foreach ($sortedLines as $line) {
                $intent = new InventoryLedgerPostingIntent(
                    propertyId: $document->property_id,
                    sourceDocumentType: 'receiving_document',
                    sourceDocumentId: $document->id,
                    sourceLineType: 'receiving_line',
                    sourceLineId: $line->id,
                    movementRole: TransactionTypeEnum::PurchaseReceipt->value,
                    itemId: $line->inventory_item_id,
                    locationId: $line->destination_location_id,
                    quantityChange: $line->received_quantity,
                    unitCost: $line->unit_cost,
                    totalCost: $line->line_total,
                    occurredAt: $occurredAt,
                    businessDate: $businessDate->business_date,
                    idempotencyKey: "rcv_{$document->id}_{$line->id}_receipt",
                    transactionType: TransactionTypeEnum::PurchaseReceipt,
                    reference: $document->grn_number,
                    notes: $document->remarks ?? 'Receiving Approval'
                );

                $this->coordinator->post($intent, $actorId);
            }

            foreach ($linesByItem as $itemId => $lines) {
                if (!isset($itemSnapshots[$itemId])) continue;

                ['item' => $item, 'oldWac' => $oldWac, 'oldQty' => $oldQty] = $itemSnapshots[$itemId];

                $receiptQty   = $lines->sum(fn ($l) => (float) $l->received_quantity);
                $receiptValue = $lines->sum(fn ($l) => (float) $l->received_quantity * (float) $l->unit_cost);

                $newWac = $this->avcoCalculator->calculate($oldQty, $oldWac, $receiptQty, $receiptValue);

                $this->itemRepository->update($item->id, ['weighted_average_cost' => $newWac]);
            }
        });
    }
}
