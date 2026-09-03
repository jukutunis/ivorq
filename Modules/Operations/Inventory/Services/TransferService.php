<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\AuthoritativeInventoryCostPort;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransferRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Shared\Exceptions\BusinessLogicException;

class TransferService
{
    public function __construct(
        private InventoryTransferRepository $transferRepository,
        private InventoryPostingControlCoordinator $coordinator,
        private InventoryItemRepository $itemRepository,
        private CostDeliveryModePort $costDeliveryMode,
        private AuthoritativeInventoryCostPort $authoritativeCost,
        private SynchronousCostValuationPort $synchronousValuation,
    ) {}

    public function create(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data): InventoryTransfer {
            $this->lockMutationItems((string) $data['property_id'], collect($data['lines'] ?? [])->pluck('item_id')->all());
            $data['status'] = TransferStatusEnum::Draft->value;

            return $this->transferRepository->create($data);
        });
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

        if (! $businessDate) {
            throw new BusinessLogicException('No open business date found for property.');
        }

        $authId = auth()->id();
        $actorId = $userId ?? $authId;

        if (! $actorId) {
            throw new BusinessLogicException('Authenticated posting operator is required.');
        }

        if ($authId !== null && $userId !== null && $userId !== $authId) {
            throw new BusinessLogicException('The supplied user ID does not match the authenticated posting operator.');
        }

        // Deterministic multi-line order: item_id ASC -> id ASC
        $sortedLines = $transfer->lines->map(function ($line) {
            if (! $line->item_id) {
                throw new BusinessLogicException('Transfer line is missing item.');
            }

            return $line;
        })->sortBy([
            ['item_id', 'asc'],
            ['id', 'asc'],
        ]);

        $enrolledCount = 0;
        $totalLines = $sortedLines->count();
        foreach ($sortedLines as $line) {
            if ($this->costDeliveryMode->isEnrolled($transfer->property_id, (string) $line->item_id)) {
                $enrolledCount++;
            }
        }

        if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
            throw new RuntimeException('Mixed enrollment status detected across transfer lines. Fail closed.');
        }

        $allEnrolled = ($enrolledCount === $totalLines);
        $occurredAt = Carbon::parse($transfer->created_at ?? now());

        if (! $allEnrolled) {
            $intents = [];

            foreach ($sortedLines as $line) {
                $item = $this->itemRepository->find($line->item_id);
                if (! $item) {
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
                    'status' => TransferStatusEnum::Completed->value,
                    'completed_at' => now(),
                    'completed_by' => $actorId,
                ], true);
            });
        } else {
            // All ENROLLED loop
            DB::transaction(function () use ($transfer, $sortedLines, $businessDate, $occurredAt, $actorId) {
                $sources = [];
                foreach ($sortedLines as $line) {
                    $sources[] = [
                        'propertyId' => (string) $transfer->property_id,
                        'itemId' => (string) $line->item_id,
                        'locationId' => (string) $transfer->from_location_id,
                        'idempotencyKey' => "trf_{$transfer->id}_{$line->id}_out",
                        'sourceDocumentType' => 'inventory_transfer',
                        'sourceDocumentId' => $transfer->id,
                        'sourceLineType' => 'inventory_transfer_line',
                        'sourceLineId' => $line->id,
                        'movementRole' => TransactionTypeEnum::TransferOut->value,
                        'quantityChange' => bcmul((string) abs((float) $line->quantity_requested), '-1', 4),
                    ];
                    $sources[] = [
                        'propertyId' => (string) $transfer->property_id,
                        'itemId' => (string) $line->item_id,
                        'locationId' => (string) $transfer->to_location_id,
                        'idempotencyKey' => "trf_{$transfer->id}_{$line->id}_in",
                        'sourceDocumentType' => 'inventory_transfer',
                        'sourceDocumentId' => $transfer->id,
                        'sourceLineType' => 'inventory_transfer_line',
                        'sourceLineId' => $line->id,
                        'movementRole' => TransactionTypeEnum::TransferIn->value,
                        'quantityChange' => (string) abs((float) $line->quantity_requested),
                    ];
                }
                $resolved = $this->coordinator->resolveDocumentDeliveryModes($sources);

                foreach ($sortedLines as $line) {
                    $outKey = "trf_{$transfer->id}_{$line->id}_out";
                    $inKey = "trf_{$transfer->id}_{$line->id}_in";
                    $outResolution = $resolved[$outKey];
                    $inResolution = $resolved[$inKey];
                    if (($outResolution['existing'] === null) !== ($inResolution['existing'] === null)) {
                        throw new RuntimeException('CC_P01F_TRANSFER_PARTIAL_SOURCE_REPLAY');
                    }
                    $unitCost = $outResolution['existing'] !== null
                        ? (string) $outResolution['existing']->unit_cost
                        : $this->authoritativeCost->resolveUnitCostForPosting($outResolution['decision']);
                    $quantity = (string) abs((float) $line->quantity_requested);
                    $negativeQuantity = bcmul($quantity, '-1', 4);
                    $common = [
                        'propertyId' => $transfer->property_id,
                        'itemId' => $line->item_id,
                        'businessDate' => $businessDate->business_date,
                        'occurredAt' => $occurredAt,
                        'sourceDocumentType' => 'inventory_transfer',
                        'sourceDocumentId' => $transfer->id,
                        'sourceLineType' => 'inventory_transfer_line',
                        'sourceLineId' => $line->id,
                        'reference' => $transfer->transfer_number,
                        'notes' => $transfer->notes ?? 'Inventory Transfer Posting',
                    ];
                    $outIntent = new InventoryLedgerPostingIntent(
                        ...$common,
                        locationId: $transfer->from_location_id,
                        movementRole: TransactionTypeEnum::TransferOut->value,
                        idempotencyKey: $outKey,
                        transactionType: TransactionTypeEnum::TransferOut,
                        quantityChange: $negativeQuantity,
                        unitCost: $unitCost,
                        totalCost: bcmul($negativeQuantity, $unitCost, 4),
                    );
                    $inIntent = new InventoryLedgerPostingIntent(
                        ...$common,
                        locationId: $transfer->to_location_id,
                        movementRole: TransactionTypeEnum::TransferIn->value,
                        idempotencyKey: $inKey,
                        transactionType: TransactionTypeEnum::TransferIn,
                        quantityChange: $quantity,
                        unitCost: $unitCost,
                        totalCost: bcmul($quantity, $unitCost, 4),
                    );
                    $outTx = $this->coordinator->post($outIntent, $actorId, $outResolution['decision']);
                    $inTx = $this->coordinator->post($inIntent, $actorId, $inResolution['decision']);
                    if ($outResolution['existing'] === null) {
                        if ($outResolution['decision']->outcome !== $inResolution['decision']->outcome) {
                            throw new RuntimeException('CC_P01F_TRANSFER_DELIVERY_MODE_MISMATCH');
                        }
                        if ($outResolution['decision']->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
                            $this->synchronousValuation->applyTransfer($outTx->id, $inTx->id);
                        }
                    }
                }

                // Update transfer header
                $this->transferRepository->update($transfer->id, [
                    'status' => TransferStatusEnum::Completed->value,
                    'completed_at' => now(),
                    'completed_by' => $actorId,
                ], true);
            });
        }

        return $this->transferRepository->find($id);
    }

    private function lockMutationItems(string $propertyId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        sort($itemIds, SORT_STRING);
        foreach ($itemIds as $itemId) {
            $this->costDeliveryMode->lockForDocumentMutation($propertyId, $itemId);
        }
    }
}
