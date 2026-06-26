<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransferRepository;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Shared\Exceptions\BusinessLogicException;

class TransferService
{
    public function __construct(
        private InventoryTransferRepository $transferRepository,
        private InventoryPostingControlCoordinator $coordinator,
        private InventoryItemRepository $itemRepository
    ) {}

    public function create(array $data): InventoryTransfer
    {
        $data['status'] = TransferStatusEnum::Draft->value;
        return $this->transferRepository->create($data);
    }

    public function complete(string $id, ?string $userId = null): InventoryTransfer
    {
        $transfer = $this->transferRepository->find($id);

        if (! $transfer->status->canTransitionTo(TransferStatusEnum::Completed)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition transfer from {$transfer->status->label()} to Completed."],
            ]);
        }

        // BR-051: at least one line required before completing
        if ($transfer->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['A transfer must have at least one line before it can be completed.'],
            ]);
        }

        $businessDate = PropertyBusinessDate::where('property_id', $transfer->property_id)
            ->where('status', PropertyBusinessDateStatusEnum::Open)
            ->where('is_open', true)
            ->first();

        if (!$businessDate) {
            throw new BusinessLogicException("No open business date found for property.");
        }

        $authId = auth()->id();
        $actorId = $userId ?? $authId;

        if (!$actorId) {
            throw new BusinessLogicException("Authenticated posting operator is required.");
        }

        if ($authId !== null && $userId !== null && $userId !== $authId) {
            throw new BusinessLogicException("The supplied user ID does not match the authenticated posting operator.");
        }

        // Deterministic multi-line order: item_id ASC -> id ASC
        $sortedLines = $transfer->lines->map(function ($line) {
            if (!$line->item_id) {
                throw new BusinessLogicException("Transfer line is missing item.");
            }
            return $line;
        })->sortBy([
            ['item_id', 'asc'],
            ['id', 'asc'],
        ]);

        $intents = [];
        $occurredAt = \Illuminate\Support\Carbon::parse($transfer->created_at ?? now());

        foreach ($sortedLines as $line) {
            $item = $this->itemRepository->find($line->item_id);
            if (!$item) {
                throw new BusinessLogicException("Item not found: {$line->item_id}");
            }

            $wac = $item->weighted_average_cost;
            if ($wac === null) {
                throw ValidationException::withMessages([
                    'cost' => ["Item {$item->name} ({$item->sku}) does not have a valid weighted average cost."],
                ]);
            }

            $qty = (string) abs((float) $line->quantity_requested);
            $negQty = (string) (-1 * abs((float) $line->quantity_requested));
            $totalCostOut = bcmul($negQty, (string) $wac, 4);
            $totalCostIn = bcmul($qty, (string) $wac, 4);

            // Intent Out
            $intents[] = new InventoryLedgerPostingIntent(
                propertyId: $transfer->property_id,
                itemId: $line->item_id,
                locationId: $transfer->from_location_id,
                businessDate: $businessDate->business_date,
                occurredAt: $occurredAt,
                sourceDocumentType: 'inventory_transfer',
                sourceDocumentId: $transfer->id,
                sourceLineType: 'inventory_transfer_line',
                sourceLineId: $line->id,
                movementRole: TransactionTypeEnum::TransferOut->value,
                idempotencyKey: "trf_{$transfer->id}_{$line->id}_out",
                transactionType: TransactionTypeEnum::TransferOut,
                quantityChange: $negQty,
                unitCost: (string) $wac,
                totalCost: $totalCostOut,
                reference: $transfer->transfer_number,
                notes: $transfer->notes ?? 'Inventory Transfer Posting'
            );

            // Intent In
            $intents[] = new InventoryLedgerPostingIntent(
                propertyId: $transfer->property_id,
                itemId: $line->item_id,
                locationId: $transfer->to_location_id,
                businessDate: $businessDate->business_date,
                occurredAt: $occurredAt,
                sourceDocumentType: 'inventory_transfer',
                sourceDocumentId: $transfer->id,
                sourceLineType: 'inventory_transfer_line',
                sourceLineId: $line->id,
                movementRole: TransactionTypeEnum::TransferIn->value,
                idempotencyKey: "trf_{$transfer->id}_{$line->id}_in",
                transactionType: TransactionTypeEnum::TransferIn,
                quantityChange: $qty,
                unitCost: (string) $wac,
                totalCost: $totalCostIn,
                reference: $transfer->transfer_number,
                notes: $transfer->notes ?? 'Inventory Transfer Posting'
            );
        }

        // Sort intents to guarantee deterministic lock order: itemId ASC -> locationId ASC
        usort($intents, function (InventoryLedgerPostingIntent $a, InventoryLedgerPostingIntent $b) {
            if ($a->itemId !== $b->itemId) {
                return strcmp($a->itemId, $b->itemId);
            }
            return strcmp($a->locationId, $b->locationId);
        });

        DB::transaction(function () use ($transfer, $intents, $businessDate, $occurredAt, $actorId) {
            // Lock context first
            $this->coordinator->lockContext($transfer->property_id, $businessDate->business_date, $occurredAt);

            // Post all intents
            foreach ($intents as $intent) {
                $this->coordinator->post($intent, $actorId);
            }

            // Update transfer header
            $this->transferRepository->update($transfer->id, [
                'status'       => TransferStatusEnum::Completed->value,
                'completed_at' => now(),
                'completed_by' => $actorId,
            ]);
        });

        return $this->transferRepository->find($id);
    }
}
