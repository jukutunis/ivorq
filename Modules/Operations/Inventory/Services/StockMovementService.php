<?php

namespace Modules\Operations\Inventory\Services;

use Exception;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockCardRepository;

class StockMovementService
{
    public function __construct(
        private InventoryStockBalanceRepository $balanceRepository,
        private InventoryStockCardRepository $cardRepository,
        private InventoryItemRepository $itemRepository
    ) {}

    /**
     * @throws ValidationException
     */
    public function move(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantityChange,
        TransactionTypeEnum $movementType,
        string $unitCost,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryStockCard {
        $balance = $this->balanceRepository->lockForUpdate($itemId, $locationId);

        if (! $balance) {
            $balance = $this->balanceRepository->findOrCreate($itemId, $locationId, $propertyId);
            // Need to lock again since we just created it
            $balance = $this->balanceRepository->lockForUpdate($itemId, $locationId);
        }

        $quantityBefore = (float) $balance->quantity;
        $change = (float) $quantityChange;
        $quantityAfter = $quantityBefore + $change;

        if ($quantityAfter < 0) {
            throw ValidationException::withMessages([
                'stock' => ['Negative stock is not allowed for item ' . $itemId . ' at location ' . $locationId],
            ]);
        }

        $newStatus = $quantityAfter > 0 ? ItemStatusEnum::InStock : ItemStatusEnum::OutOfStock;

        // In IVORQ, we update balance via repository
        $this->balanceRepository->updateBalance(
            $balance->id,
            (string) $quantityAfter,
            $newStatus,
            now()
        );

        $totalValue = $change * (float) $unitCost;

        return $this->cardRepository->create([
            'property_id'     => $propertyId,
            'item_id'         => $itemId,
            'location_id'     => $locationId,
            'movement_type'   => $movementType->value,
            'quantity_before' => $quantityBefore,
            'quantity_change' => $change,
            'quantity_after'  => $quantityAfter,
            'unit_cost'       => $unitCost,
            'total_value'     => $totalValue,
            'reference_id'    => $referenceId,
            'remarks'         => $remarks,
            'posted_at'       => now(),
            'posted_by'       => $userId ?? auth()->id(),
        ]);
    }
}
