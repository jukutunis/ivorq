<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;

class StockMovementService
{
    public function __construct(
        private InventoryStockRepository $stockRepository,
        private InventoryTransactionRepository $transactionRepository,
        private InventoryItemRepository $itemRepository
    ) {}

    public function receive(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $unitCost,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryTransaction {
        return $this->executeMovement(
            $propertyId,
            $itemId,
            $locationId,
            $quantity,
            TransactionTypeEnum::PurchaseReceipt,
            $unitCost,
            'Receipt',
            $referenceId,
            $remarks,
            $userId
        );
    }

    public function issue(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantity,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryTransaction {
        // Issue is a negative movement
        $negativeQuantity = (string) (-1 * abs((float) $quantity));
        return $this->executeMovement(
            $propertyId,
            $itemId,
            $locationId,
            $negativeQuantity,
            TransactionTypeEnum::Issue,
            null,
            'Issue',
            $referenceId,
            $remarks,
            $userId
        );
    }

    public function transfer(
        string $propertyId,
        string $itemId,
        string $fromLocationId,
        string $toLocationId,
        string $quantity,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): array {
        $negativeQuantity = (string) (-1 * abs((float) $quantity));
        $positiveQuantity = (string) abs((float) $quantity);

        $outTransaction = $this->executeMovement(
            $propertyId,
            $itemId,
            $fromLocationId,
            $negativeQuantity,
            TransactionTypeEnum::TransferOut,
            null,
            'Transfer',
            $referenceId,
            $remarks,
            $userId
        );

        $inTransaction = $this->executeMovement(
            $propertyId,
            $itemId,
            $toLocationId,
            $positiveQuantity,
            TransactionTypeEnum::TransferIn,
            null,
            'Transfer',
            $referenceId,
            $remarks,
            $userId
        );

        return [$outTransaction, $inTransaction];
    }

    public function adjust(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantityChange,
        ?string $unitCost = null,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryTransaction {
        $change = (float) $quantityChange;
        $type = $change >= 0 ? TransactionTypeEnum::AdjustmentIn : TransactionTypeEnum::AdjustmentOut;

        return $this->executeMovement(
            $propertyId,
            $itemId,
            $locationId,
            $quantityChange,
            $type,
            $unitCost,
            'Adjustment',
            $referenceId,
            $remarks,
            $userId
        );
    }

    private function executeMovement(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantityChange,
        TransactionTypeEnum $movementType,
        ?string $unitCost = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryTransaction {
        $item = $this->itemRepository->find($itemId);
        
        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'item' => ["Item {$itemId} is inactive and cannot receive stock movements."],
            ]);
        }

        // Lock balance row (Materialized Read Model Update)
        $stock = $this->stockRepository->findOrCreateLocked($itemId, $locationId, $propertyId);

        $quantityBefore = (float) $stock->physical_quantity;
        $change         = (float) $quantityChange;
        $quantityAfter  = $quantityBefore + $change;

        // Negative stock protection
        if ($quantityAfter < 0) {
            throw ValidationException::withMessages([
                'stock' => ["Negative stock is not allowed for item {$itemId} at location {$locationId}"],
            ]);
        }

        $reorderPoint = (float) $item->reorder_point;
        $newStatus = match (true) {
            $quantityAfter <= 0                                  => ItemStatusEnum::OutOfStock,
            $reorderPoint > 0 && $quantityAfter < $reorderPoint  => ItemStatusEnum::LowStock,
            default                                              => ItemStatusEnum::InStock,
        };

        $this->stockRepository->updateBalance(
            $stock->id,
            (string) $quantityAfter,
            $newStatus,
            now()
        );

        // Fallback unit cost to item's average cost if not explicitly provided
        $costToUse = $unitCost ?? $item->weighted_average_cost;
        $totalCost = ($costToUse !== null) ? $change * (float) $costToUse : null;

        // Immutable Ledger Append
        return $this->transactionRepository->create([
            'property_id'      => $propertyId,
            'item_id'          => $itemId,
            'location_id'      => $locationId,
            'transaction_type' => $movementType->value,
            'quantity_before'  => $quantityBefore,
            'quantity_change'  => $change,
            'quantity_after'   => $quantityAfter,
            'unit_cost'        => $costToUse,
            'total_cost'       => $totalCost,
            'reference_type'   => $referenceType,
            'reference_id'     => $referenceId,
            'notes'            => $remarks,
            'posted_at'        => now(),
            'posted_by'        => $userId ?? auth()->id(),
        ]);
    }
}
