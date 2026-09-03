<?php

namespace Modules\Operations\Receiving\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Services\AvcoValuationCalculator;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use RuntimeException;
use Shared\Exceptions\BusinessLogicException;

class InventoryReceiptIntegrationService
{
    public function __construct(
        protected InventoryPostingControlCoordinator $coordinator,
        protected InventoryStockRepository $stockRepository,
        protected InventoryItemRepository $itemRepository,
        protected AvcoValuationCalculator $avcoCalculator,
        protected CostDeliveryModePort $costDeliveryMode,
        protected SynchronousCostValuationPort $synchronousValuation,
    ) {}

    public function syncToInventory(ReceivingDocument $document, ?string $approverId = null): void
    {
        DB::transaction(function () use ($document, $approverId) {
            $document->loadMissing(['lines.inventoryItem']);

            if ($document->lines->isEmpty()) {
                return;
            }

            // Pass 1: validate all line fields before any authority check or mutation.
            foreach ($document->lines as $line) {
                if (! $line->inventory_item_id || ! $line->destination_location_id) {
                    throw new BusinessLogicException('Receiving line is missing item or destination location.');
                }
            }

            // Pass 2: enrollment classification
            $propertyId = $document->property_id;
            $lines = $document->lines;
            $totalLines = $lines->count();
            $enrolledCount = 0;
            foreach ($lines as $line) {
                if ($this->costDeliveryMode->isEnrolled($propertyId, (string) $line->inventory_item_id)) {
                    $enrolledCount++;
                }
            }

            // Mixed enrollment fail closed
            if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
                throw new RuntimeException('Mixed enrollment status detected across receiving lines. Fail closed.');
            }

            $allEnrolled = ($enrolledCount === $totalLines);

            if (! $allEnrolled) {
                // Legacy path remains unchanged
                $businessDate = PropertyBusinessDate::where('property_id', $document->property_id)
                    ->where('status', PropertyBusinessDateStatusEnum::Open)
                    ->where('is_open', true)
                    ->first();

                if (! $businessDate) {
                    throw new BusinessLogicException('No open business date found for property.');
                }

                if (! $approverId) {
                    throw new BusinessLogicException('Actual approver identity is required for controlled posting.');
                }

                $actorId = $approverId;

                $occurredAt = Carbon::parse($document->received_at ?? $document->created_at);
                $this->coordinator->lockContext($document->property_id, $businessDate->business_date, $occurredAt);

                $linesByItem = $document->lines->groupBy('inventory_item_id');

                $itemSnapshots = [];
                foreach ($linesByItem as $itemId => $lines) {
                    if (! $itemId) {
                        continue;
                    }
                    $item = $this->itemRepository->find($itemId);
                    $oldWac = (float) $item->weighted_average_cost;
                    $oldQty = (float) $this->stockRepository->totalQuantityForPropertyItemLocked($document->property_id, $itemId);

                    $itemSnapshots[$itemId] = [
                        'item' => $item,
                        'oldWac' => $oldWac,
                        'oldQty' => $oldQty,
                    ];
                }

                $sortedLines = $document->lines->sortBy([
                    ['inventory_item_id', 'asc'],
                    ['destination_location_id', 'asc'],
                ]);

                foreach ($sortedLines as $line) {
                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $document->property_id,
                        itemId: $line->inventory_item_id,
                        locationId: $line->destination_location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'receiving_document',
                        sourceDocumentId: $document->id,
                        sourceLineType: 'receiving_line',
                        sourceLineId: $line->id,
                        movementRole: TransactionTypeEnum::PurchaseReceipt->value,
                        idempotencyKey: $line->id,
                        transactionType: TransactionTypeEnum::PurchaseReceipt,
                        quantityChange: (string) $line->received_quantity,
                        unitCost: (string) $line->unit_cost,
                        totalCost: (string) $line->line_total,
                        reference: $document->id,
                        notes: $document->remarks ?? 'Receiving Approval'
                    );

                    $this->coordinator->post($intent, $actorId);
                }

                foreach ($linesByItem as $itemId => $lines) {
                    if (! isset($itemSnapshots[$itemId])) {
                        continue;
                    }

                    ['item' => $item, 'oldWac' => $oldWac, 'oldQty' => $oldQty] = $itemSnapshots[$itemId];

                    $receiptQty = $lines->sum(fn ($l) => (float) $l->received_quantity);
                    $receiptValue = $lines->sum(fn ($l) => (float) $l->received_quantity * (float) $l->unit_cost);

                    $newWac = $this->avcoCalculator->calculate($oldQty, $oldWac, $receiptQty, $receiptValue);

                    $this->itemRepository->update($item->id, ['weighted_average_cost' => $newWac]);
                }
            } else {
                // All ENROLLED path
                $businessDate = PropertyBusinessDate::where('property_id', $document->property_id)
                    ->where('status', PropertyBusinessDateStatusEnum::Open)
                    ->where('is_open', true)
                    ->first();

                if (! $businessDate) {
                    throw new BusinessLogicException('No open business date found for property.');
                }

                if (! $approverId) {
                    throw new BusinessLogicException('Actual approver identity is required for controlled posting.');
                }

                $actorId = $approverId;
                $occurredAt = Carbon::parse($document->received_at ?? $document->created_at);

                $sortedLines = $document->lines->sortBy([
                    ['inventory_item_id', 'asc'],
                    ['destination_location_id', 'asc'],
                ]);

                $intents = [];
                $sources = [];
                foreach ($sortedLines as $line) {
                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $document->property_id,
                        itemId: $line->inventory_item_id,
                        locationId: $line->destination_location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'receiving_document',
                        sourceDocumentId: $document->id,
                        sourceLineType: 'receiving_line',
                        sourceLineId: $line->id,
                        movementRole: TransactionTypeEnum::PurchaseReceipt->value,
                        idempotencyKey: $line->id,
                        transactionType: TransactionTypeEnum::PurchaseReceipt,
                        quantityChange: (string) $line->received_quantity,
                        unitCost: (string) $line->unit_cost,
                        totalCost: (string) $line->line_total,
                        reference: $document->id,
                        notes: $document->remarks ?? 'Receiving Approval'
                    );
                    $intents[] = $intent;
                    $sources[] = [
                        'propertyId' => (string) $propertyId,
                        'itemId' => (string) $line->inventory_item_id,
                        'locationId' => (string) $line->destination_location_id,
                        'idempotencyKey' => (string) $line->id,
                        'sourceDocumentType' => $intent->sourceDocumentType,
                        'sourceDocumentId' => $intent->sourceDocumentId,
                        'sourceLineType' => $intent->sourceLineType,
                        'sourceLineId' => $intent->sourceLineId,
                        'movementRole' => $intent->movementRole,
                        'quantityChange' => $intent->quantityChange,
                        'unitCost' => $intent->unitCost,
                        'totalCost' => $intent->totalCost,
                    ];
                }
                $resolved = $this->coordinator->resolveDocumentDeliveryModes($sources);
                foreach ($intents as $intent) {
                    $resolution = $resolved[$intent->idempotencyKey];
                    $transaction = $this->coordinator->post($intent, $actorId, $resolution['decision']);
                    if ($resolution['existing'] === null
                        && $resolution['decision']->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
                        $this->synchronousValuation->applyReceipt($transaction->id);
                    }
                }
            }
        });
    }
}
