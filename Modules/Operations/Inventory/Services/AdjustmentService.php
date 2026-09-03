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
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Events\InventoryAdjustmentPosted;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryAdjustmentRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Shared\Exceptions\BusinessLogicException;

class AdjustmentService
{
    public function __construct(
        private InventoryAdjustmentRepository $adjustmentRepository,
        private InventoryPostingControlCoordinator $coordinator,
        private InventoryItemRepository $itemRepository,
        private InventoryStockRepository $stockRepository,
        private CostDeliveryModePort $costDeliveryMode,
        private AuthoritativeInventoryCostPort $authoritativeCost,
        private SynchronousCostValuationPort $synchronousValuation,
    ) {}

    public function create(array $data): InventoryAdjustment
    {
        return DB::transaction(function () use ($data): InventoryAdjustment {
            $this->lockMutationItems((string) $data['property_id'], collect($data['lines'] ?? [])->pluck('item_id')->all());
            $data['status'] = AdjustmentStatusEnum::Draft->value;

            return $this->adjustmentRepository->create($data);
        });
    }

    public function submit(string $id, ?string $userId = null): InventoryAdjustment
    {
        $adjustment = $this->adjustmentRepository->find($id);

        if (! $adjustment->status->canTransitionTo(AdjustmentStatusEnum::Submitted)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition adjustment from {$adjustment->status->label()} to Submitted."],
            ]);
        }

        return DB::transaction(function () use ($adjustment, $id, $userId): InventoryAdjustment {
            $this->lockMutationItems((string) $adjustment->property_id, $adjustment->lines->pluck('item_id')->all());

            return $this->adjustmentRepository->update($id, [
                'status' => AdjustmentStatusEnum::Submitted->value,
                'submitted_at' => now(),
                'submitted_by' => $userId ?? auth()->id(),
            ]);
        });
    }

    public function approve(string $id, ?string $userId = null): InventoryAdjustment
    {
        $adjustment = $this->adjustmentRepository->find($id);

        if (! $adjustment->status->canTransitionTo(AdjustmentStatusEnum::Approved)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition adjustment from {$adjustment->status->label()} to Approved."],
            ]);
        }

        // L-01: at least one line required before approving
        if ($adjustment->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['An adjustment must have at least one line before it can be approved.'],
            ]);
        }

        $businessDate = PropertyBusinessDate::where('property_id', $adjustment->property_id)
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
        $sortedLines = $adjustment->lines->map(function ($line) {
            if (! $line->item_id) {
                throw new BusinessLogicException('Adjustment line is missing item.');
            }

            return $line;
        })->sortBy([
            ['item_id', 'asc'],
            ['id', 'asc'],
        ]);

        $occurredAt = Carbon::parse($adjustment->created_at ?? now());

        $enrolledCount = 0;
        $unenrolledCount = 0;
        foreach ($sortedLines as $line) {
            if ($this->costDeliveryMode->isEnrolled($adjustment->property_id, $line->item_id)) {
                $enrolledCount++;
            } else {
                $unenrolledCount++;
            }
        }

        if ($enrolledCount > 0 && $unenrolledCount > 0) {
            throw new \RuntimeException('Mixed enrolled and unenrolled item authority is forbidden.');
        }

        $isAllEnrolled = ($enrolledCount > 0);

        if ($isAllEnrolled) {
            DB::transaction(function () use ($adjustment, $sortedLines, $businessDate, $occurredAt, $actorId) {
                $sources = [];
                foreach ($sortedLines as $line) {
                    if ((float) $line->quantity_variance == 0.0) {
                        continue;
                    }
                    $sources[] = [
                        'propertyId' => (string) $adjustment->property_id,
                        'itemId' => (string) $line->item_id,
                        'locationId' => (string) $adjustment->location_id,
                        'idempotencyKey' => "adj_{$adjustment->id}_{$line->id}_approve",
                        'sourceDocumentType' => 'inventory_adjustment',
                        'sourceDocumentId' => $adjustment->id,
                        'sourceLineType' => 'inventory_adjustment_line',
                        'sourceLineId' => $line->id,
                        'movementRole' => (float) $line->quantity_variance > 0
                            ? TransactionTypeEnum::AdjustmentIn->value
                            : TransactionTypeEnum::AdjustmentOut->value,
                        'quantityChange' => (string) $line->quantity_variance,
                    ];
                }
                $resolved = $this->coordinator->resolveDocumentDeliveryModes($sources);

                // Run validation for each line before any writes
                foreach ($sortedLines as $line) {
                    $idemKey = "adj_{$adjustment->id}_{$line->id}_approve";
                    // BR-065: staleness check
                    $balance = $this->stockRepository->createOrLockControlled($adjustment->property_id, $line->item_id, $adjustment->location_id);
                    $currentQty = (float) $balance->physical_quantity;

                    if ($currentQty !== (float) $line->quantity_system) {
                        throw ValidationException::withMessages([
                            'staleness' => ["System quantity has changed for item {$line->item_id} since adjustment was created. Expected: {$line->quantity_system}, Actual: {$currentQty}"],
                        ]);
                    }

                    $variance = (float) $line->quantity_variance;
                    if ($variance == 0) {
                        continue;
                    }

                    $item = $this->itemRepository->find($line->item_id);
                    if (! $item) {
                        throw new BusinessLogicException("Item not found: {$line->item_id}");
                    }

                    $resolution = $resolved[$idemKey];
                    $type = $variance > 0 ? TransactionTypeEnum::AdjustmentIn : TransactionTypeEnum::AdjustmentOut;
                    $costToUse = $resolution['existing'] !== null
                        ? (string) $resolution['existing']->unit_cost
                        : ($variance > 0 && $line->unit_cost !== null
                            ? (string) $line->unit_cost
                            : $this->authoritativeCost->resolveUnitCostForPosting($resolution['decision']));
                    $quantity = (string) $line->quantity_variance;
                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $adjustment->property_id,
                        itemId: $line->item_id,
                        locationId: $adjustment->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_adjustment',
                        sourceDocumentId: $adjustment->id,
                        sourceLineType: 'inventory_adjustment_line',
                        sourceLineId: $line->id,
                        movementRole: $type->value,
                        idempotencyKey: $idemKey,
                        transactionType: $type,
                        quantityChange: $quantity,
                        unitCost: $costToUse,
                        totalCost: bcmul($quantity, $costToUse, 4),
                        reference: $adjustment->adjustment_number,
                        notes: 'Inventory Adjustment Posting',
                    );
                    $transaction = $this->coordinator->post($intent, $actorId, $resolution['decision']);
                    if ($resolution['existing'] === null
                        && $resolution['decision']->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
                        $this->synchronousValuation->applyAdjustment($transaction->id);
                    }
                    InventoryAdjustmentPosted::dispatch($transaction);
                }

                $this->adjustmentRepository->update($adjustment->id, [
                    'status' => AdjustmentStatusEnum::Approved->value,
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                ], true);
            });
        } else {
            // Existing legacy behavior unchanged
            DB::transaction(function () use ($adjustment, $sortedLines, $businessDate, $occurredAt, $actorId) {
                // Lock context first
                $this->coordinator->lockContext($adjustment->property_id, $businessDate->business_date, $occurredAt);

                $intents = [];

                foreach ($sortedLines as $line) {
                    // Check idempotency first to allow re-post replay
                    $idemKey = "adj_{$adjustment->id}_{$line->id}_approve";
                    $existingTx = InventoryTransaction::where('property_id', $adjustment->property_id)
                        ->where('idempotency_key', $idemKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existingTx) {
                        continue;
                    }

                    // BR-065: staleness check — lock balance row for this item
                    $balance = $this->stockRepository->createOrLockControlled($adjustment->property_id, $line->item_id, $adjustment->location_id);
                    $currentQty = (float) $balance->physical_quantity;

                    if ($currentQty !== (float) $line->quantity_system) {
                        throw ValidationException::withMessages([
                            'staleness' => ["System quantity has changed for item {$line->item_id} since adjustment was created. Expected: {$line->quantity_system}, Actual: {$currentQty}"],
                        ]);
                    }

                    $variance = (float) $line->quantity_variance;

                    // BR-063: skip zero-variance lines — no stock card written
                    if ($variance == 0) {
                        continue;
                    }

                    $item = $this->itemRepository->find($line->item_id);
                    if (! $item) {
                        throw new BusinessLogicException("Item not found: {$line->item_id}");
                    }

                    // BR-067: cost stamped at approval time using item.weighted_average_cost.
                    // positive adjustment may use unit_cost from the line if provided;
                    // negative adjustment always uses the item's weighted_average_cost.
                    $costToUse = $variance > 0 && $line->unit_cost !== null
                        ? (string) $line->unit_cost
                        : ($item->weighted_average_cost !== null ? (string) $item->weighted_average_cost : null);

                    if ($costToUse === null) {
                        throw ValidationException::withMessages([
                            'cost' => ["Item {$item->name} ({$item->sku}) does not have a valid cost."],
                        ]);
                    }

                    $qtyChange = (string) $variance;
                    $totalCost = bcmul($qtyChange, $costToUse, 4);

                    $type = $variance > 0 ? TransactionTypeEnum::AdjustmentIn : TransactionTypeEnum::AdjustmentOut;

                    $intents[] = new InventoryLedgerPostingIntent(
                        propertyId: $adjustment->property_id,
                        itemId: $line->item_id,
                        locationId: $adjustment->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_adjustment',
                        sourceDocumentId: $adjustment->id,
                        sourceLineType: 'inventory_adjustment_line',
                        sourceLineId: $line->id,
                        movementRole: $type->value,
                        idempotencyKey: "adj_{$adjustment->id}_{$line->id}_approve",
                        transactionType: $type,
                        quantityChange: $qtyChange,
                        unitCost: $costToUse,
                        totalCost: $totalCost,
                        reference: $adjustment->adjustment_number,
                        notes: 'Inventory Adjustment Posting'
                    );
                }

                // Post all intents
                foreach ($intents as $intent) {
                    $transaction = $this->coordinator->post($intent, $actorId);
                    InventoryAdjustmentPosted::dispatch($transaction);
                }

                $this->adjustmentRepository->update($adjustment->id, [
                    'status' => AdjustmentStatusEnum::Approved->value,
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                ]);
            });
        }

        return $this->adjustmentRepository->find($id);
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
